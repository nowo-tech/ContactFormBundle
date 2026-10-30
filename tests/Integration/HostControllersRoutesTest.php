<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\RouterInterface;

use function class_exists;
use function is_string;
use function sprintf;
use function str_starts_with;

/**
 * REQ-SF-004 rule 7: the contact form routes are opt-in. A host whose routing is only `routing.controllers`
 * must get none of them (the admin pages would be mounted at the root, without `web_ui.path_prefix`, and the public
 * form without the host import); they exist only where the host imports the bundle routes.yaml.
 */
final class HostControllersRoutesTest extends TestCase
{
    public function testRoutingControllersAloneRegistersNoContactFormRoute(): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('The routing.controllers resource needs symfony/routing >= 7.3.');
        }

        $kernel = new HostControllersRoutesKernel('test', true);
        $kernel->boot();

        try {
            $router = $kernel->getContainer()->get('router');
            self::assertInstanceOf(RouterInterface::class, $router);

            foreach ($router->getRouteCollection()->all() as $name => $route) {
                $controller = $route->getDefault('_controller');
                self::assertFalse(str_starts_with($name, 'nowo_contact_form'), sprintf('Route "%s" must only exist when the host imports the bundle routes.yaml.', $name));
                self::assertFalse(
                    is_string($controller) && str_starts_with($controller, 'Nowo\\ContactFormBundle\\Controller\\'),
                    sprintf('Route "%s" points to a contact form controller without the bundle routes import.', $name),
                );
            }
        } finally {
            $kernel->shutdown();
        }
    }
}
