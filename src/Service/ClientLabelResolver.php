<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

use Stringable;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Throwable;

use function is_scalar;
use function is_string;

/**
 * Resolves optional client entity labels when linking submissions to existing clients.
 *
 * The label is read with Symfony PropertyAccess: getter / isser / hasser / method first, then a public property
 * (a private or protected property is never read directly). A failure falls back to the client id, then to "client".
 */
final readonly class ClientLabelResolver
{
    private PropertyAccessorInterface $propertyAccessor;

    public function __construct(
        private ?string $clientEntityClass = null,
        private string $clientLabelProperty = 'email',
        ?PropertyAccessorInterface $propertyAccessor = null,
    ) {
        $this->propertyAccessor = $propertyAccessor ?? PropertyAccess::createPropertyAccessor();
    }

    public function getClientEntityClass(): ?string
    {
        return $this->clientEntityClass;
    }

    public function resolveLabel(object $client): string
    {
        $value = $this->readLabelProperty($client);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (method_exists($client, 'getId')) {
            $id = $client->getId();

            if (is_scalar($id) || $id instanceof Stringable) {
                return (string) $id;
            }
        }

        return 'client';
    }

    private function readLabelProperty(object $client): mixed
    {
        if ($this->clientLabelProperty === '') {
            return null;
        }

        try {
            // getValue() alone: isReadable() would already call the getter, so it would run twice.
            return $this->propertyAccessor->getValue($client, $this->clientLabelProperty);
        } catch (Throwable) {
            // Not readable (NoSuchPropertyException, private property, uninitialized) or the host getter failed:
            // a label is informative only, never lose the submission for it.
            return null;
        }
    }
}
