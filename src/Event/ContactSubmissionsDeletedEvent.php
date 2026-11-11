<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after contact submissions were deleted and the deletion was flushed (1.7, architecture bar rules 4 and 7):
 * from the admin (`reason` = {@see self::REASON_ADMIN}) or by the retention cleanup ({@see self::REASON_RETENTION}, one
 * event per flushed chunk). Ids only: the rows and their values are gone.
 */
final class ContactSubmissionsDeletedEvent extends Event
{
    public const REASON_ADMIN     = 'admin';
    public const REASON_RETENTION = 'retention';

    /**
     * @param list<int> $submissionIds
     */
    public function __construct(
        private readonly array $submissionIds,
        private readonly string $reason,
    ) {
    }

    /**
     * @return list<int>
     */
    public function getSubmissionIds(): array
    {
        return $this->submissionIds;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
