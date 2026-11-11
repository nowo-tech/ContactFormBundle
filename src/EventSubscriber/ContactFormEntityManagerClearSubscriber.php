<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

use function max;

/**
 * Clears the bundle's own entity manager (`doctrine.entity_manager`) at every unit-of-work boundary, so forms and
 * submissions (personal data) loaded by the admin, a command or a message handler never stay managed across requests
 * (REQ-RUNTIME-002 rule 4). A closed manager is recreated at the boundary.
 *
 * Boundaries:
 * - HTTP: the start of every main request (priority 4096) and `kernel.terminate` (priority -4096, after the
 *   application's terminate listeners). Sub-requests (fragments, ESI) share their main request's unit of work;
 * - Messenger: when a message is received (priority 4096) and after it is handled or failed (priority -4096);
 * - console: when the OUTERMOST command starts and terminates, and only while no Messenger message is in flight. A
 *   command run in-process from another command or from a message handler (`Application::doRun()`) does not clear the
 *   outer unit's manager: the nesting depth is counted on `console.command` / `console.terminate`, and the in-flight
 *   flag is set when a message is received and unset when it is handled, failed or the worker stops (same pattern as
 *   BeaconBundle 1.24.0).
 *
 * Rules:
 * - only the manager named by `doctrine.entity_manager` is cleared. Without that option the bundle entities live on
 *   the application's manager, which the bundle never clears (the host clears its own managers at the same
 *   boundaries when it bypasses the services resetter, REQ-RUNTIME-002 rule 7);
 * - a manager with changes waiting to be written (scheduled inserts, updates or deletes) is not cleared: those changes
 *   are kept until they are flushed or until a later boundary finds nothing pending;
 * - the manager and the registry arrive as iterators of IGNORE_ON_UNINITIALIZED_REFERENCE references, so a boundary
 *   only touches a manager the unit of work already built and never builds one itself.
 *
 * The Messenger events are subscribed by name, so symfony/messenger stays optional.
 *
 * @internal registered by the bundle; the constructor is not a public API
 */
final class ContactFormEntityManagerClearSubscriber implements EventSubscriberInterface
{
    /** Messenger event names (class names), subscribed without requiring symfony/messenger. */
    public const MESSENGER_WORKER_MESSAGE_RECEIVED = WorkerMessageReceivedEvent::class;
    public const MESSENGER_WORKER_MESSAGE_HANDLED  = WorkerMessageHandledEvent::class;
    public const MESSENGER_WORKER_MESSAGE_FAILED   = WorkerMessageFailedEvent::class;
    public const MESSENGER_WORKER_STOPPED          = WorkerStoppedEvent::class;

    /** Console event names (ConsoleEvents::COMMAND / TERMINATE). */
    public const CONSOLE_COMMAND   = 'console.command';
    public const CONSOLE_TERMINATE = 'console.terminate';

    /**
     * Console commands currently running in this process (nested in-process runs count). Process-lifetime counter,
     * bounded by the nesting depth; intentionally not reset by `kernel.reset`.
     */
    private int $consoleDepth = 0;

    /** A Messenger message is being handled (between received and handled / failed / worker stopped). */
    private bool $messageInFlight = false;

    /**
     * @param iterable<EntityManagerInterface> $entityManagers the `doctrine.entity_manager` manager (empty without the option)
     * @param iterable<ManagerRegistry> $registries the Doctrine registry, to recreate a closed manager
     * @param string|null $managerName `doctrine.entity_manager`
     */
    public function __construct(
        private readonly iterable $entityManagers = [],
        private readonly iterable $registries = [],
        private readonly ?string $managerName = null,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST                   => ['onRequest', 4096],
            KernelEvents::TERMINATE                 => ['clearState', -4096],
            self::MESSENGER_WORKER_MESSAGE_RECEIVED => ['onMessageReceived', 4096],
            self::MESSENGER_WORKER_MESSAGE_HANDLED  => ['onMessageFinished', -4096],
            self::MESSENGER_WORKER_MESSAGE_FAILED   => ['onMessageFinished', -4096],
            self::MESSENGER_WORKER_STOPPED          => ['onWorkerStopped', -4096],
            self::CONSOLE_COMMAND                   => ['onConsoleCommand', 4096],
            self::CONSOLE_TERMINATE                 => ['onConsoleTerminate', -4096],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->clearState();
    }

    /**
     * Start of a Messenger message: nothing of the previous message or the worker's idle time is kept.
     */
    public function onMessageReceived(): void
    {
        // @igor-ignore - Unit-of-work flag, set and unset at the Messenger boundaries.
        $this->messageInFlight = true;
        $this->clearState();
    }

    /**
     * End of a Messenger message (handled or failed).
     */
    public function onMessageFinished(): void
    {
        // @igor-ignore - Unit-of-work flag, set and unset at the Messenger boundaries.
        $this->messageInFlight = false;
        $this->clearState();
    }

    /**
     * The worker stopped (possibly after a skipped message): no message is in flight any more.
     */
    public function onWorkerStopped(): void
    {
        // @igor-ignore - Unit-of-work flag, set and unset at the Messenger boundaries.
        $this->messageInFlight = false;
    }

    /**
     * Start of a command: clears only when the outermost command starts outside a Messenger message.
     */
    public function onConsoleCommand(): void
    {
        // @igor-ignore - Nesting counter of the running commands (no request data).
        if (++$this->consoleDepth === 1 && !$this->messageInFlight) {
            $this->clearState();
        }
    }

    /**
     * End of a command: clears only when the outermost command ends outside a Messenger message.
     */
    public function onConsoleTerminate(): void
    {
        // @igor-ignore - Nesting counter of the running commands (no request data).
        $this->consoleDepth = max(0, $this->consoleDepth - 1);
        if ($this->consoleDepth === 0 && !$this->messageInFlight) {
            $this->clearState();
        }
    }

    /**
     * Clears the bundle's own manager (recreated when closed; kept when changes wait to be written). Called at every
     * unit-of-work boundary.
     */
    public function clearState(): void
    {
        foreach ($this->entityManagers as $entityManager) {
            if (!$entityManager->isOpen()) {
                $this->recreate();

                continue;
            }

            if (!$this->hasScheduledWork($entityManager)) {
                $entityManager->clear();
            }
        }
    }

    private function recreate(): void
    {
        if ($this->managerName === null) {
            return;
        }

        foreach ($this->registries as $registry) {
            $registry->resetManager($this->managerName);
        }
    }

    /**
     * Whether the unit of work has inserts, updates or deletes waiting to be written (change sets computed).
     */
    private function hasScheduledWork(EntityManagerInterface $entityManager): bool
    {
        $unitOfWork = $entityManager->getUnitOfWork();
        $unitOfWork->computeChangeSets();

        return $unitOfWork->getScheduledEntityInsertions() !== []
            || $unitOfWork->getScheduledEntityUpdates() !== []
            || $unitOfWork->getScheduledEntityDeletions() !== []
            || $unitOfWork->getScheduledCollectionUpdates() !== []
            || $unitOfWork->getScheduledCollectionDeletions() !== [];
    }
}
