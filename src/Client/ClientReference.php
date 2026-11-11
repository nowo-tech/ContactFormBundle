<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Client;

use InvalidArgumentException;

use function mb_strlen;
use function mb_substr;
use function sprintf;
use function trim;

/**
 * The host client a submission belongs to, by id (1.7, architecture bar rules 2 and 3): any id type as a string (integer,
 * UUID, ULID…; 1 to 64 characters, kept as given) and an optional display label. No entity, no Doctrine.
 */
final readonly class ClientReference
{
    public const MAX_ID_LENGTH    = 64;
    public const MAX_LABEL_LENGTH = 255;

    public string $id;

    public ?string $label;

    public function __construct(string $id, ?string $label = null)
    {
        if (trim($id) === '' || mb_strlen($id) > self::MAX_ID_LENGTH) {
            throw new InvalidArgumentException(sprintf('A client reference id must be 1 to %d characters.', self::MAX_ID_LENGTH));
        }

        $this->id    = $id;
        $this->label = $label === null || trim($label) === '' ? null : mb_substr($label, 0, self::MAX_LABEL_LENGTH);
    }
}
