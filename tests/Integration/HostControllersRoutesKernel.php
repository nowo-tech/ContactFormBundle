<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration;

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * REQ-SF-004 rule 7: same bundles and configuration as TestKernel, but the routing is only the Symfony 7.3+
 * default `controllers: { resource: routing.controllers }` (no import of the bundle routes.yaml).
 */
final class HostControllersRoutesKernel extends TestKernel
{
    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/host-controllers-' . $this->environment;
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('routing.controllers');
    }
}
