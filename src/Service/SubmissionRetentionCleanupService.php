<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ContactFormBundle\Event\ContactSubmissionsDeletedEvent;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Nowo\ContactFormBundle\Repository\ContactSubmissionRepository;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

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
        private ?ContactFormEntityManagerResolver $entityManagerResolver = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
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

        // One SELECT per chunk instead of find() per id, and one flush per chunk so a large backlog does not
        // accumulate every submission (and its cascaded values) in one unit of work: removed entities leave the
        // identity map with the flush. 1.7: the manager is never clear()ed here (it may be the host's default manager,
        // holding the host's own entities); the bundle's manager is resolved by class / doctrine.entity_manager. 1.7.1:
        // a doctrine.entity_manager manager is cleared when the command ends (ContactFormEntityManagerClearSubscriber).
        $entityManager = $this->entityManagerResolver?->get() ?? $this->entityManager;
        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
            $removed = [];
            foreach ($this->submissionRepository->findBy(['id' => $chunk]) as $submission) {
                $removed[] = (int) $submission->getId();
                $entityManager->remove($submission);
            }
            $entityManager->flush();
            $this->eventDispatcher?->dispatch(new ContactSubmissionsDeletedEvent($removed, ContactSubmissionsDeletedEvent::REASON_RETENTION));
        }

        return count($ids);
    }
}
