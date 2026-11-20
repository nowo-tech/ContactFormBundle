<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration;

/**
 * {@see TestKernel} in host mode (`doctrine.private_entity_manager: false`, deprecated since 1.13, removed in 2.0):
 * the bundle entities live on the application's default manager, which the bundle flushes and recovers when closed.
 * Its container build triggers the 1.13 notice; the tests using it ignore deprecations.
 */
final class HostModeTestKernel extends TestKernel
{
    protected function contactFormConfig(): array
    {
        return ['doctrine' => ['private_entity_manager' => false]];
    }
}
