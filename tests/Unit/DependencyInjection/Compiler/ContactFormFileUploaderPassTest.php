<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\DependencyInjection\Compiler;

use ArrayObject;
use LogicException;
use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormFileUploaderPass;
use Nowo\ContactFormBundle\Form\ContactFormFieldDefinitionStepType;
use Nowo\ContactFormBundle\Service\ContactFormSubmissionValueNormalizer;
use Nowo\ContactFormBundle\Service\NullContactFormFileUploadHandler;
use Nowo\ContactFormBundle\Upload\ContactFormFileUploaderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 2.0: ContactFormFileUploaderInterface is the only upload port. `file_upload.service` must implement it; the bundle's
 * consumers always get the port.
 */
#[CoversClass(ContactFormFileUploaderPass::class)]
final class ContactFormFileUploaderPassTest extends TestCase
{
    public function testServiceAliasIsFollowedToItsClass(): void
    {
        $container = $this->container('app.uploader');
        $container->register(NullContactFormFileUploadHandler::class . '.copy', NullContactFormFileUploadHandler::class);
        $container->setAlias('app.uploader', NullContactFormFileUploadHandler::class . '.copy');

        (new ContactFormFileUploaderPass())->process($container);

        self::assertSame('app.uploader', (string) $container->getAlias(ContactFormFileUploaderInterface::class));
        self::assertEquals(new Reference(ContactFormFileUploaderInterface::class), $container->getDefinition(ContactFormFieldDefinitionStepType::class)->getArgument('$fileUploadHandler'));
        self::assertEquals(new Reference(ContactFormFileUploaderInterface::class), $container->getDefinition(ContactFormSubmissionValueNormalizer::class)->getArgument('$fileUploadHandler'));
    }

    /**
     * A service whose class is only known at runtime (factory, synthetic) cannot be checked; a missing service is left
     * to the container (it reports the unknown id itself).
     */
    public function testRuntimeClassesAndMissingServicesAreAliasedUnchecked(): void
    {
        $container = $this->container('app.factory_uploader');
        $container->register('app.factory_uploader', ArrayObject::class)->setFactory([ArrayObject::class, 'create']);
        (new ContactFormFileUploaderPass())->process($container);
        self::assertSame('app.factory_uploader', (string) $container->getAlias(ContactFormFileUploaderInterface::class));

        $container = $this->container('app.synthetic');
        $container->register('app.synthetic', stdClass::class)->setSynthetic(true);
        (new ContactFormFileUploaderPass())->process($container);
        self::assertSame('app.synthetic', (string) $container->getAlias(ContactFormFileUploaderInterface::class));

        $container = $this->container('app.missing');
        (new ContactFormFileUploaderPass())->process($container);
        self::assertSame('app.missing', (string) $container->getAlias(ContactFormFileUploaderInterface::class));
    }

    public function testAServiceThatDoesNotImplementThePortFailsTheBuild(): void
    {
        $container = $this->container('app.uploader');
        $container->register('app.uploader', stdClass::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('nowo_contact_form.file_upload.service: the service "app.uploader" (stdClass) must implement "Nowo\ContactFormBundle\Upload\ContactFormFileUploaderInterface", the only upload port since 2.0. "Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface" (deprecated since 1.8) was removed in 2.0');

        (new ContactFormFileUploaderPass())->process($container);
    }

    /**
     * A class that cannot be loaded (for example one that still implements the removed interface) fails the build
     * instead of a fatal error later.
     */
    public function testAClassThatCannotBeLoadedFailsTheBuild(): void
    {
        $container = $this->container('app.parameter_class');
        $container->setParameter('app.class', 'App\\Does\\Not\\Exist');
        $container->register('app.parameter_class', '%app.class%');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the service "app.parameter_class" (App\Does\Not\Exist) must implement');

        (new ContactFormFileUploaderPass())->process($container);
    }

    public function testWithoutConsumersOrParameterNothingFails(): void
    {
        $container = new ContainerBuilder();

        (new ContactFormFileUploaderPass())->process($container);

        self::assertFalse($container->hasAlias(ContactFormFileUploaderInterface::class));
    }

    private function container(?string $service): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(ContactFormFileUploaderPass::SERVICE_PARAMETER, $service);
        $container->register(NullContactFormFileUploadHandler::class, NullContactFormFileUploadHandler::class);
        $container->setAlias(ContactFormFileUploaderInterface::class, NullContactFormFileUploadHandler::class);
        $container->register(ContactFormSubmissionValueNormalizer::class, ContactFormSubmissionValueNormalizer::class);
        $container->register(ContactFormFieldDefinitionStepType::class, ContactFormFieldDefinitionStepType::class);

        return $container;
    }
}
