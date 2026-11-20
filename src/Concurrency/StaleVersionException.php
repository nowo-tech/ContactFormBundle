<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Concurrency;

use RuntimeException;

/**
 * An admin edit was made on a page that is no longer current: another admin saved the same row after the page was
 * opened (REQ-RUNTIME-003 rule 4, optimistic concurrency).
 *
 * It is a domain answer, not a lock conflict: nothing was written, retrying the same request never succeeds, and the
 * admin has to reload the page. The admin controllers catch it and re-render the form with a 409 answer
 * (`X-Error-Code: CONTACT_FORM_STALE_VERSION`); a host that calls the repositories itself catches it too.
 */
final class StaleVersionException extends RuntimeException
{
    /** Value of the `X-Error-Code` header of the 409 answer. */
    public const ERROR_CODE = 'CONTACT_FORM_STALE_VERSION';

    /** Translation key (domain NowoContactFormBundle) of the message shown above the re-rendered form. */
    public const MESSAGE_KEY = 'nowo_contact_form.admin.stale_version';

    /** English text used when no translator is wired. */
    public const MESSAGE = 'This item was changed by someone else after you opened the page. Your changes were not saved. Reload the page and apply them again.';

    /**
     * @param class-string $entityClass The versioned entity that was edited
     * @param int|string|null $id Its identifier
     * @param int $expected The version the edited page was built from
     */
    public function __construct(
        public readonly string $entityClass,
        public readonly int|string|null $id,
        public readonly int $expected,
    ) {
        parent::__construct(self::MESSAGE);
    }
}
