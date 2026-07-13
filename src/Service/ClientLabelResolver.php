<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

use function is_string;

/**
 * Resolves optional client entity labels when linking submissions to existing clients.
 */
final class ClientLabelResolver
{
    public function __construct(
        private readonly ?string $clientEntityClass = null,
        private readonly string $clientLabelProperty = 'email',
    ) {
    }

    public function getClientEntityClass(): ?string
    {
        return $this->clientEntityClass;
    }

    public function resolveLabel(object $client): string
    {
        $property = $this->clientLabelProperty;

        if (property_exists($client, $property)) {
            $value = $client->{$property};

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        if (method_exists($client, $property)) {
            $value = $client->{$property}();

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $getter = 'get' . ucfirst($property);
        if (method_exists($client, $getter)) {
            $value = $client->{$getter}();

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        if (method_exists($client, 'getId')) {
            return (string) $client->getId();
        }

        return 'client';
    }
}
