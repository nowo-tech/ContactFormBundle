<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ContactFormBundle\EventSubscriber\ContactFormEntityManagerClearSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

#[CoversClass(ContactFormEntityManagerClearSubscriber::class)]
final class ContactFormEntityManagerClearSubscriberTest extends TestCase
{
    private int $clears = 0;

    public function testSubscribesToEveryUnitOfWorkBoundary(): void
    {
        $events = ContactFormEntityManagerClearSubscriber::getSubscribedEvents();

        self::assertSame(['onRequest', 4096], $events[KernelEvents::REQUEST]);
        self::assertSame(['clearState', -4096], $events[KernelEvents::TERMINATE]);
        self::assertSame(['onMessageReceived', 4096], $events[WorkerMessageReceivedEvent::class]);
        self::assertSame(['onMessageFinished', -4096], $events[WorkerMessageHandledEvent::class]);
        self::assertSame(['onMessageFinished', -4096], $events[WorkerMessageFailedEvent::class]);
        self::assertSame(['onWorkerStopped', -4096], $events[WorkerStoppedEvent::class]);
        self::assertSame(['onConsoleCommand', 4096], $events[ConsoleEvents::COMMAND]);
        self::assertSame(['onConsoleTerminate', -4096], $events[ConsoleEvents::TERMINATE]);
    }

    public function testWithoutABundleManagerNothingIsTouched(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::never())->method(self::anything());

        (new ContactFormEntityManagerClearSubscriber([], [$registry]))->clearState();
        (new ContactFormEntityManagerClearSubscriber())->clearState();
        $this->addToAssertionCount(1);
    }

    public function testClearsOnMainRequestOnly(): void
    {
        $subscriber = $this->subscriber();
        $kernel     = $this->createStub(HttpKernelInterface::class);

        $subscriber->onRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::SUB_REQUEST));
        self::assertSame(0, $this->clears);

        $subscriber->onRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST));
        self::assertSame(1, $this->clears);

        $subscriber->clearState();
        self::assertSame(2, $this->clears);
    }

    public function testNestedCommandsClearOnlyAtTheOutermostBoundaries(): void
    {
        $subscriber = $this->subscriber();

        $subscriber->onConsoleCommand();
        self::assertSame(1, $this->clears);
        $subscriber->onConsoleCommand();
        $subscriber->onConsoleTerminate();
        self::assertSame(1, $this->clears, 'A nested command must not clear the outer command.');
        $subscriber->onConsoleTerminate();
        self::assertSame(2, $this->clears);

        // A stray terminate never drives the depth below zero.
        $subscriber->onConsoleTerminate();
        self::assertSame(3, $this->clears);
        $subscriber->onConsoleCommand();
        self::assertSame(4, $this->clears);
    }

    public function testCommandsInsideAMessageDoNotClear(): void
    {
        $subscriber = $this->subscriber();

        $subscriber->onMessageReceived();
        self::assertSame(1, $this->clears);
        $subscriber->onConsoleCommand();
        $subscriber->onConsoleTerminate();
        self::assertSame(1, $this->clears, 'A command run from a handler must not clear the message.');
        $subscriber->onMessageFinished();
        self::assertSame(2, $this->clears);

        // A skipped message (no handled / failed event): the stopped worker ends the in-flight state.
        $subscriber->onMessageReceived();
        $subscriber->onWorkerStopped();
        self::assertSame(3, $this->clears);
        $subscriber->onConsoleCommand();
        self::assertSame(4, $this->clears);
    }

    /**
     * @param array<string, list<object>> $scheduled
     */
    #[DataProvider('pendingWork')]
    public function testAManagerWithPendingChangesIsNotCleared(array $scheduled): void
    {
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->expects(self::once())->method('computeChangeSets');
        foreach (['getScheduledEntityInsertions', 'getScheduledEntityUpdates', 'getScheduledEntityDeletions', 'getScheduledCollectionUpdates', 'getScheduledCollectionDeletions'] as $method) {
            $unitOfWork->method($method)->willReturn($scheduled[$method] ?? []);
        }
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(true);
        $manager->method('getUnitOfWork')->willReturn($unitOfWork);
        $manager->expects(self::never())->method('clear');

        (new ContactFormEntityManagerClearSubscriber([$manager], [], 'contact_form'))->clearState();
    }

    /**
     * @return iterable<string, array{array<string, list<object>>}>
     */
    public static function pendingWork(): iterable
    {
        yield 'insert' => [['getScheduledEntityInsertions' => [new stdClass()]]];
        yield 'update' => [['getScheduledEntityUpdates' => [new stdClass()]]];
        yield 'delete' => [['getScheduledEntityDeletions' => [new stdClass()]]];
        yield 'collection update' => [['getScheduledCollectionUpdates' => [new stdClass()]]];
        yield 'collection delete' => [['getScheduledCollectionDeletions' => [new stdClass()]]];
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

    private function subscriber(): ContactFormEntityManagerClearSubscriber
    {
        $this->clears = 0;
        $unitOfWork   = $this->createStub(UnitOfWork::class);
        $unitOfWork->method('getScheduledEntityInsertions')->willReturn([]);
        $unitOfWork->method('getScheduledEntityUpdates')->willReturn([]);
        $unitOfWork->method('getScheduledEntityDeletions')->willReturn([]);
        $unitOfWork->method('getScheduledCollectionUpdates')->willReturn([]);
        $unitOfWork->method('getScheduledCollectionDeletions')->willReturn([]);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(true);
        $manager->method('getUnitOfWork')->willReturn($unitOfWork);
        $manager->method('clear')->willReturnCallback(function (): void {
            ++$this->clears;
        });

        return new ContactFormEntityManagerClearSubscriber([$manager], [], 'contact_form');
    }
}
