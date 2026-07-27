<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Security;

/**
 * Permissive checker used only when security.allow_unauthenticated is true (demo/dev).
 */
final class AllowAllContactFormAccessChecker implements ContactFormAccessCheckerInterface
{
    public function canAccess(): bool
    {
        return true;
    }
}
