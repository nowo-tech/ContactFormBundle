<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Fixtures\RemovedPorts\ImplementsOnly;

use Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * A 1.x host handler still on the upload port removed in 2.0 (W1-1): the class cannot be loaded any more, so Symfony
 * never reads its #[AsAlias] and keeps the definition with a loading error only. Excluded from PHPStan and Rector.
 */
#[AsAlias(ContactFormFileUploadHandlerInterface::class)]
final class LegacyUploadHandler implements ContactFormFileUploadHandlerInterface
{
}
