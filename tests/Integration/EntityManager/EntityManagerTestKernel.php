<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\EntityManager;

use Nowo\ContactFormBundle\Client\ClientReferenceResolverInterface;
use Nowo\ContactFormBundle\Tests\Integration\TestKernel;
use Symfony\Component\DependencyInjection\ContainerBuilder;

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
     */
    public function __construct(
        private readonly array $doctrine,
        private readonly array $contactForm = [],
    ) {
        $this->cacheKey = hash('xxh128', serialize([$doctrine, $contactForm]));
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
