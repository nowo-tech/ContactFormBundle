<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\DependencyInjection\Compiler;

use ArrayObject;
use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormFileUploaderPass;
use Nowo\ContactFormBundle\Form\ContactFormFieldDefinitionStepType;
use Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface;
use Nowo\ContactFormBundle\Service\ContactFormSubmissionValueNormalizer;
use Nowo\ContactFormBundle\Service\NullContactFormFileUploadHandler;
use Nowo\ContactFormBundle\Upload\ContactFormFileUploaderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(ContactFormFileUploaderPass::class)]
final class ContactFormFileUploaderPassTest extends TestCase
{
    public function testUnresolvableClassKeepsThePreviousWiringWithoutADeprecation(): void
    {
        $container = $this->container('app.factory_uploader');
        $container->register('app.factory_uploader', ArrayObject::class)->setFactory([ArrayObject::class, 'create']);

        (new ContactFormFileUploaderPass())->process($container);

        $alias = $container->getAlias(ContactFormFileUploadHandlerInterface::class);
        self::assertSame('app.factory_uploader', (string) $alias);
        self::assertFalse($alias->isDeprecated());
        self::assertSame(NullContactFormFileUploadHandler::class, (string) $container->getAlias(ContactFormFileUploaderInterface::class));
        // The host service is on the deprecated port only: the consumers use it.
        self::assertEquals(new Reference(ContactFormFileUploadHandlerInterface::class), $container->getDefinition(ContactFormSubmissionValueNormalizer::class)->getArgument('$fileUploadHandler'));
    }

    public function testMissingServiceAndNonClassIdKeepThePreviousWiring(): void
    {
        $container = $this->container('app.missing');
        (new ContactFormFileUploaderPass())->process($container);
        self::assertSame('app.missing', (string) $container->getAlias(ContactFormFileUploadHandlerInterface::class));

        $container = $this->container('app.parameter_class');
        $container->setParameter('app.class', 'App\\Does\\Not\\Exist');
        $container->register('app.parameter_class', '%app.class%');
        (new ContactFormFileUploaderPass())->process($container);
        self::assertSame('app.parameter_class', (string) $container->getAlias(ContactFormFileUploadHandlerInterface::class));
    }

    public function testServiceAliasIsFollowedToItsClass(): void
    {
        $container = $this->container('app.uploader');
        $container->register(NullContactFormFileUploadHandler::class . '.copy', NullContactFormFileUploadHandler::class);
        $container->setAlias('app.uploader', NullContactFormFileUploadHandler::class . '.copy');

        (new ContactFormFileUploaderPass())->process($container);

        self::assertSame('app.uploader', (string) $container->getAlias(ContactFormFileUploaderInterface::class));
        self::assertTrue($container->getAlias(ContactFormFileUploadHandlerInterface::class)->isDeprecated());
        self::assertEquals(new Reference(ContactFormFileUploaderInterface::class), $container->getDefinition(ContactFormFieldDefinitionStepType::class)->getArgument('$fileUploadHandler'));
    }

    public function testPortDefinedAsAServiceIsHostProvided(): void
    {
        $container = $this->container(null);
        $container->removeAlias(ContactFormFileUploadHandlerInterface::class);
        $container->register(ContactFormFileUploadHandlerInterface::class, NullContactFormFileUploadHandler::class);
        $container->removeAlias(ContactFormFileUploaderInterface::class);

        (new ContactFormFileUploaderPass())->process($container);

        self::assertEquals(new Reference(ContactFormFileUploadHandlerInterface::class), $container->getDefinition(ContactFormSubmissionValueNormalizer::class)->getArgument('$fileUploadHandler'));
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
        $container->setAlias(ContactFormFileUploadHandlerInterface::class, NullContactFormFileUploadHandler::class);
        $container->register(ContactFormSubmissionValueNormalizer::class, ContactFormSubmissionValueNormalizer::class);
        $container->register(ContactFormFieldDefinitionStepType::class, ContactFormFieldDefinitionStepType::class);

        return $container;
    }
}
