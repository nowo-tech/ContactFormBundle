<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

use function max;

/**
 * Clears the bundle's own entity manager (`doctrine.entity_manager`, when it is not the host's default manager) at
 * every OUTERMOST unit-of-work boundary, so forms and submissions (personal data) loaded by the admin, a command or a
 * message handler never stay managed across requests, and an unflushed change of an abandoned unit (an invalid admin
 * POST, translations added on a GET) is never written by a later, unrelated flush such as a visitor's submission
 * (REQ-RUNTIME-002 rule 4; same policy as ApiStudioBundle 1.12.1 `EntityManagerBoundarySubscriber`).
 *
 * Boundaries:
 * - HTTP: the main `kernel.request` (priority 4096) and `kernel.terminate` (priority -4096), only outside a console
 *   command and a Messenger message (an in-process `HttpKernel::handle()` from a command or a handler must not clear
 *   the unit that runs it). Sub-requests (fragments, ESI) share their main request's unit of work;
 * - Messenger: when a message is received (priority 4096) and after it is handled or failed (priority -4096);
 * - console: when the OUTERMOST command starts and terminates, only outside a Messenger message and outside a main
 *   request. A command run in-process from another command, a message handler or a controller
 *   (`Application::doRun()`) does not clear the outer unit's manager.
 *
 * State: a console depth counter; a message flag set on received and unset on handled / failed / worker stopped; a
 * request flag set on the main `kernel.request` and unset on its `kernel.finish_request`.
 *
 * Rules:
 * - an open manager is always cleared at an outermost boundary: whatever the finished (or abandoned) unit left
 *   unflushed is discarded, never written later;
 * - a closed manager (a failed write) is recreated by name through the registry;
 * - only the manager named by `doctrine.entity_manager` is touched, and only when it is not the host's default manager
 *   (the compiler pass wires nothing otherwise). Without that option the bundle entities live on the application's
 *   manager, which the bundle never clears (REQ-RUNTIME-002 rule 7: the host clears its own managers);
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

    /** A main request is being handled (between its `kernel.request` and its `kernel.finish_request`). */
    private bool $requestInFlight = false;

    /**
     * @param iterable<EntityManagerInterface> $entityManagers the bundle-owned manager (empty without one)
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
            KernelEvents::FINISH_REQUEST            => ['onFinishRequest', -4096],
            KernelEvents::TERMINATE                 => ['onTerminate', -4096],
            self::MESSENGER_WORKER_MESSAGE_RECEIVED => ['onMessageReceived', 4096],
            self::MESSENGER_WORKER_MESSAGE_HANDLED  => ['onMessageFinished', -4096],
            self::MESSENGER_WORKER_MESSAGE_FAILED   => ['onMessageFinished', -4096],
            self::MESSENGER_WORKER_STOPPED          => ['onWorkerStopped', -4096],
            self::CONSOLE_COMMAND                   => ['onConsoleCommand', 4096],
            self::CONSOLE_TERMINATE                 => ['onConsoleTerminate', -4096],
        ];
    }

    /**
     * Start of a main request: clears only outside a command and a message (an in-process HttpKernel::handle()).
     */
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $outermost = $this->consoleDepth === 0 && !$this->messageInFlight;
        // @igor-ignore - Unit-of-work flag, set and unset at the main request boundaries.
        $this->requestInFlight = true;
        if ($outermost) {
            $this->clearState();
        }
    }

    public function onFinishRequest(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            // @igor-ignore - Unit-of-work flag, set and unset at the main request boundaries.
            $this->requestInFlight = false;
        }
    }

    /**
     * End of a request: clears only outside a command and a message (an in-process HttpKernel::handle()).
     */
    public function onTerminate(): void
    {
        if ($this->consoleDepth === 0 && !$this->messageInFlight) {
            $this->clearState();
        }
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
     * Start of a command: clears only for the outermost command outside a message and a main request.
     */
    public function onConsoleCommand(): void
    {
        if ($this->isOutermostUnit()) {
            $this->clearState();
        }
        // @igor-ignore - Nesting counter of the running commands (no request data).
        ++$this->consoleDepth;
    }

    /**
     * End of a command: clears only for the outermost command outside a message and a main request.
     */
    public function onConsoleTerminate(): void
    {
        // @igor-ignore - Nesting counter of the running commands (no request data).
        $this->consoleDepth = max(0, $this->consoleDepth - 1);
        if ($this->isOutermostUnit()) {
            $this->clearState();
        }
    }

    /**
     * Recreates the bundle's manager when a failed write closed it, clears it when it is open (unflushed changes of
     * the finished unit are discarded); nothing when it was never built.
     */
    public function clearState(): void
    {
        foreach ($this->entityManagers as $entityManager) {
            if ($entityManager->isOpen()) {
                $entityManager->clear();

                continue;
            }

            if ($this->managerName !== null) {
                foreach ($this->registries as $registry) {
                    $registry->resetManager($this->managerName);
                }
            }
        }
    }

    private function isOutermostUnit(): bool
    {
        return $this->consoleDepth === 0 && !$this->messageInFlight && !$this->requestInFlight;
    }
}
