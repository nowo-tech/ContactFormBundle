<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Upload;

use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormFileUploaderPass;
use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormRemovedPortsPass;
use Nowo\ContactFormBundle\Form\ContactFormFieldDefinitionStepType;
use Nowo\ContactFormBundle\Service\ContactFormSubmissionValueNormalizer;
use Nowo\ContactFormBundle\Service\NullContactFormFileUploadHandler;
use Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\DddHost\Infrastructure\ContactFormAttachmentAdapter;
use Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\DecoratingFileUploader;
use Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\HostFileUploader;
use Nowo\ContactFormBundle\Upload\ContactFormFileUploaderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `lint:container` (Symfony's `CheckAliasValidityPass` + `CheckTypeDeclarationsPass`) passes for a host on the upload
 * port, with a decorator of it, and with `file_upload.service` pointing at a host uploader or a DDD adapter; the
 * bundle's consumers use the host service. The removed 1.x port fails the build: see RemovedPortsKernelTest.
 */
#[CoversClass(ContactFormFileUploaderPass::class)]
final class FileUploadPortsKernelTest extends TestCase
{
    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function variants(): iterable
    {
        // variant, service of both consumers
        yield 'bundle defaults' => ['none', NullContactFormFileUploadHandler::class];
        yield 'host uploader' => ['new', HostFileUploader::class];
        yield 'decorator of the port' => ['decorator', DecoratingFileUploader::class];
        yield 'decorator over a host uploader' => ['decorator-host', DecoratingFileUploader::class];
        yield 'file_upload.service: host uploader' => ['config-new', HostFileUploader::class];
        yield 'file_upload.service: DDD adapter' => ['ddd', ContactFormAttachmentAdapter::class];
    }

    /**
     * @param class-string $consumerService
     */
    #[DataProvider('variants')]
    public function testLintContainerPassesAndConsumersUseTheHostService(string $variant, string $consumerService): void
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
     * The removed port's id is not registered by the bundle any more.
     */
    public function testTheRemovedPortIdIsGone(): void
    {
        $kernel = new UploadPortsKernel('none');
        (new Filesystem())->remove($kernel->baseDir());
        $kernel->boot();

        try {
            self::assertTrue($kernel->getContainer()->has(UploadPortsKernel::PROBE));
            self::assertFalse($kernel->getContainer()->has(ContactFormRemovedPortsPass::REMOVED_UPLOAD_PORT));
        } finally {
            $kernel->shutdown();
            (new Filesystem())->remove($kernel->baseDir());
        }
    }
}
