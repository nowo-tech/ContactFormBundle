<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Fixtures\RemovedPorts\AsDecorator;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * A loadable host decorator (quarantine, antivirus) of the upload port id removed in 2.0 (W1-1).
 */
#[AsDecorator('Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface')]
final readonly class QuarantineUploadDecorator
{
    public function __construct(#[AutowireDecorated] public object $inner)
    {
    }
}
