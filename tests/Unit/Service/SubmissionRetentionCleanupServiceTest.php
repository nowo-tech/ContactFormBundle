<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Nowo\ContactFormBundle\Repository\ContactSubmissionRepository;
use Nowo\ContactFormBundle\Service\SubmissionRetentionCleanupService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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
        $submissionRepository->method('find')->with(5)->willReturn($submission);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('remove')->with($submission);
        $entityManager->expects(self::once())->method('flush');

        $service = new SubmissionRetentionCleanupService(
            $formRepository,
            $submissionRepository,
            $entityManager,
        );

        self::assertSame(1, $service->cleanup(false));
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
        );

        self::assertSame([1, 2], $service->collectExpiredSubmissionIds());
    }
}
