<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

/**
 * Resolves the host client entity from the current security context.
 */
interface ClientResolverInterface
{
    /**
     * Returns the client entity when the authenticated user matches configuration.
     */
    public function resolve(): ?object;
}
