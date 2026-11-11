<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Exception;

use InvalidArgumentException;
use Nowo\ContactFormBundle\Service\ContactSubmissionRecorderInterface;

use function sprintf;

/**
 * No contact form has the given slug (1.7, {@see ContactSubmissionRecorderInterface}).
 */
final class ContactFormNotFoundException extends InvalidArgumentException
{
    public static function forSlug(string $slug): self
    {
        return new self(sprintf('No contact form has the slug "%s".', $slug));
    }
}
