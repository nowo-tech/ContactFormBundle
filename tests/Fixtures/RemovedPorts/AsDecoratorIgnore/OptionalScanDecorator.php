<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Fixtures\RemovedPorts\AsDecoratorIgnore;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A loadable host decorator of the upload port id removed in 2.0 with `onInvalid: IGNORE` (W1-1, XR2-I1): Symfony
 * would drop it without a word.
 */
#[AsDecorator('Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface', onInvalid: ContainerInterface::IGNORE_ON_INVALID_REFERENCE)]
final readonly class OptionalScanDecorator
{
    public function __construct(#[AutowireDecorated] public ?object $inner = null)
    {
    }
}
