<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Command;

use Nowo\ContactFormBundle\Service\SubmissionRetentionCleanupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

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
        private readonly SubmissionRetentionCleanupService $cleanupService,
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

        $count = $this->cleanupService->cleanup($dryRun);

        if ($dryRun) {
            $io->success(sprintf('%d submission(s) would be deleted.', $count));

            return Command::SUCCESS;
        }

        $io->success(sprintf('%d expired submission(s) deleted.', $count));

        return Command::SUCCESS;
    }
}
