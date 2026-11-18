<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Command;

use Nowo\ContactFormBundle\Service\SubmissionRetentionCleanerInterface;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function sprintf;

/**
 * Deletes contact submissions older than each form's retention_days setting.
 */
#[AsCommand(
    name: 'nowo:contact-form:cleanup-submissions',
    description: 'Remove contact submissions that exceeded GDPR retention periods',
)]
final class CleanupExpiredSubmissionsCommand extends Command
{
    public function __construct(
        private readonly SubmissionRetentionCleanerInterface $cleanupService,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List how many submissions would be deleted without removing them');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $count = $this->cleanupService->cleanup($dryRun);
        } catch (Throwable $exception) {
            // 1.11 (REQ-RUNTIME-003): a lock conflict (deadlock, lock wait timeout, serialization failure) is reported
            // as such; any other failure stays a real error.
            if (!(new TransientFailureClassifier())->isTransient($exception)) {
                throw $exception;
            }

            $io->error('A lock conflict with another write stopped the cleanup. The chunks before it are deleted; the failed chunk is unchanged. Run the command again.');

            return Command::FAILURE;
        }

        if ($dryRun) {
            $io->success(sprintf('%d submission(s) would be deleted.', $count));

            return Command::SUCCESS;
        }

        $io->success(sprintf('%d expired submission(s) deleted.', $count));

        return Command::SUCCESS;
    }
}
