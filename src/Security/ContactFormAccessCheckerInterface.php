<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Security;

/**
 * Access control for Contact Form admin CRUD routes.
 */
interface ContactFormAccessCheckerInterface
{
    public function canAccess(): bool;
}
