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
use Symfony\Component\Console\Input\InputInterface;
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
use WeakMap;

use function array_splice;
use function count;

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
 * Console bookkeeping (1.8.1, audits NM and NL2-v), at the extreme priorities so no other listener can skip it:
 * - every console run is a FRAME on a stack, keyed by its Command object AND its input (a command that runs itself
 *   in-process has a new input per run). `console.command` (priority PHP_INT_MAX) pushes it with the outer state it
 *   started in (Messenger message in flight, main requests in flight) and whether it is the outermost unit (nothing
 *   else running). `console.terminate` (priority PHP_INT_MAX) removes ITS frame and every frame above it (nested runs
 *   that never terminated) and restores the state saved in it; a terminate without a frame removes nothing (never a
 *   blind decrement). The clear at the end of the outermost command still runs at `console.terminate` -4096, for the
 *   verdict computed by the early listener for that very event;
 * - `console.error` (priority PHP_INT_MAX) marks the frame as failing and nothing unmarks it: when an error listener
 *   throws, Symfony never dispatches the command's `console.terminate`, so a frame still marked at the next boundary
 *   (main request start or end, Messenger message received / finished, command, worker stop) is dropped (with the
 *   frames above it) and its saved state restored. Every handler drops such frames FIRST and only then writes its own
 *   state. `console.error` without a command (unknown command) changes nothing;
 * - the end of a main request (`kernel.finish_request` / `kernel.terminate`) and of a Messenger message (handled,
 *   failed, worker stopped) also restores the frames to what they were at its start;
 * - main requests in flight are counted per `Request` object, so a nested main request (an in-process
 *   `HttpKernel::handle()` from a controller) never ends the outer one early.
 * So a listener that throws or stops the propagation can no longer leave the process "inside a command" for good
 * (which stopped every later HTTP clear), nor make a nested command clear the outer unit mid-run. The bundle has no
 * `kernel.exception` boundary: `kernel.terminate` is the HTTP end boundary.
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

    /** A skipped message (only dispatched by `messenger:failed:retry`); Messenger 7.4+, by name. */
    public const MESSENGER_WORKER_MESSAGE_SKIPPED = WorkerMessageSkipEvent::class;

    /** Console event names (ConsoleEvents::COMMAND / ERROR / TERMINATE). */
    public const CONSOLE_COMMAND   = 'console.command';
    public const CONSOLE_ERROR     = 'console.error';
    public const CONSOLE_TERMINATE = 'console.terminate';

    /**
     * Running console commands, outermost first. Each frame keeps the outer state it started in (`message`, `requests`),
     * restored when the frame is removed, and whether it is the outermost unit.
     *
     * @var list<array{command: Command, input: InputInterface, outermost: bool, failing: bool, message: list<mixed>|null, requests: SplObjectStorage<Request, list<mixed>>}>
     */
    private array $frames = [];

    /**
     * Main requests in flight (between their `kernel.request` and their `kernel.finish_request` / `kernel.terminate`),
     * in start order, with the frames at their start.
     *
     * @var SplObjectStorage<Request, list<mixed>>
     */
    private SplObjectStorage $requests;

    /**
     * Frames when the in-flight Messenger message was received; null outside a message.
     *
     * @var list<mixed>|null
     */
    private ?array $messageFrames = null;

    /**
     * Outermost verdict of each `console.terminate` event, computed by the early listener for the late clear.
     *
     * @var WeakMap<ConsoleTerminateEvent, bool>
     */
    private WeakMap $verdicts;

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
        $this->requests = new SplObjectStorage();
        $this->verdicts = new WeakMap();
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
            self::CONSOLE_ERROR                     => ['onConsoleError', PHP_INT_MAX],
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

        $this->dropFailingFrames();
        $outermost = $this->isOutsideMessageAndCommand();
        // @igor-ignore - Unit-of-work stack, pushed and popped at the main request boundaries (no request data kept).
        $this->requests[$event->getRequest()] = $this->frames;
        if ($outermost) {
            $this->clearState();
        }
    }

    public function onFinishRequest(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->dropFailingFrames();
            $this->endRequest($event->getRequest());
        }
    }

    /**
     * End of a request: clears only outside a command and a message (an in-process HttpKernel::handle()).
     */
    public function onTerminate(TerminateEvent $event): void
    {
        $this->dropFailingFrames();
        $this->endRequest($event->getRequest());
        if ($this->isOutsideMessageAndCommand()) {
            $this->clearState();
        }
    }

    /**
     * Start of a Messenger message: nothing of the previous message or the worker's idle time is kept.
     */
    public function onMessageReceived(): void
    {
        $this->dropFailingFrames();
        // A previous message that never finished ends here.
        $this->endMessage();
        // @igor-ignore - Unit-of-work state, set and unset at the Messenger boundaries (no message data kept).
        $this->messageFrames = $this->frames;
        $this->clearState();
    }

    /**
     * End of a Messenger message (handled or failed).
     */
    public function onMessageFinished(): void
    {
        $this->dropFailingFrames();
        $this->endMessage();
        $this->clearState();
    }

    /**
     * The worker stopped, or a message was skipped (`WorkerMessageSkipEvent`, only dispatched by
     * `messenger:failed:retry`): no message is in flight any more.
     */
    public function onWorkerStopped(): void
    {
        // Failing frames first (they restore the state they saved), then this handler's own state.
        $this->dropFailingFrames();
        $this->endMessage();
    }

    /**
     * Start of a command (priority PHP_INT_MAX): pushes its frame, and clears for the outermost command (outside a
     * message, a main request and another command).
     */
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();
        if (!$command instanceof Command) {
            return;
        }

        $this->dropFailingFrames();
        $outermost = $this->isOutsideMessageAndCommand() && $this->requests->count() === 0;
        // @igor-ignore - Stack of the running commands (no request data), popped by their own terminate.
        $this->frames[] = [
            'command'   => $command,
            'input'     => $event->getInput(),
            'outermost' => $outermost,
            'failing'   => false,
            'message'   => $this->messageFrames,
            'requests'  => clone $this->requests,
        ];
        if ($outermost) {
            $this->clearState();
        }
    }

    /**
     * A command failed (priority PHP_INT_MAX): marks its frame; a frame still marked when the next unit starts was never
     * terminated (an error listener threw) and is dropped then. Without a command (unknown command) nothing changes.
     */
    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        $index = $this->indexOf($event->getCommand(), $event->getInput());
        if ($index !== null) {
            // @igor-ignore - Failure marker of a running command, removed with its frame.
            $this->frames[$index]['failing'] = true;
        }
    }

    /**
     * End of a command (priority PHP_INT_MAX): removes its frame (and the nested frames above it) and records whether
     * it was the outermost unit; a command without a frame changes nothing.
     */
    public function onConsoleEnd(ConsoleTerminateEvent $event): void
    {
        $index = $this->indexOf($event->getCommand(), $event->getInput());
        if ($index === null) {
            return;
        }

        // @igor-ignore - Per-event verdict; the WeakMap entry dies with the event.
        $this->verdicts[$event] = $this->frames[$index]['outermost'];
        $this->removeFrames($index);
    }

    /**
     * End of a command (priority -4096): clears only when the outermost command ended.
     */
    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        if ($this->verdicts[$event] ?? false) {
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

    private function isOutsideMessageAndCommand(): bool
    {
        return $this->frames === [] && $this->messageFrames === null;
    }

    private function indexOf(?Command $command, InputInterface $input): ?int
    {
        for ($i = count($this->frames) - 1; $i >= 0; --$i) {
            if ($this->frames[$i]['command'] === $command && $this->frames[$i]['input'] === $input) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Removes the frame at $index and the frames above it, and restores the state saved when it started.
     */
    private function removeFrames(int $index): void
    {
        $frame = $this->frames[$index];
        // @igor-ignore - Stack of the running commands (no request data), popped by their own terminate or self-heal.
        array_splice($this->frames, $index);
        // @igor-ignore - Outer state saved by the frame (no request data kept).
        $this->messageFrames = $frame['message'];
        // @igor-ignore - Outer state saved by the frame (no request data kept).
        $this->requests = $frame['requests'];
    }

    /**
     * Drops the lowest frame whose error listeners threw (Symfony never dispatched its terminate), with the frames above.
     */
    private function dropFailingFrames(): void
    {
        foreach ($this->frames as $index => $frame) {
            if ($frame['failing']) {
                $this->removeFrames($index);

                return;
            }
        }
    }

    private function endRequest(Request $request): void
    {
        if (!isset($this->requests[$request])) {
            return;
        }

        $frames = $this->requests[$request];
        $popped = [];
        $found  = false;
        foreach ($this->requests as $started) {
            $found = $found || $started === $request;
            if ($found) {
                $popped[] = $started;
            }
        }
        foreach ($popped as $started) {
            unset($this->requests[$started]);
        }
        // @igor-ignore - Frames restored at the end of the enclosing request (no request data).
        $this->frames = $frames;
    }

    private function endMessage(): void
    {
        if ($this->messageFrames === null) {
            return;
        }

        // @igor-ignore - Frames restored at the end of the enclosing message (no message data).
        $this->frames = $this->messageFrames;
        // @igor-ignore - Unit-of-work state, set and unset at the Messenger boundaries (no message data kept).
        $this->messageFrames = null;
    }
}
