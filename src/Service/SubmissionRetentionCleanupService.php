<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Nowo\ContactFormBundle\Repository\ContactSubmissionRepository;
use Psr\Clock\ClockInterface;

use function array_chunk;
use function count;

/**
 * Removes contact submissions that exceeded their form retention period.
 */
final readonly class SubmissionRetentionCleanupService
{
    /** Submissions loaded, removed and flushed per round (bounded unit of work, one SELECT per chunk). */
    public const CHUNK_SIZE = 500;

    public function __construct(
        private ContactFormRepository $formRepository,
        private ContactSubmissionRepository $submissionRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<int> Submission IDs that would be or were removed
     */
    public function collectExpiredSubmissionIds(): array
    {
        $ids = [];

        foreach ($this->formRepository->findAll() as $form) {
            // 0 (or a legacy negative value) = keep forever: never compute a threshold in the future.
            if ($form->getRetentionDays() <= 0) {
                continue;
            }

            $threshold = $this->clock->now()->modify('-' . $form->getRetentionDays() . ' days');
            $ids       = array_merge(
                $ids,
                $this->submissionRepository->findExpiredIdsByForm($form, $threshold),
            );
        }

        return $ids;
    }

    public function cleanup(bool $dryRun = false): int
    {
        $ids = $this->collectExpiredSubmissionIds();

        if ($dryRun || $ids === []) {
            return count($ids);
        }

        // One SELECT per chunk instead of find() per id, and flush + clear per chunk so a large backlog does not
        // accumulate every submission (and its cascaded values) in one unit of work.
        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
            foreach ($this->submissionRepository->findBy(['id' => $chunk]) as $submission) {
                $this->entityManager->remove($submission);
            }
            $this->entityManager->flush();
            $this->entityManager->clear();
        }

        return count($ids);
    }
}
