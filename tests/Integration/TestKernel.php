<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\ContactFormBundle\NowoContactFormBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

use function dirname;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new TwigBundle();
        yield new DoctrineBundle();
        yield new NowoContactFormBundle();
    }

    protected function configureContainer(ContainerBuilder $container): void
    {
        $databasePath = dirname(__DIR__, 2) . '/var/test.sqlite';
        if (!is_dir(dirname($databasePath))) {
            mkdir(dirname($databasePath), 0777, true);
        }

        $container->loadFromExtension('framework', [
            'secret'          => 'test_secret',
            'test'            => true,
            'csrf_protection' => true,
            'form'            => [
                'enabled'         => true,
                'csrf_protection' => ['enabled' => true],
            ],
            'session'         => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'router'          => ['utf8' => true],
            'translator'      => ['enabled' => true, 'fallbacks' => ['en']],
            'enabled_locales' => ['en', 'es'],
            'default_locale'  => 'en',
            'php_errors'      => ['log' => true],
        ]);
        $container->loadFromExtension('doctrine', [
            'dbal' => ['url' => 'sqlite:///' . $databasePath],
            'orm'  => [
                'naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware',
            ],
        ]);
        $container->loadFromExtension('twig', [
            'strict_variables' => false,
        ]);
        $container->loadFromExtension('security', [
            'firewalls' => [
                'main' => [
                    'security' => false,
                ],
            ],
        ]);
        $container->loadFromExtension('nowo_contact_form', [
            'notifications' => ['enabled' => false],
            'security'      => [
                'allow_unauthenticated' => true,
            ],
            'phone_input' => [
                'value_format'            => 'CONCATENATED',
                'default_country'         => 'ES',
                'country_prefix_selector' => true,
                'show_flag'               => true,
            ],
        ]);

        $container->register('security.csrf.token_generator', UriSafeTokenGenerator::class)
            ->setArguments([256])
            ->setPublic(true);
        $container->register('security.csrf.token_storage', SessionTokenStorage::class)
            ->setArguments([new Reference('request_stack')])
            ->setPublic(true);
        $container->register('security.csrf.token_manager', CsrfTokenManager::class)
            ->setArguments([
                new Reference('security.csrf.token_generator'),
                new Reference('security.csrf.token_storage'),
            ])
            ->setPublic(true);

        if ($container->hasDefinition('twig.form.renderer')) {
            $container->getDefinition('twig.form.renderer')
                ->setArgument('$csrfTokenManager', new Reference('security.csrf.token_manager'));
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@NowoContactFormBundle/Resources/config/routes.yaml');
    }
}
