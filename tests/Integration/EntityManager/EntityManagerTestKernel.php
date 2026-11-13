<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\EntityManager;

use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Nowo\ContactFormBundle\Client\ClientReferenceResolverInterface;
use Nowo\ContactFormBundle\Tests\Integration\TestKernel;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

use function dirname;
use function getmypid;
use function hash;
use function serialize;
use function sys_get_temp_dir;

/**
 * {@see TestKernel} with a configurable Doctrine setup (1.7, `doctrine.entity_manager`): one cache directory per
 * configuration and PHPUnit process.
 */
final class EntityManagerTestKernel extends TestKernel
{
    private readonly string $cacheKey;

    /**
     * @param array<string, mixed> $doctrine
     * @param array<string, mixed> $contactForm
     * @param bool $hostDefaultDriver the host gives its default manager's chain a catch-all default driver (what
     *                                DoctrineBundle 3.4 `auto_discover_entities` does)
     */
    public function __construct(
        private readonly array $doctrine,
        private readonly array $contactForm = [],
        private readonly bool $hostDefaultDriver = false,
    ) {
        $this->cacheKey = hash('xxh128', serialize([$doctrine, $contactForm, $hostDefaultDriver]));
        parent::__construct('test', false);
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo_contact_form_em/' . getmypid() . '/' . $this->cacheKey . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo_contact_form_em/' . getmypid() . '/' . $this->cacheKey . '/log';
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        if (!$this->hostDefaultDriver) {
            return;
        }

        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                // An attribute driver answers "not transient" for any class carrying #[ORM\Entity], whatever its paths.
                $container->getDefinition('doctrine.orm.default_metadata_driver')
                    ->addMethodCall('setDefaultDriver', [new Definition(AttributeDriver::class, [[__DIR__ . '/Entity'], true])]);
            }
        });
    }

    protected function configureContainer(ContainerBuilder $container): void
    {
        parent::configureContainer($container);
        // Set by the test that configures client_reference_resolver.
        $container->register('test.client_reference_resolver', ClientReferenceResolverInterface::class)->setSynthetic(true)->setPublic(true);
    }

    protected function doctrineConfig(string $databasePath): array
    {
        return $this->doctrine;
    }

    protected function contactFormConfig(): array
    {
        return $this->contactForm;
    }
}
