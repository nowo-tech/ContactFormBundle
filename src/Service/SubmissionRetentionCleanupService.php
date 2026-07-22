<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Nowo\ContactFormBundle\Repository\ContactSubmissionRepository;

use function count;

/**
 * Removes contact submissions that exceeded their form retention period.
 */
final readonly class SubmissionRetentionCleanupService
{
    public function __construct(
        private ContactFormRepository $formRepository,
        private ContactSubmissionRepository $submissionRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<int> Submission IDs that would be or were removed
     */
    public function collectExpiredSubmissionIds(): array
    {
        $ids = [];

        foreach ($this->formRepository->findAll() as $form) {
            $threshold = new DateTimeImmutable('-' . $form->getRetentionDays() . ' days');
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

        foreach ($ids as $id) {
            $submission = $this->submissionRepository->find($id);

            if ($submission !== null) {
                $this->entityManager->remove($submission);
            }
        }

        $this->entityManager->flush();

        return count($ids);
    }
}
