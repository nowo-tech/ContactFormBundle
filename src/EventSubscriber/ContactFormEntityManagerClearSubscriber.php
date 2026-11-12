<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use SplObjectStorage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageSkipEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

use const PHP_INT_MAX;

/**
 * Clears the bundle's own entity manager (`doctrine.entity_manager`, when it is not the host's default manager) at
 * every OUTERMOST unit-of-work boundary, so forms and submissions (personal data) loaded by the admin, a command or a
 * message handler never stay managed across requests, and an unflushed change of an abandoned unit (an invalid admin
 * POST, translations added on a GET) is never written by a later, unrelated flush such as a visitor's submission
 * (REQ-RUNTIME-002 rule 4; same policy as ApiStudioBundle `EntityManagerBoundarySubscriber`).
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
 * Console bookkeeping (1.8.1, audit NM): every started command is recorded with the depth before it and whether it is
 * the outermost unit, at `console.command` priority PHP_INT_MAX. Its record is popped (the depth goes back to the
 * recorded value, records of commands started after it are dropped) at `console.error` and `console.terminate`
 * priority PHP_INT_MAX, before any listener that could throw or stop the propagation. An event for a command that has
 * no record is ignored (never a blind decrement). The clear at the end of the outermost command still runs at
 * `console.terminate` priority -4096. The enclosing unit also heals the depth: it is snapshotted at the main
 * `kernel.request` and at `WorkerMessageReceivedEvent`, and restored at that request's `kernel.finish_request` /
 * `kernel.terminate` and at that message's handled / failed / skipped event or `WorkerStoppedEvent`. So a listener that
 * throws or stops the propagation can no longer leave the process "inside a command" forever (which stopped every
 * later HTTP clear), nor make a nested command clear the outer unit mid-run.
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

    /** A listener skipped the received message (no handled / failed event follows); Messenger 7.4+, by name. */
    public const MESSENGER_WORKER_MESSAGE_SKIPPED = WorkerMessageSkipEvent::class;

    /** Console event names (ConsoleEvents::COMMAND / ERROR / TERMINATE). */
    public const CONSOLE_COMMAND   = 'console.command';
    public const CONSOLE_ERROR     = 'console.error';
    public const CONSOLE_TERMINATE = 'console.terminate';

    /**
     * Console commands currently running in this process (nested in-process runs count). Process-lifetime counter,
     * bounded by the nesting depth and healed at every enclosing unit end; intentionally not reset by `kernel.reset`.
     */
    private int $consoleDepth = 0;

    /**
     * Running commands in start order: depth before the command and whether it is the outermost unit.
     *
     * @var SplObjectStorage<Command, array{0: int, 1: bool}>
     */
    private SplObjectStorage $commands;

    /**
     * Main requests being handled (between their `kernel.request` and their `kernel.finish_request` / `kernel.terminate`),
     * in start order, with the console state at their start.
     *
     * @var SplObjectStorage<Request, array{0: int, 1: SplObjectStorage<Command, array{0: int, 1: bool}>}>
     */
    private SplObjectStorage $requests;

    /**
     * Console state when the in-flight Messenger message was received; null outside a message.
     *
     * @var array{0: int, 1: SplObjectStorage<Command, array{0: int, 1: bool}>}|null
     */
    private ?array $messageState = null;

    /** The outermost command whose end was recorded and whose clear (at `console.terminate` -4096) is due. */
    private ?Command $endedOutermostCommand = null;

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
        $this->commands = new SplObjectStorage();
        $this->requests = new SplObjectStorage();
    }

    /**
     * @return array<string, array{0: string, 1: int}|list<array{0: string, 1: int}>>
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
            self::MESSENGER_WORKER_MESSAGE_SKIPPED  => ['onWorkerStopped', -4096],
            self::MESSENGER_WORKER_STOPPED          => ['onWorkerStopped', -4096],
            self::CONSOLE_COMMAND                   => ['onConsoleCommand', PHP_INT_MAX],
            self::CONSOLE_ERROR                     => ['onConsoleEnd', PHP_INT_MAX],
            self::CONSOLE_TERMINATE                 => [['onConsoleEnd', PHP_INT_MAX], ['onConsoleTerminate', -4096]],
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

        $outermost = $this->consoleDepth === 0 && $this->messageState === null;
        // @igor-ignore - Unit-of-work stack, pushed and popped at the main request boundaries (no request data kept).
        $this->requests[$event->getRequest()] = $this->consoleState();
        if ($outermost) {
            $this->clearState();
        }
    }

    public function onFinishRequest(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->endRequest($event->getRequest());
        }
    }

    /**
     * End of a request: clears only outside a command and a message (an in-process HttpKernel::handle()).
     */
    public function onTerminate(TerminateEvent $event): void
    {
        $this->endRequest($event->getRequest());
        if ($this->consoleDepth === 0 && $this->messageState === null) {
            $this->clearState();
        }
    }

    /**
     * Start of a Messenger message: nothing of the previous message or the worker's idle time is kept.
     */
    public function onMessageReceived(): void
    {
        // A previous message that never finished (skipped without a skip event) ends here.
        $this->endMessage();
        // @igor-ignore - Unit-of-work state, set and unset at the Messenger boundaries (no message data kept).
        $this->messageState = $this->consoleState();
        $this->clearState();
    }

    /**
     * End of a Messenger message (handled or failed).
     */
    public function onMessageFinished(): void
    {
        $this->endMessage();
        $this->clearState();
    }

    /**
     * A message was skipped or the worker stopped: no message is in flight any more.
     */
    public function onWorkerStopped(): void
    {
        $this->endMessage();
    }

    /**
     * Start of a command (priority PHP_INT_MAX): records it, and clears for the outermost command outside a message and
     * a main request.
     */
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();
        if (!$command instanceof Command) {
            return;
        }
        if (isset($this->commands[$command])) {
            // The same command object started again while recorded as running: its earlier end was never seen.
            $this->endCommand($command);
        }
        if ($this->endedOutermostCommand === $command) {
            // @igor-ignore - Pending end of the outermost command (bookkeeping, no request data).
            $this->endedOutermostCommand = null;
        }

        $outermost = $this->consoleDepth === 0 && $this->messageState === null && $this->requests->count() === 0;
        // @igor-ignore - Running-command stack, pushed and popped at the console boundaries (no request data).
        $this->commands[$command] = [$this->consoleDepth, $outermost];
        // @igor-ignore - Nesting counter of the running commands (no request data).
        ++$this->consoleDepth;
        if ($outermost) {
            $this->clearState();
        }
    }

    /**
     * Error or end of a command (priority PHP_INT_MAX): pops its record; a command without a record (unknown, or a
     * `console.error` without a command) changes nothing.
     */
    public function onConsoleEnd(ConsoleErrorEvent|ConsoleTerminateEvent $event): void
    {
        $command = $event->getCommand();
        if ($command instanceof Command) {
            $this->endCommand($command);
        }
    }

    /**
     * End of a command (priority -4096): clears only when the outermost command ended.
     */
    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        if (!$this->endedOutermostCommand instanceof Command || $this->endedOutermostCommand !== $event->getCommand()) {
            return;
        }

        // @igor-ignore - Pending end of the outermost command (bookkeeping, no request data).
        $this->endedOutermostCommand = null;
        $this->clearState();
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

    /**
     * @return array{0: int, 1: SplObjectStorage<Command, array{0: int, 1: bool}>}
     */
    private function consoleState(): array
    {
        return [$this->consoleDepth, clone $this->commands];
    }

    /**
     * @param array{0: int, 1: SplObjectStorage<Command, array{0: int, 1: bool}>} $state
     */
    private function restoreConsoleState(array $state): void
    {
        // @igor-ignore - Console bookkeeping restored at the end of the enclosing unit (no request data).
        [$this->consoleDepth, $this->commands] = $state;
    }

    private function endRequest(Request $request): void
    {
        if (!isset($this->requests[$request])) {
            return;
        }

        $state = $this->requests[$request];
        $this->pop($this->requests, $request);
        $this->restoreConsoleState($state);
    }

    private function endMessage(): void
    {
        if ($this->messageState === null) {
            return;
        }

        $this->restoreConsoleState($this->messageState);
        // @igor-ignore - Unit-of-work state, set and unset at the Messenger boundaries (no message data kept).
        $this->messageState = null;
    }

    private function endCommand(Command $command): void
    {
        if (!isset($this->commands[$command])) {
            return;
        }

        [$depth, $outermost] = $this->commands[$command];
        $this->pop($this->commands, $command);
        // @igor-ignore - Nesting counter of the running commands (no request data).
        $this->consoleDepth = $depth;
        if ($outermost) {
            // @igor-ignore - Pending end of the outermost command (bookkeeping, no request data).
            $this->endedOutermostCommand = $command;
        }
    }

    /**
     * Removes $entry and every entry attached after it (a stack pop down to $entry).
     *
     * @template T of object
     * @template D
     *
     * @param SplObjectStorage<T, D> $stack
     * @param T $entry
     */
    private function pop(SplObjectStorage $stack, object $entry): void
    {
        $popped = [];
        $found  = false;
        foreach ($stack as $object) {
            $found = $found || $object === $entry;
            if ($found) {
                $popped[] = $object;
            }
        }
        foreach ($popped as $object) {
            unset($stack[$object]);
        }
    }
}
