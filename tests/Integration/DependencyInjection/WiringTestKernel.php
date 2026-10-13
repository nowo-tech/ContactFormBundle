<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\ContactFormBundle\Notification\ContactSubmissionNotifierInterface;
use Nowo\ContactFormBundle\NowoContactFormBundle;
use Nowo\ContactFormBundle\Service\ContactFormSubmissionRateLimiter;
use Nowo\ContactFormBundle\Service\SecurityClientResolver;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;
use function md5;
use function serialize;
use function sys_get_temp_dir;

/**
 * Real kernel (FrameworkBundle + this bundle) used to prove cross-bundle wiring.
 *
 * Extension::load() runs on an isolated container in real apps, so unit tests that pre-register
 * FrameworkBundle services on the ContainerBuilder cannot catch wiring that depends on them.
 */
final class WiringTestKernel extends Kernel
{
    use MicroKernelTrait;

    /** Services made public so tests can inspect what the real compilation produced. */
    public const INSPECTED_IDS = [
        ContactFormSubmissionRateLimiter::class,
        SecurityClientResolver::class,
        'clock',
    ];

    /**
     * @param array<string, mixed> $frameworkConfig merged into the base framework config
     * @param array<string, mixed> $contactFormConfig nowo_contact_form config
     */
    public function __construct(
        private readonly array $frameworkConfig = [],
        private readonly array $contactFormConfig = [],
        private readonly bool $withSecurity = true,
    ) {
        parent::__construct('wiring' . substr(md5(serialize([$frameworkConfig, $contactFormConfig, $withSecurity])), 0, 12), false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        if ($this->withSecurity) {
            yield new SecurityBundle();
        }
        yield new TwigBundle();
        yield new TwigExtraBundle();
        yield new DoctrineBundle();
        yield new NowoFormKitBundle();
        yield new NowoUiKitBundle();
        yield new NowoContactFormBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo_contact_form_wiring/' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo_contact_form_wiring/' . $this->environment . '/log';
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach (WiringTestKernel::INSPECTED_IDS as $id) {
                    if ($container->hasDefinition($id)) {
                        $container->getDefinition($id)->setPublic(true);
                    }
                }
                $container->getAlias(ContactSubmissionNotifierInterface::class)->setPublic(true);
            }
        }, PassConfig::TYPE_BEFORE_REMOVING);
    }

    protected function configureContainer(ContainerBuilder $container): void
    {
        $container->loadFromExtension('framework', array_replace([
            'secret'               => 'test_secret',
            'test'                 => true,
            'http_method_override' => false,
            'php_errors'           => ['log' => true],
            'router'               => ['utf8' => true],
            'form'                 => ['enabled' => true, 'csrf_protection' => ['enabled' => false]],
            'csrf_protection'      => false,
            'translator'           => ['enabled' => true, 'fallbacks' => ['en']],
        ], $this->frameworkConfig));
        $container->loadFromExtension('doctrine', [
            'dbal' => ['url' => 'sqlite:///:memory:'],
            'orm'  => [],
        ]);
        $container->loadFromExtension('twig', []);
        if ($this->withSecurity) {
            $container->loadFromExtension('security', [
                'firewalls' => ['main' => ['security' => false]],
            ]);
        }
        $container->loadFromExtension('nowo_contact_form', $this->contactFormConfig);
        // Keep expected 403/404 responses out of the test output (default logger writes to stderr).
        $container->register('logger', NullLogger::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@NowoContactFormBundle/Resources/config/routes.yaml');
    }
}
