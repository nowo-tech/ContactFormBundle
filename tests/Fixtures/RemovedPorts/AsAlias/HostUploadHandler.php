<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Fixtures\RemovedPorts\AsAlias;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * A loadable host class aliased to the id of the upload port removed in 2.0 (W1-1): 2.0 never reads that id.
 */
#[AsAlias('Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface')]
final class HostUploadHandler
{
}
