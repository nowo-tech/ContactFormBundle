<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

use DateTimeImmutable;
use Nowo\ContactFormBundle\Client\ClientReference;
use Nowo\ContactFormBundle\Event\ContactSubmissionCreatedEvent;
use Nowo\ContactFormBundle\Exception\ContactFormNotFoundException;

/**
 * Request-free write port of the contact form bounded context (1.7, architecture bar rule 1): records a submission
 * from plain values — from a host command handler, an API controller or a message handler — without a Symfony form or
 * an HTTP request. The submission is flushed on the bundle's entity manager, then {@see ContactSubmissionCreatedEvent}
 * is dispatched and the notifier runs, exactly like a submission of the public form.
 */
interface ContactSubmissionRecorderInterface
{
    /**
     * @param string $formSlug slug of an existing contact form (enabled or not)
     * @param array<string, mixed> $values field name => submitted value; names that are not fields of the form are
     *                                     ignored and values are normalised like the public form's
     * @param string|null $ipHash already anonymised client IP (see {@see IpAnonymizer}); null = none
     *
     * @throws ContactFormNotFoundException when no form has that slug
     *
     * @return int id of the stored submission
     */
    public function record(
        string $formSlug,
        array $values,
        string $locale,
        ?string $ipHash = null,
        ?ClientReference $client = null,
        ?DateTimeImmutable $consentGivenAt = null,
    ): int;
}
