<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormRemovedPortsPass;
use Nowo\ContactFormBundle\Service\NullContactFormFileUploadHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * W1-1: every trace of the upload port removed in 2.0 fails the build, by its exact class name only.
 */
#[CoversClass(ContactFormRemovedPortsPass::class)]
final class ContactFormRemovedPortsPassTest extends TestCase
{
    public function testAContainerWithoutTheRemovedPortCompiles(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.uploader', NullContactFormFileUploadHandler::class);
        // An unrelated missing class (a longer name that starts with the removed one) keeps its lazy error.
        $container->register('app.other', stdClass::class)->addError('Class "' . ContactFormRemovedPortsPass::REMOVED_UPLOAD_PORT . 'Policy" not found while loading "App\\Other".');

        (new ContactFormRemovedPortsPass())->process($container);

        self::assertSame(['Class "' . ContactFormRemovedPortsPass::REMOVED_UPLOAD_PORT . 'Policy" not found while loading "App\\Other".'], $container->getDefinition('app.other')->getErrors());
    }

    public function testAnAliasUnderTheRemovedIdFailsTheBuild(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.uploader', NullContactFormFileUploadHandler::class);
        $container->setAlias(ContactFormRemovedPortsPass::REMOVED_UPLOAD_PORT, 'app.uploader');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The service id "Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface" (an alias of "app.uploader") is no longer read by NowoContactFormBundle. "Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface" (deprecated since 1.8) was removed in 2.0');

        (new ContactFormRemovedPortsPass())->process($container);
    }

    public function testAServiceUnderTheRemovedIdFailsTheBuild(): void
    {
        $container = new ContainerBuilder();
        $container->register(ContactFormRemovedPortsPass::REMOVED_UPLOAD_PORT, NullContactFormFileUploadHandler::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The service id "Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface" is no longer read by NowoContactFormBundle.');

        (new ContactFormRemovedPortsPass())->process($container);
    }

    public function testALoadingErrorNamingTheRemovedInterfaceFailsTheBuild(): void
    {
        $container = new ContainerBuilder();
        $container->register('App\\Upload\\LegacyUploader', 'App\\Upload\\LegacyUploader')
            ->addError('Class "' . ContactFormRemovedPortsPass::REMOVED_UPLOAD_PORT . '" not found while loading "App\\Upload\\LegacyUploader".');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The service "App\Upload\LegacyUploader" cannot be loaded: Class "Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface" not found while loading "App\Upload\LegacyUploader". "Nowo');

        (new ContactFormRemovedPortsPass())->process($container);
    }

    public function testADecoratorOfTheRemovedIdFailsTheBuildAlsoWhenInvalidIsIgnored(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.scanning_uploader', NullContactFormFileUploadHandler::class)
            ->setDecoratedService(ContactFormRemovedPortsPass::REMOVED_UPLOAD_PORT, null, 0, ContainerInterface::IGNORE_ON_INVALID_REFERENCE);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The service "app.scanning_uploader" decorates "Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface", which no longer exists. Decorate "Nowo\ContactFormBundle\Upload\ContactFormFileUploaderInterface" instead.');

        (new ContactFormRemovedPortsPass())->process($container);
    }
}
