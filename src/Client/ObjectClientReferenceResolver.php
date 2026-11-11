<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Client;

use Nowo\ContactFormBundle\Service\ClientLabelResolver;
use Nowo\ContactFormBundle\Service\ClientResolverInterface;
use Stringable;

use function is_int;
use function is_string;
use function mb_strlen;
use function method_exists;
use function trim;

/**
 * Default {@see ClientReferenceResolverInterface} (1.7): the client entity of {@see ClientResolverInterface} (security
 * user / `client_entity_class`) as a reference — its `getId()` (integer, string, UUID / ULID object…) and the label
 * from `client_label_property`.
 */
final readonly class ObjectClientReferenceResolver implements ClientReferenceResolverInterface
{
    public function __construct(
        private ClientResolverInterface $clientResolver,
        private ClientLabelResolver $labelResolver,
    ) {
    }

    public function resolve(): ?ClientReference
    {
        $client = $this->clientResolver->resolve();

        return $client === null ? null : $this->fromObject($client);
    }

    /**
     * The reference of a host client object; null when it has no usable id (no `getId()`, null, too long) and no label.
     */
    public function fromObject(object $client): ?ClientReference
    {
        if ($client instanceof ClientReference) {
            return $client;
        }

        $label = $this->labelResolver->resolveLabel($client);
        $id    = self::idOf($client);
        if ($id === null) {
            return null;
        }

        return new ClientReference($id, $label);
    }

    /**
     * `getId()` as a string, unchanged (no trimming): integers, strings and Stringable ids (Symfony Uid, Ramsey UUID);
     * null for other types, blank strings and ids longer than 64 characters.
     */
    public static function idOf(object $client): ?string
    {
        if (!method_exists($client, 'getId')) {
            return null;
        }

        $id = $client->getId();
        $id = match (true) {
            is_int($id)               => (string) $id,
            is_string($id)            => $id,
            $id instanceof Stringable => (string) $id,
            default                   => null,
        };

        return $id === null || trim($id) === '' || mb_strlen($id) > ClientReference::MAX_ID_LENGTH ? null : $id;
    }
}
