<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function is_object;

/**
 * Resolves client entities from Symfony Security when configured.
 */
final class SecurityClientResolver implements ClientResolverInterface
{
    public function __construct(
        private readonly ?string $clientEntityClass,
        private readonly ?string $clientUserAccessor = null,
        private readonly ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    public function resolve(): ?object
    {
        if ($this->clientEntityClass === null || $this->tokenStorage === null) {
            return null;
        }

        $user = $this->tokenStorage->getToken()?->getUser();

        if (!is_object($user)) {
            return null;
        }

        if (is_a($user, $this->clientEntityClass)) {
            return $user;
        }

        if ($this->clientUserAccessor !== null && method_exists($user, $this->clientUserAccessor)) {
            $client = $user->{$this->clientUserAccessor}();

            if (is_object($client) && is_a($client, $this->clientEntityClass)) {
                return $client;
            }
        }

        return null;
    }
}
