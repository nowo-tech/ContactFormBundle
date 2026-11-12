<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Upload;

use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormFileUploaderPass;
use Nowo\ContactFormBundle\Form\ContactFormFieldDefinitionStepType;
use Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface;
use Nowo\ContactFormBundle\Service\ContactFormSubmissionValueNormalizer;
use Nowo\ContactFormBundle\Service\NullContactFormFileUploadHandler;
use Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\DddHost\Infrastructure\ContactFormAttachmentAdapter;
use Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\DecoratingFileUploader;
use Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\DualHostFileStore;
use Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\HostFileUploader;
use Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\LegacyHostFileHandler;
use Nowo\ContactFormBundle\Upload\ContactFormFileUploaderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Filesystem\Filesystem;

/**
 * 1.8: `lint:container` (Symfony's `CheckAliasValidityPass` + `CheckTypeDeclarationsPass`) passes for a host on the
 * new port only, on the deprecated port only, on both, with a decorator of the new port, and with `file_upload.service`
 * pointing at each kind of service. Each alias resolves to a service that implements it, the deprecated alias is
 * re-pointed (and marked deprecated) only at a service that implements it, and the bundle's consumers use the host
 * service.
 */
#[CoversClass(ContactFormFileUploaderPass::class)]
final class FileUploadPortsKernelTest extends TestCase
{
    /**
     * @return iterable<string, array{string, class-string, array{string, bool}, array{string, bool}}>
     */
    public static function variants(): iterable
    {
        $null = NullContactFormFileUploadHandler::class;

        // variant, service of both consumers, [new alias target, deprecated], [legacy alias target, deprecated]
        yield 'bundle defaults' => ['none', $null, [$null, false], [$null, true]];
        yield 'new port only' => ['new', HostFileUploader::class, [HostFileUploader::class, false], [$null, true]];
        yield 'deprecated port only' => ['legacy', LegacyHostFileHandler::class, [$null, false], [LegacyHostFileHandler::class, false]];
        yield 'both ports' => ['both', DualHostFileStore::class, [DualHostFileStore::class, false], [DualHostFileStore::class, false]];
        yield 'decorator of the new port' => ['decorator', DecoratingFileUploader::class, [DecoratingFileUploader::class, false], [$null, true]];
        yield 'decorator over a host uploader' => ['decorator-host', DecoratingFileUploader::class, [DecoratingFileUploader::class, false], [$null, true]];
        yield 'file_upload.service: new port' => ['config-new', HostFileUploader::class, [HostFileUploader::class, false], [$null, true]];
        yield 'file_upload.service: deprecated port' => ['config-legacy', LegacyHostFileHandler::class, [$null, false], [LegacyHostFileHandler::class, true]];
        yield 'file_upload.service: both ports' => ['config-both', DualHostFileStore::class, [DualHostFileStore::class, false], [DualHostFileStore::class, true]];
        yield 'file_upload.service: DDD adapter' => ['ddd', ContactFormAttachmentAdapter::class, [ContactFormAttachmentAdapter::class, false], [$null, true]];
    }

    /**
     * @param class-string $consumerService
     * @param array{string, bool} $newAlias
     * @param array{string, bool} $legacyAlias
     */
    #[DataProvider('variants')]
    #[IgnoreDeprecations]
    public function testLintContainerPassesAndConsumersUseTheHostService(string $variant, string $consumerService, array $newAlias, array $legacyAlias): void
    {
        (new Filesystem())->remove((new UploadPortsKernel($variant))->baseDir());

        // lint:container rebuilds the container on the kernel it runs on (and re-creates its bundles, so that kernel
        // cannot be shut down cleanly afterwards): lint on its own kernel, inspect the services on another one.
        $lintKernel = new UploadPortsKernel($variant);
        $lintKernel->boot();
        $application = new Application($lintKernel);
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('lint:container'));

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('linted successfully', $tester->getDisplay());
        unset($application, $tester, $lintKernel);

        $kernel = new UploadPortsKernel($variant);
        $kernel->boot();

        try {
            $container = $kernel->getContainer();
            self::assertSame($newAlias, $container->getParameter('test.cf_port.new'));
            self::assertSame($legacyAlias, $container->getParameter('test.cf_port.legacy'));

            $probe = $container->get(UploadPortsKernel::PROBE);
            self::assertInstanceOf(ServiceLocator::class, $probe);

            $normalizer = $probe->get(ContactFormSubmissionValueNormalizer::class);
            self::assertInstanceOf(ContactFormSubmissionValueNormalizer::class, $normalizer);
            self::assertInstanceOf($consumerService, (new ReflectionProperty($normalizer, 'fileUploadHandler'))->getValue($normalizer));

            $stepType = $probe->get(ContactFormFieldDefinitionStepType::class);
            self::assertInstanceOf(ContactFormFieldDefinitionStepType::class, $stepType);
            self::assertInstanceOf($consumerService, (new ReflectionProperty($stepType, 'fileUploadHandler'))->getValue($stepType));

            $uploader = $probe->get(ContactFormFileUploaderInterface::class);
            self::assertInstanceOf(ContactFormFileUploaderInterface::class, $uploader);
            if ($variant === 'decorator-host') {
                self::assertInstanceOf(DecoratingFileUploader::class, $uploader);
                self::assertInstanceOf(HostFileUploader::class, $uploader->inner);
            }
        } finally {
            $kernel->shutdown();
            (new Filesystem())->remove($kernel->baseDir());
        }
    }

    /**
     * Without any host file service, nothing the bundle wires uses the deprecated alias: no deprecation is triggered
     * while the container is built (this test runs without #[IgnoreDeprecations]).
     */
    public function testDefaultsTriggerNoDeprecation(): void
    {
        $kernel = new UploadPortsKernel('none');
        (new Filesystem())->remove($kernel->baseDir());
        $kernel->boot();

        try {
            self::assertTrue($kernel->getContainer()->has(UploadPortsKernel::PROBE));
            self::assertFalse($kernel->getContainer()->has(ContactFormFileUploadHandlerInterface::class));
        } finally {
            $kernel->shutdown();
            (new Filesystem())->remove($kernel->baseDir());
        }
    }
}
