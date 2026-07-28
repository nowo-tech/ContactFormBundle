<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Command;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ContactFormBundle\Command\CleanupExpiredSubmissionsCommand;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Nowo\ContactFormBundle\Repository\ContactSubmissionRepository;
use Nowo\ContactFormBundle\Service\SubmissionRetentionCleanupService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CleanupExpiredSubmissionsCommand::class)]
final class CleanupExpiredSubmissionsCommandTest extends TestCase
{
    public function testExecuteDeletesExpiredSubmissions(): void
    {
        $cleanupService = $this->createCleanupService(3, false);

        $command = new CleanupExpiredSubmissionsCommand($cleanupService);
        $tester  = new CommandTester($command);

        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('3 expired submission(s) deleted', $tester->getDisplay());
    }

    public function testExecuteDryRunReportsCount(): void
    {
        $cleanupService = $this->createCleanupService(2, true);

        $command = new CleanupExpiredSubmissionsCommand($cleanupService);
        $tester  = new CommandTester($command);

        $tester->execute(['--dry-run' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('2 submission(s) would be deleted', $tester->getDisplay());
    }

    private function createCleanupService(int $count, bool $dryRunExpected): SubmissionRetentionCleanupService
    {
        $form = (new ContactForm())->setRetentionDays(30);

        $formRepository = $this->createMock(ContactFormRepository::class);
        $formRepository->method('findAll')->willReturn([$form]);

        $submissionRepository = $this->createMock(ContactSubmissionRepository::class);
        $submissionRepository
            ->method('findExpiredIdsByForm')
            ->willReturn(array_fill(0, $count, 1));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        if (!$dryRunExpected) {
            $entityManager->expects(self::once())->method('flush');
        } else {
            $entityManager->expects(self::never())->method('flush');
        }

        return new SubmissionRetentionCleanupService(
            $formRepository,
            $submissionRepository,
            $entityManager,
            new MockClock(),
        );
    }
}
