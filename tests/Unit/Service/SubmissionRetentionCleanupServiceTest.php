<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Nowo\ContactFormBundle\Repository\ContactSubmissionRepository;
use Nowo\ContactFormBundle\Service\ContactFormEntityManagerResolver;
use Nowo\ContactFormBundle\Service\SubmissionRetentionCleanupService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

use function count;

#[CoversClass(SubmissionRetentionCleanupService::class)]
final class SubmissionRetentionCleanupServiceTest extends TestCase
{
    public function testCleanupDryRunReturnsCountWithoutRemoving(): void
    {
        $form = (new ContactForm())->setRetentionDays(30);

        $formRepository = $this->createMock(ContactFormRepository::class);
        $formRepository->method('findAll')->willReturn([$form]);

        $submissionRepository = $this->createMock(ContactSubmissionRepository::class);
        $submissionRepository
            ->method('findExpiredIdsByForm')
            ->willReturn([1, 2]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $service = new SubmissionRetentionCleanupService(
            $formRepository,
            $submissionRepository,
            $entityManager,
            new MockClock(),
        );

        self::assertSame(2, $service->cleanup(true));
    }

    public function testCleanupRemovesExpiredSubmissions(): void
    {
        $form       = (new ContactForm())->setRetentionDays(30);
        $submission = new ContactSubmission();

        $formRepository = $this->createMock(ContactFormRepository::class);
        $formRepository->method('findAll')->willReturn([$form]);

        $submissionRepository = $this->createMock(ContactSubmissionRepository::class);
        $submissionRepository->method('findExpiredIdsByForm')->willReturn([5]);
        $submissionRepository->expects(self::once())->method('findBy')->with(['id' => [5]])->willReturn([$submission]);
        $submissionRepository->expects(self::never())->method('find');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('remove')->with($submission);
        $entityManager->expects(self::once())->method('flush');
        $entityManager->expects(self::never())->method('clear');

        $service = new SubmissionRetentionCleanupService(
            $formRepository,
            $submissionRepository,
            $entityManager,
            new MockClock(),
        );

        self::assertSame(1, $service->cleanup(false));
    }

    /**
     * 1.11 (REQ-RUNTIME-003): a failed chunk (a lock conflict) recovers the manager it closed and is rethrown, never
     * swallowed; no event is dispatched for it.
     */
    public function testAFailedChunkResetsTheClosedManagerAndIsRethrown(): void
    {
        $formRepository = $this->createStub(ContactFormRepository::class);
        $formRepository->method('findAll')->willReturn([(new ContactForm())->setRetentionDays(30)]);
        $submissionRepository = $this->createStub(ContactSubmissionRepository::class);
        $submissionRepository->method('findExpiredIdsByForm')->willReturn([5]);
        $submissionRepository->method('findBy')->willReturn([new ContactSubmission()]);

        $failure       = new RuntimeException('Lock wait timeout exceeded');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturnOnConsecutiveCalls(true, false);
        $entityManager->expects(self::once())->method('flush')->willThrowException($failure);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->with('contact_form')->willReturn($entityManager);
        $registry->expects(self::once())->method('resetManager')->with('contact_form')->willReturn($this->createStub(EntityManagerInterface::class));

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $service = new SubmissionRetentionCleanupService($formRepository, $submissionRepository, $entityManager, new MockClock(), new ContactFormEntityManagerResolver($registry, 'contact_form'), $dispatcher);

        $this->expectExceptionObject($failure);
        $service->cleanup();
    }

    public function testCleanupLoadsAndFlushesExpiredSubmissionsInChunks(): void
    {
        $ids            = range(1, SubmissionRetentionCleanupService::CHUNK_SIZE + 2);
        $formRepository = $this->createMock(ContactFormRepository::class);
        $formRepository->method('findAll')->willReturn([(new ContactForm())->setRetentionDays(30)]);

        $chunks               = [];
        $submissionRepository = $this->createMock(ContactSubmissionRepository::class);
        $submissionRepository->method('findExpiredIdsByForm')->willReturn($ids);
        $submissionRepository->expects(self::exactly(2))->method('findBy')->willReturnCallback(static function (array $criteria) use (&$chunks): array {
            $chunks[] = count($criteria['id']);

            return [new ContactSubmission()];
        });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('remove');
        $entityManager->expects(self::exactly(2))->method('flush');
        $entityManager->expects(self::never())->method('clear');

        $service = new SubmissionRetentionCleanupService($formRepository, $submissionRepository, $entityManager, new MockClock());

        self::assertSame(count($ids), $service->cleanup(false));
        self::assertSame([SubmissionRetentionCleanupService::CHUNK_SIZE, 2], $chunks);
    }

    public function testCollectExpiredSubmissionIds(): void
    {
        $form = (new ContactForm())->setRetentionDays(10);

        $formRepository = $this->createMock(ContactFormRepository::class);
        $formRepository->method('findAll')->willReturn([$form]);

        $submissionRepository = $this->createMock(ContactSubmissionRepository::class);
        $submissionRepository
            ->method('findExpiredIdsByForm')
            ->willReturn([1, 2]);

        $service = new SubmissionRetentionCleanupService(
            $formRepository,
            $submissionRepository,
            $this->createMock(EntityManagerInterface::class),
            new MockClock(),
        );

        self::assertSame([1, 2], $service->collectExpiredSubmissionIds());
    }

    /**
     * L3: 0 keeps submissions forever and a legacy negative value never yields a future threshold (delete-all).
     */
    public function testZeroOrNegativeRetentionKeepsEverything(): void
    {
        $formRepository = $this->createStub(ContactFormRepository::class);
        $formRepository->method('findAll')->willReturn([(new ContactForm())->setRetentionDays(0), (new ContactForm())->setRetentionDays(-5)]);

        $submissionRepository = $this->createMock(ContactSubmissionRepository::class);
        $submissionRepository->expects(self::never())->method('findExpiredIdsByForm');

        $service = new SubmissionRetentionCleanupService($formRepository, $submissionRepository, $this->createStub(EntityManagerInterface::class), new MockClock());

        self::assertSame([], $service->collectExpiredSubmissionIds());
    }
}
