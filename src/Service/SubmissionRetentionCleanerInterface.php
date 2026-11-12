<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

use Nowo\ContactFormBundle\Event\ContactSubmissionsDeletedEvent;

/**
 * Removes contact submissions that exceeded their form retention period (1.8, REQ-ARCH-001 rule 1): the port behind
 * `nowo:contact-form:cleanup-expired`, for a host that runs the cleanup from its own scheduler or use case.
 *
 * Ids only: each removed chunk is announced by a {@see ContactSubmissionsDeletedEvent}
 * after its flush.
 */
interface SubmissionRetentionCleanerInterface
{
    /**
     * @return list<int> ids of the submissions past their retention period
     */
    public function collectExpiredSubmissionIds(): array;

    /**
     * Removes the expired submissions (none with `$dryRun`) and returns how many there were.
     */
    public function cleanup(bool $dryRun = false): int;
}
