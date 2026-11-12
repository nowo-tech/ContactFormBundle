<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Upload;

use Nowo\ContactFormBundle\Form\ContactFormFieldDefinitionStepType;
use Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface;
use Nowo\ContactFormBundle\Service\ContactFormSubmissionValueNormalizer;
use Nowo\ContactFormBundle\Tests\Integration\TestKernel;
use Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\DddHost\Domain\AttachmentVault;
use Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\DddHost\Infrastructure\ContactFormAttachmentAdapter;
use Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\DddHost\Infrastructure\InMemoryAttachmentVault;
use Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\DecoratingFileUploader;
use Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\DualHostFileStore;
use Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\HostFileUploader;
use Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\LegacyHostFileHandler;
use Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\QuarantineLegacyHandler;
use Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\UploadRecorder;
use Nowo\ContactFormBundle\Upload\ContactFormFileUploaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;

use function dirname;
use function getmypid;
use function sys_get_temp_dir;

/**
 * Host kernel that wires the file ports the way a host would (1.8):
 *
 * - `none`: bundle defaults; `new` / `legacy` / `both`: the host aliases the 1.8 port, the deprecated port, or both;
 * - `decorator`: a decorator of the 1.8 port over the default; `decorator-host`: a decorator over a host uploader;
 * - `config-new` / `config-legacy` / `config-both`: `file_upload.service` pointing at such a service;
 * - `ddd`: `file_upload.service` pointing at a DDD host adapter of its own domain port.
 */
final class UploadPortsKernel extends TestKernel
{
    public const PROBE = 'test.cf_upload_ports';

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
        return sys_get_temp_dir() . '/nowo_contact_form_upload/' . getmypid() . '/' . $this->variant;
    }

    protected function configureContainer(ContainerBuilder $container): void
    {
        parent::configureContainer($container);

        $container->register(UploadRecorder::class)->setArguments([$this->baseDir() . '/storage'])->setPublic(true);
        $container->register(HostFileUploader::class)->setAutowired(true);
        $container->register(LegacyHostFileHandler::class)->setAutowired(true);
        $container->register(DualHostFileStore::class)->setAutowired(true);
        $container->register(InMemoryAttachmentVault::class)->setPublic(true);
        $container->setAlias(AttachmentVault::class, InMemoryAttachmentVault::class);
        $container->register(ContactFormAttachmentAdapter::class)->setAutowired(true);

        match ($this->variant) {
            'new'    => $container->setAlias(ContactFormFileUploaderInterface::class, HostFileUploader::class),
            'legacy' => $container->setAlias(ContactFormFileUploadHandlerInterface::class, LegacyHostFileHandler::class),
            'both'   => [
                $container->setAlias(ContactFormFileUploaderInterface::class, DualHostFileStore::class),
                $container->setAlias(ContactFormFileUploadHandlerInterface::class, DualHostFileStore::class),
            ],
            'decorator' => $container->register(DecoratingFileUploader::class)
                ->setDecoratedService(ContactFormFileUploaderInterface::class)
                ->setArguments([new Reference('.inner')]),
            'decorator-host' => [
                $container->setAlias(ContactFormFileUploaderInterface::class, HostFileUploader::class),
                $container->register(DecoratingFileUploader::class)
                    ->setDecoratedService(ContactFormFileUploaderInterface::class)
                    ->setArguments([new Reference('.inner')]),
            ],
            // MZ-C-1: decorators of the deprecated port, with a legacy-only alias or a dual file_upload.service.
            'legacy-decorator', 'legacy-decorator-config-both' => [
                $this->variant === 'legacy-decorator' ? $container->setAlias(ContactFormFileUploadHandlerInterface::class, LegacyHostFileHandler::class) : null,
                $container->register(QuarantineLegacyHandler::class)
                    ->setDecoratedService(ContactFormFileUploadHandlerInterface::class)
                    ->setArguments([new Reference('.inner'), new Reference(UploadRecorder::class)]),
            ],
            default => null,
        };

        // Probe: the 1.8 port and the bundle's two consumers (the deprecated alias is checked by the pass below).
        $container->register(self::PROBE, ServiceLocator::class)
            ->setArguments([[
                ContactFormFileUploaderInterface::class     => new Reference(ContactFormFileUploaderInterface::class),
                ContactFormSubmissionValueNormalizer::class => new Reference(ContactFormSubmissionValueNormalizer::class),
                ContactFormFieldDefinitionStepType::class   => new Reference(ContactFormFieldDefinitionStepType::class),
            ]])
            ->addTag('container.service_locator')
            ->setPublic(true);

        // Records where both port aliases point (and whether they are deprecated) before private aliases are removed.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach (['new' => ContactFormFileUploaderInterface::class, 'legacy' => ContactFormFileUploadHandlerInterface::class] as $key => $port) {
                    $alias = $container->getAlias($port);
                    $container->setParameter('test.cf_port.' . $key, [(string) $alias, $alias->isDeprecated()]);
                }
            }
        }, PassConfig::TYPE_BEFORE_REMOVING);
    }

    protected function doctrineConfig(string $databasePath): array
    {
        return [
            'dbal' => ['url' => 'sqlite:///' . $this->baseDir() . '/db.sqlite'],
            'orm'  => ['naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware'],
        ];
    }

    protected function contactFormConfig(): array
    {
        $service = match ($this->variant) {
            'config-new'                                  => HostFileUploader::class,
            'config-legacy'                               => LegacyHostFileHandler::class,
            'config-both', 'legacy-decorator-config-both' => DualHostFileStore::class,
            'ddd'                                         => ContactFormAttachmentAdapter::class,
            default                                       => null,
        };

        // Many submissions from one client IP in a row: no rate limit.
        $config = ['public_submission_rate_limit' => ['enabled' => false]];

        return $service === null ? $config : $config + ['file_upload' => ['service' => $service]];
    }
}
