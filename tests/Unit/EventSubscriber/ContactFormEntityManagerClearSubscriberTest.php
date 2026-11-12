<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\EventSubscriber;

use Closure;
use Countable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\ContactFormBundle\EventSubscriber\ContactFormEntityManagerClearSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageSkipEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;

use function count;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * Boundaries of {@see ContactFormEntityManagerClearSubscriber} driven by a real HttpKernel and a real console
 * Application on one event dispatcher, without the services resetter (FrankenPHP worker, kernel reset false).
 */
#[CoversClass(ContactFormEntityManagerClearSubscriber::class)]
final class ContactFormEntityManagerClearSubscriberTest extends TestCase
{
    private int $clears = 0;

    private EventDispatcher $dispatcher;

    private HttpKernel $kernel;

    private Application $application;

    private ContactFormEntityManagerClearSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->dispatcher  = new EventDispatcher();
        $this->kernel      = new HttpKernel($this->dispatcher, new ControllerResolver(), new RequestStack(), new ArgumentResolver());
        $this->application = new Application();
        $this->application->setAutoExit(false);
        $this->application->setCatchExceptions(false);
        $this->application->setDispatcher($this->dispatcher);
    }

    public function testSubscribesToEveryUnitOfWorkBoundary(): void
    {
        $events = ContactFormEntityManagerClearSubscriber::getSubscribedEvents();

        self::assertSame(['onRequest', 4096], $events[KernelEvents::REQUEST]);
        self::assertSame(['onFinishRequest', -4096], $events[KernelEvents::FINISH_REQUEST]);
        self::assertSame([['onTerminateStart', PHP_INT_MAX], ['onTerminate', -4096]], $events[KernelEvents::TERMINATE]);
        self::assertSame([['onMessageStart', PHP_INT_MAX], ['onMessageReceived', 4096]], $events[WorkerMessageReceivedEvent::class]);
        self::assertSame([['onMessageEnd', PHP_INT_MAX], ['onMessageFinished', -4096]], $events[WorkerMessageHandledEvent::class]);
        self::assertSame([['onMessageEnd', PHP_INT_MAX], ['onMessageFinished', -4096]], $events[WorkerMessageFailedEvent::class]);
        self::assertSame(['onWorkerStopped', PHP_INT_MAX], $events[WorkerMessageSkipEvent::class]);
        self::assertSame(['onWorkerStopped', PHP_INT_MAX], $events[WorkerStoppedEvent::class]);
        self::assertSame(['onConsoleCommand', PHP_INT_MAX], $events[ConsoleEvents::COMMAND]);
        self::assertSame(['onConsoleError', PHP_INT_MAX], $events[ConsoleEvents::ERROR]);
        self::assertSame([['onConsoleEnd', PHP_INT_MAX], ['onConsoleTerminate', -4096]], $events[ConsoleEvents::TERMINATE]);
    }

    public function testWithoutABundleManagerNothingIsTouched(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::never())->method(self::anything());

        (new ContactFormEntityManagerClearSubscriber([], [$registry]))->clearState();
        (new ContactFormEntityManagerClearSubscriber())->clearState();
        $this->addToAssertionCount(1);
    }

    public function testClearsAtTheMainRequestStartAndAtTerminateOnly(): void
    {
        $this->subscribe();

        $this->handle(function (): Response {
            $this->kernel->handle($this->request(static fn (): Response => new Response()), HttpKernelInterface::SUB_REQUEST);
            self::assertSame(1, $this->clears, 'A sub-request shares the main request unit of work.');

            return new Response();
        });

        self::assertSame(2, $this->clears);
    }

    /**
     * MT-C-L1: a command run in-process from a controller does not clear the request's unit of work.
     */
    public function testCommandsInsideAMainRequestDoNotClear(): void
    {
        $this->subscribe();
        $this->command('app:probe', static fn (): int => Command::SUCCESS);

        $this->handle(function (): Response {
            $this->runCommand('app:probe');
            $this->runCommand('app:probe');
            self::assertSame(1, $this->clears, 'A command run from a controller must not clear the request.');

            return new Response();
        });
        self::assertSame(2, $this->clears);

        $this->runCommand('app:probe');
        self::assertSame(4, $this->clears, 'After the request a command clears at its start and end again.');
    }

    /**
     * MT-T-I1: an in-process HttpKernel::handle() from a command or a message handler does not clear the unit that runs it.
     */
    public function testRequestsInsideACommandOrAMessageDoNotClear(): void
    {
        $this->subscribe();
        $this->command('app:render', function (): int {
            $this->handle(static fn (): Response => new Response());
            self::assertSame(1, $this->clears, 'A request handled inside a command must not clear it.');

            return Command::SUCCESS;
        });

        $this->runCommand('app:render');
        self::assertSame(2, $this->clears);

        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent($this->envelope(), 'async'));
        self::assertSame(3, $this->clears);
        $this->handle(static fn (): Response => new Response());
        self::assertSame(3, $this->clears, 'A request handled inside a message must not clear it.');
        $this->dispatcher->dispatch(new WorkerMessageHandledEvent($this->envelope(), 'async'));
        self::assertSame(4, $this->clears);
    }

    public function testNestedCommandsClearOnlyAtTheOutermostBoundaries(): void
    {
        $this->subscribe();
        $this->command('app:inner', static fn (): int => Command::SUCCESS);
        $this->command('app:outer', function (): int {
            $this->application->doRun(new ArrayInput(['command' => 'app:inner']), new NullOutput());
            self::assertSame(1, $this->clears, 'A nested command must not clear the outer command.');

            return Command::SUCCESS;
        });

        $this->runCommand('app:outer');
        self::assertSame(2, $this->clears);
    }

    public function testCommandsInsideAMessageDoNotClearAndASkippedMessageEnds(): void
    {
        $this->subscribe();
        $this->command('app:probe', static fn (): int => Command::SUCCESS);

        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent($this->envelope(), 'async'));
        self::assertSame(1, $this->clears);
        $this->runCommand('app:probe');
        self::assertSame(1, $this->clears, 'A command run from a handler must not clear the message.');
        $this->dispatcher->dispatch(new WorkerMessageFailedEvent($this->envelope(), 'async', new RuntimeException('failed')));
        self::assertSame(2, $this->clears);

        // A skipped message (no handled / failed event): the skip event ends the in-flight state.
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent($this->envelope(), 'async'));
        $this->dispatcher->dispatch(new WorkerMessageSkipEvent($this->envelope(), 'async'));
        self::assertSame(3, $this->clears);
        $this->runCommand('app:probe');
        self::assertSame(5, $this->clears);

        // Without a skip event, the stopped worker (or the next received message) ends it.
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent($this->envelope(), 'async'));
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent($this->envelope(), 'async'));
        $this->dispatcher->dispatch(new WorkerStoppedEvent(new Worker([], $this->createStub(MessageBusInterface::class))));
        self::assertSame(7, $this->clears);
        $this->handle(static fn (): Response => new Response());
        self::assertSame(9, $this->clears);
    }

    /**
     * Audit NM (a): a `console.terminate` listener that throws no longer leaves the process "inside a command".
     */
    public function testATerminateListenerThatThrowsDoesNotStopLaterRequestClears(): void
    {
        $this->subscribe();
        $this->dispatcher->addListener(ConsoleEvents::TERMINATE, static function (): never {
            throw new LogicException('terminate listener failed');
        });
        $this->command('app:probe', static fn (): int => Command::SUCCESS);

        $this->runAndCatch('app:probe', 'terminate listener failed');
        self::assertSame(1, $this->clears, 'The listener stopped the end-of-command clear.');

        $this->handle(static fn (): Response => new Response());
        self::assertSame(3, $this->clears, 'The next main request clears at its start and at terminate.');
    }

    /**
     * Audit NM (b): a `console.error` listener that throws (Symfony then skips `console.terminate`) no longer leaves
     * the process "inside a command".
     */
    public function testAnErrorListenerThatThrowsDoesNotStopLaterRequestClears(): void
    {
        $this->subscribe();
        $this->dispatcher->addListener(ConsoleEvents::ERROR, static function (): never {
            throw new LogicException('error listener failed');
        });
        $this->command('app:failing', static function (): int {
            throw new RuntimeException('command failed');
        });

        $this->runAndCatch('app:failing', 'error listener failed');
        self::assertSame(1, $this->clears);

        $this->handle(static fn (): Response => new Response());
        self::assertSame(3, $this->clears, 'The next main request clears at its start and at terminate.');
    }

    /**
     * A failing command whose error is handled still clears once at its end (`console.error` then `console.terminate`).
     */
    public function testAFailedOutermostCommandClearsAtItsEnd(): void
    {
        $this->subscribe();
        $this->command('app:failing', static function (): int {
            throw new RuntimeException('command failed');
        });

        $this->runAndCatch('app:failing', 'command failed');
        self::assertSame(2, $this->clears);
    }

    /**
     * Audit NM: when even the bookkeeping listener is skipped (a listener registered before it at PHP_INT_MAX throws),
     * the end of the enclosing request or message restores the console depth.
     */
    public function testTheEnclosingRequestAndMessageHealASkippedBookkeeping(): void
    {
        $this->dispatcher->addListener(ConsoleEvents::ERROR, static function (): never {
            throw new LogicException('error listener failed');
        }, PHP_INT_MAX);
        $this->subscribe();
        $this->command('app:failing', static function (): int {
            throw new RuntimeException('command failed');
        });

        $this->handle(function (): Response {
            $this->runAndCatch('app:failing', 'error listener failed');

            return new Response();
        });
        self::assertSame(2, $this->clears, 'The request end restored the depth, so terminate cleared.');
        $this->handle(static fn (): Response => new Response());
        self::assertSame(4, $this->clears);

        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent($this->envelope(), 'async'));
        $this->runAndCatch('app:failing', 'error listener failed');
        $this->dispatcher->dispatch(new WorkerMessageHandledEvent($this->envelope(), 'async'));
        self::assertSame(6, $this->clears);
        $this->handle(static fn (): Response => new Response());
        self::assertSame(8, $this->clears, 'The message end restored the depth.');
    }

    /**
     * Audit NM (c): an unknown nested command (`console.error` without a command) or a stray terminate never ends the
     * outer unit.
     */
    public function testAnUnknownNestedCommandDoesNotDiscardTheOuterUnit(): void
    {
        $this->subscribe();
        $this->command('app:outer', function (): int {
            $input = new ArrayInput(['command' => 'app:missing']);
            $input->setInteractive(false);
            try {
                $this->application->doRun($input, new NullOutput());
            } catch (CommandNotFoundException) {
                // Command "app:missing" is not defined.
            }
            $this->dispatcher->dispatch(new ConsoleTerminateEvent(new Command('app:stray'), new ArrayInput([]), new NullOutput(), 0), ConsoleEvents::TERMINATE);
            $this->handle(static fn (): Response => new Response());
            self::assertSame(1, $this->clears, 'The outer unit is still running.');

            return Command::SUCCESS;
        });

        $this->runCommand('app:outer');
        self::assertSame(2, $this->clears);
    }

    /**
     * Audit NM (d): a `console.command` listener above 4096 that stops the propagation in a nested command no longer
     * skips the nested start, so the nested end does not clear the outer unit mid-run.
     */
    public function testAStoppedNestedCommandStartDoesNotClearTheOuterUnit(): void
    {
        $this->subscribe();
        $this->dispatcher->addListener(ConsoleEvents::COMMAND, static function (ConsoleCommandEvent $event): void {
            if ($event->getCommand()?->getName() === 'app:inner') {
                $event->stopPropagation();
            }
        }, 5000);
        $this->command('app:inner', static fn (): int => Command::SUCCESS);
        $this->command('app:outer', function (): int {
            $this->application->doRun(new ArrayInput(['command' => 'app:inner']), new NullOutput());
            self::assertSame(1, $this->clears, 'The nested end must not clear the outer unit.');

            return Command::SUCCESS;
        });

        $this->runCommand('app:outer');
        self::assertSame(2, $this->clears);
    }

    /**
     * Audit NL2-v: a command that runs itself in-process (same Command object, new input) whose inner run fails with a
     * throwing error listener keeps the outer run's unit of work, and the outer end still clears.
     */
    public function testASelfRecursiveCommandWithAThrowingErrorListenerKeepsTheOuterRun(): void
    {
        $this->subscribe();
        $throw = static function (): never {
            throw new LogicException('error listener failed');
        };
        $depth = 0;
        $this->command('app:rec', function () use (&$depth, $throw): int {
            ++$depth;
            if ($depth > 1) {
                throw new RuntimeException('inner failed');
            }
            $this->dispatcher->addListener(ConsoleEvents::ERROR, $throw);
            $this->runAndCatch('app:rec', 'error listener failed');
            $this->dispatcher->removeListener(ConsoleEvents::ERROR, $throw);
            self::assertSame(1, $this->clears, 'The inner run must not clear the outer run.');

            return Command::SUCCESS;
        });

        $this->runCommand('app:rec');
        self::assertSame(2, $this->clears, 'The outer end clears.');
        $this->handle(static fn (): Response => new Response());
        self::assertSame(4, $this->clears);
    }

    /**
     * Audit NL2-v: an error listener at PHP_INT_MIN that throws leaves the frame marked; the next unit drops it.
     */
    public function testAnErrorListenerAtTheLowestPriorityThatThrowsHealsAtTheNextUnit(): void
    {
        $this->subscribe();
        $this->dispatcher->addListener(ConsoleEvents::ERROR, static function (): never {
            throw new LogicException('error listener failed');
        }, PHP_INT_MIN);
        $this->command('app:failing', static function (): int {
            throw new RuntimeException('command failed');
        });
        $this->command('app:probe', static fn (): int => Command::SUCCESS);

        $this->runAndCatch('app:failing', 'error listener failed');
        self::assertSame(1, $this->clears);
        $this->runCommand('app:probe');
        self::assertSame(3, $this->clears, 'The next command is the outermost unit again.');

        $this->runAndCatch('app:failing', 'error listener failed');
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent($this->envelope(), 'async'));
        $this->dispatcher->dispatch(new WorkerMessageHandledEvent($this->envelope(), 'async'));
        $this->handle(static fn (): Response => new Response());
        self::assertSame(8, $this->clears, 'The next message and request clear.');
    }

    /**
     * Audit NL2-v: a `WorkerMessageReceivedEvent` listener that throws inside an in-process consume command (no
     * handled / failed / stopped event): the consume frame restores "no message in flight" when it ends.
     */
    public function testAThrowingReceivedListenerInsideAConsumeCommandHeals(): void
    {
        $this->subscribe();
        $this->dispatcher->addListener(WorkerMessageReceivedEvent::class, static function (): never {
            throw new RuntimeException('received listener failed');
        });
        $this->command('app:consume', function (): int {
            $this->dispatcher->dispatch(new WorkerMessageReceivedEvent($this->envelope(), 'async'));

            return Command::SUCCESS;
        });

        $this->runAndCatch('app:consume', 'received listener failed');
        self::assertSame(3, $this->clears, 'Command start, message received, command end.');
        $this->handle(static fn (): Response => new Response());
        self::assertSame(5, $this->clears);
    }

    /**
     * Audit NL2-v: a nested main request (in-process HttpKernel::handle() from a controller) does not end the outer
     * request early, so a later command in the outer controller is still not the outermost unit.
     */
    public function testANestedMainRequestDoesNotEndTheOuterRequest(): void
    {
        $this->subscribe();
        $this->command('app:probe', static fn (): int => Command::SUCCESS);

        $this->handle(function (): Response {
            $inner = $this->request(static fn (): Response => new Response());
            $this->kernel->handle($inner);
            self::assertSame(2, $this->clears, 'Outer start, inner start (no command, no message).');
            $this->runCommand('app:probe');
            self::assertSame(2, $this->clears, 'The command still runs inside the outer request.');

            return new Response();
        });
        self::assertSame(3, $this->clears);
    }

    /**
     * Audit NL2-v: an error listener that runs another command leaves a sane state.
     */
    public function testAnErrorListenerThatRunsACommandLeavesASaneState(): void
    {
        $this->subscribe();
        $runs = 0;
        $this->dispatcher->addListener(ConsoleEvents::ERROR, function () use (&$runs): void {
            if ($runs++ === 0) {
                $this->runCommand('app:notify');
            }
        });
        $this->command('app:notify', static fn (): int => Command::SUCCESS);
        $this->command('app:failing', static function (): int {
            throw new RuntimeException('command failed');
        });

        $this->runAndCatch('app:failing', 'command failed');
        $before = $this->clears;
        $this->handle(static fn (): Response => new Response());
        self::assertSame($before + 2, $this->clears);
    }

    /**
     * Audit NL2-v2: a message handler runs a command that fails with a throwing error listener, the message fails and
     * the worker stops: the next main request still clears (failing frames are dropped before the message state ends).
     */
    public function testAFailingCommandInsideAFailedMessageThenAStoppedWorkerStillLetsRequestsClear(): void
    {
        $this->subscribe();
        $this->dispatcher->addListener(ConsoleEvents::ERROR, static function (): never {
            throw new LogicException('error listener failed');
        });
        $this->command('app:failing', static function (): int {
            throw new RuntimeException('command failed');
        });

        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent($this->envelope(), 'async'));
        $this->runAndCatch('app:failing', 'error listener failed');
        $this->dispatcher->dispatch(new WorkerMessageFailedEvent($this->envelope(), 'async', new RuntimeException('handler failed')));
        self::assertSame(2, $this->clears);
        $this->dispatcher->dispatch(new WorkerStoppedEvent(new Worker([], $this->createStub(MessageBusInterface::class))));
        $this->handle(static fn (): Response => new Response());
        self::assertSame(4, $this->clears, 'The next main request clears at its start and at terminate.');

        // Same without the failed event: the stopped worker drops the failing frame first, then ends the message.
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent($this->envelope(), 'async'));
        $this->runAndCatch('app:failing', 'error listener failed');
        $this->dispatcher->dispatch(new WorkerStoppedEvent(new Worker([], $this->createStub(MessageBusInterface::class))));
        $this->handle(static fn (): Response => new Response());
        self::assertSame(7, $this->clears);
    }

    /**
     * Audit NR-a (P13): a throwing `kernel.exception` listener skips the request's finish and terminate; later requests
     * still clear, the next top-level terminate forgets the stale request, and a later top-level command is outermost.
     */
    public function testAThrowingExceptionListenerLeavesNoStaleRequest(): void
    {
        $this->subscribe();
        $throw = static function (): never {
            throw new LogicException('exception listener failed');
        };
        $this->dispatcher->addListener(KernelEvents::EXCEPTION, $throw);
        $failing = new Request();
        $failing->attributes->set('_controller', static function (): Response {
            throw new RuntimeException('controller failed');
        });
        try {
            $this->kernel->handle($failing);
            self::fail('The request must throw.');
        } catch (LogicException $e) {
            self::assertSame('exception listener failed', $e->getMessage());
        }
        $this->dispatcher->removeListener(KernelEvents::EXCEPTION, $throw);
        self::assertSame(1, $this->clears);

        $this->handle(static fn (): Response => new Response());
        self::assertSame(3, $this->clears);
        self::assertSame(0, $this->requestsInFlight(), 'The top-level terminate forgot the stale request.');
        $this->command('app:probe', static fn (): int => Command::SUCCESS);
        $this->runCommand('app:probe');
        self::assertSame(5, $this->clears, 'A later top-level command is the outermost unit.');
    }

    /**
     * Audit NR-a (P8): `kernel.finish_request` and `kernel.terminate` listeners that throw never leave requests in
     * flight for good (no unbounded storage, later top-level commands still clear).
     */
    public function testThrowingFinishAndTerminateListenersLeaveNoStaleRequest(): void
    {
        $this->subscribe();
        $throw = static function (): never {
            throw new LogicException('listener failed');
        };
        $this->dispatcher->addListener(KernelEvents::FINISH_REQUEST, $throw, 0);
        $this->dispatcher->addListener(KernelEvents::TERMINATE, $throw, 0);
        for ($i = 0; $i < 3; ++$i) {
            try {
                $this->handle(static fn (): Response => new Response());
                self::fail('The request must throw.');
            } catch (LogicException $e) {
                self::assertSame('listener failed', $e->getMessage());
            }
        }
        $this->dispatcher->removeListener(KernelEvents::FINISH_REQUEST, $throw);
        $this->dispatcher->removeListener(KernelEvents::TERMINATE, $throw);

        $before = $this->clears;
        $this->handle(static fn (): Response => new Response());
        self::assertSame($before + 2, $this->clears);
        self::assertSame(0, $this->requestsInFlight());
        $this->command('app:probe', static fn (): int => Command::SUCCESS);
        $this->runCommand('app:probe');
        self::assertSame($before + 4, $this->clears, 'A later top-level command is the outermost unit.');
    }

    /**
     * Audit NR-a (P6): in-process Worker events inside a request whose `WorkerMessageFailedEvent` listener throws
     * before the late clear: the message state already ended, so later requests still clear.
     */
    public function testAThrowingFailedListenerInsideARequestDoesNotStopLaterClears(): void
    {
        $this->subscribe();
        $this->dispatcher->addListener(WorkerMessageFailedEvent::class, static function (): never {
            throw new RuntimeException('retry transport down');
        }, 0);

        $this->handle(function (): Response {
            $this->dispatcher->dispatch(new WorkerMessageReceivedEvent($this->envelope(), 'async'));
            try {
                $this->dispatcher->dispatch(new WorkerMessageFailedEvent($this->envelope(), 'async', new RuntimeException('handler failed')));
                self::fail('The failed listener must throw.');
            } catch (RuntimeException $e) {
                self::assertSame('retry transport down', $e->getMessage());
            }

            return new Response();
        });
        $before = $this->clears;
        $this->handle(static fn (): Response => new Response());
        self::assertSame($before + 2, $this->clears);
    }

    /**
     * Audit NR-a (P7): a `WorkerMessageReceivedEvent` listener that throws for an in-process Worker inside a request (no
     * command): the request end restores the message state it started with, so later requests still clear.
     */
    public function testAThrowingReceivedListenerInsideARequestDoesNotStopLaterClears(): void
    {
        $this->subscribe();
        $this->dispatcher->addListener(WorkerMessageReceivedEvent::class, static function (): never {
            throw new RuntimeException('received listener failed');
        }, 0);

        $this->handle(function (): Response {
            try {
                $this->dispatcher->dispatch(new WorkerMessageReceivedEvent($this->envelope(), 'async'));
                self::fail('The received listener must throw.');
            } catch (RuntimeException $e) {
                self::assertSame('received listener failed', $e->getMessage());
            }

            return new Response();
        });
        $before = $this->clears;
        $this->handle(static fn (): Response => new Response());
        self::assertSame($before + 2, $this->clears);
    }

    public function testConsoleEventsWithoutACommandChangeNothing(): void
    {
        $this->subscribe();

        $this->dispatcher->dispatch(new ConsoleCommandEvent(null, new ArrayInput([]), new NullOutput()), ConsoleEvents::COMMAND);
        $this->dispatcher->dispatch(new ConsoleTerminateEvent(new Command('app:stray'), new ArrayInput([]), new NullOutput(), 0), ConsoleEvents::TERMINATE);
        self::assertSame(0, $this->clears);
        $this->handle(static fn (): Response => new Response());
        self::assertSame(2, $this->clears);
    }

    /**
     * MT-C-M1: an open manager is always cleared, whatever is pending (it is never inspected).
     */
    public function testAnOpenManagerIsAlwaysCleared(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(true);
        $manager->expects(self::never())->method('getUnitOfWork');
        $manager->expects(self::once())->method('clear');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::never())->method('resetManager');

        (new ContactFormEntityManagerClearSubscriber([$manager], [$registry], 'contact_form'))->clearState();
    }

    public function testAClosedManagerIsRecreatedByName(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(false);
        $manager->expects(self::never())->method('clear');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('resetManager')->with('contact_form');

        (new ContactFormEntityManagerClearSubscriber([$manager], [$registry], 'contact_form'))->clearState();
        // Without a name there is nothing the bundle may reset.
        (new ContactFormEntityManagerClearSubscriber([$manager], [$registry]))->clearState();
    }

    private function subscribe(): void
    {
        $this->clears = 0;
        $manager      = $this->createStub(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(true);
        $manager->method('clear')->willReturnCallback(function (): void {
            ++$this->clears;
        });

        $this->subscriber = new ContactFormEntityManagerClearSubscriber([$manager], [], 'contact_form');
        $this->dispatcher->addSubscriber($this->subscriber);
    }

    /**
     * @param Closure(): Response $controller
     */
    private function request(Closure $controller): Request
    {
        $request = new Request();
        $request->attributes->set('_controller', $controller);

        return $request;
    }

    /**
     * A main request through the real HttpKernel, then `kernel.terminate`.
     *
     * @param Closure(): Response $controller
     */
    private function handle(Closure $controller): void
    {
        $request  = $this->request($controller);
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);
    }

    /**
     * @param Closure(): int $code
     */
    private function command(string $name, Closure $code): void
    {
        $this->application->addCommand((new Command($name))->setCode($code));
    }

    private function runCommand(string $name): void
    {
        $this->application->run(new ArrayInput(['command' => $name]), new NullOutput());
    }

    private function runAndCatch(string $name, string $message): void
    {
        try {
            $this->runCommand($name);
            self::fail('The run must throw.');
        } catch (RuntimeException|LogicException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    private function envelope(): Envelope
    {
        return new Envelope(new stdClass());
    }

    private function requestsInFlight(): int
    {
        $requests = (new ReflectionProperty($this->subscriber, 'requests'))->getValue($this->subscriber);
        self::assertInstanceOf(Countable::class, $requests);

        return count($requests);
    }
}
