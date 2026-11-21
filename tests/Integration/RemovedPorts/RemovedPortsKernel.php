<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\RemovedPorts;

use Nowo\ContactFormBundle\Tests\Integration\TestKernel;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

use function dirname;
use function getmypid;
use function sys_get_temp_dir;

/**
 * {@see TestKernel} whose application services are registered by resource autodiscovery (autowired, autoconfigured),
 * as `config/services.yaml` does in a standard host, from one directory of `tests/Fixtures/RemovedPorts`.
 */
final class RemovedPortsKernel extends TestKernel
{
    public const FIXTURES_NAMESPACE = 'Nowo\\ContactFormBundle\\Tests\\Fixtures\\RemovedPorts\\';

    public function __construct(private readonly string $variant)
    {
        parent::__construct('test', false);
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        return $this->baseDir() . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->baseDir() . '/log';
    }

    public function baseDir(): string
    {
        return sys_get_temp_dir() . '/nowo_contact_form_removed_ports/' . getmypid() . '/' . $this->variant;
    }

    protected function configureContainer(ContainerBuilder $container): void
    {
        parent::configureContainer($container);

        $fixtures = $this->getProjectDir() . '/tests/Fixtures/RemovedPorts/' . $this->variant;
        (new PhpFileLoader($container, new FileLocator($fixtures), 'test'))->registerClasses(
            (new Definition())->setAutowired(true)->setAutoconfigured(true),
            self::FIXTURES_NAMESPACE . $this->variant . '\\',
            $fixtures . '/*',
        );
    }

    protected function doctrineConfig(string $databasePath): array
    {
        return [
            'dbal' => ['url' => 'sqlite:///' . $this->baseDir() . '/db.sqlite'],
            'orm'  => [],
        ];
    }
}
