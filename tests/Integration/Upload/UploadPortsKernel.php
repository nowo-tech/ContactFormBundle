<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Upload;

use Nowo\ContactFormBundle\Form\ContactFormFieldDefinitionStepType;
use Nowo\ContactFormBundle\Service\ContactFormSubmissionValueNormalizer;
use Nowo\ContactFormBundle\Tests\Integration\TestKernel;
use Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\DddHost\Domain\AttachmentVault;
use Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\DddHost\Infrastructure\ContactFormAttachmentAdapter;
use Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\DddHost\Infrastructure\InMemoryAttachmentVault;
use Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\DecoratingFileUploader;
use Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\HostFileUploader;
use Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\UploadRecorder;
use Nowo\ContactFormBundle\Upload\ContactFormFileUploaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;

use function dirname;
use function getmypid;
use function sys_get_temp_dir;

/**
 * Host kernel that wires the upload port the way a host would (`ContactFormFileUploaderInterface`, the only one since
 * 2.0):
 *
 * - `none`: bundle defaults; `new`: the host aliases the port;
 * - `decorator`: a decorator of the port over the default; `decorator-host`: a decorator over a host uploader;
 * - `config-new`: `file_upload.service` pointing at a host uploader;
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
        $container->register(InMemoryAttachmentVault::class)->setPublic(true);
        $container->setAlias(AttachmentVault::class, InMemoryAttachmentVault::class);
        $container->register(ContactFormAttachmentAdapter::class)->setAutowired(true);

        match ($this->variant) {
            'new'       => $container->setAlias(ContactFormFileUploaderInterface::class, HostFileUploader::class),
            'decorator' => $container->register(DecoratingFileUploader::class)
                ->setDecoratedService(ContactFormFileUploaderInterface::class)
                ->setArguments([new Reference('.inner')]),
            'decorator-host' => [
                $container->setAlias(ContactFormFileUploaderInterface::class, HostFileUploader::class),
                $container->register(DecoratingFileUploader::class)
                    ->setDecoratedService(ContactFormFileUploaderInterface::class)
                    ->setArguments([new Reference('.inner')]),
            ],
            default => null,
        };

        // Probe: the upload port and the bundle's two consumers.
        $container->register(self::PROBE, ServiceLocator::class)
            ->setArguments([[
                ContactFormFileUploaderInterface::class     => new Reference(ContactFormFileUploaderInterface::class),
                ContactFormSubmissionValueNormalizer::class => new Reference(ContactFormSubmissionValueNormalizer::class),
                ContactFormFieldDefinitionStepType::class   => new Reference(ContactFormFieldDefinitionStepType::class),
            ]])
            ->addTag('container.service_locator')
            ->setPublic(true);
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
            'config-new' => HostFileUploader::class,
            'ddd'        => ContactFormAttachmentAdapter::class,
            default      => null,
        };

        // Many submissions from one client IP in a row: no rate limit.
        $config = ['public_submission_rate_limit' => ['enabled' => false]];

        return $service === null ? $config : $config + ['file_upload' => ['service' => $service]];
    }
}
