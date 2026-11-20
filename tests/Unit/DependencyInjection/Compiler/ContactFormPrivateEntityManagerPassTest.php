<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormPrivateEntityManagerPass;
use Nowo\ContactFormBundle\DependencyInjection\NowoContactFormExtension;
use Nowo\ContactFormBundle\Doctrine\ContactFormHostFlushReporter;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManagerMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(ContactFormPrivateEntityManagerPass::class)]
final class ContactFormPrivateEntityManagerPassTest extends TestCase
{
    public function testHostModeIsLeftAlone(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoContactFormExtension::PRIVATE_SOURCE_MANAGER_PARAMETER, '');

        (new ContactFormPrivateEntityManagerPass())->process($container);

        self::assertSame('', $container->getParameter(NowoContactFormExtension::PRIVATE_SOURCE_MANAGER_PARAMETER));
    }

    public function testTheSourceIsTheApplicationsDefaultManagerAndTheMiddlewareIsScopedToItsConnection(): void
    {
        $container = $this->container('', 'main', ['main' => 'doctrine.orm.main_entity_manager']);
        $container->setDefinition('doctrine.orm.main_entity_manager', new Definition('stdClass', [new Reference('doctrine.dbal.app_connection')]));

        (new ContactFormPrivateEntityManagerPass())->process($container);

        self::assertSame('main', $container->getParameter(NowoContactFormExtension::PRIVATE_SOURCE_MANAGER_PARAMETER));
        self::assertSame([['connection' => 'app']], $container->getDefinition(NowoContactFormExtension::PRIVATE_MIDDLEWARE_SERVICE)->getTag('doctrine.middleware'));
    }

    public function testWithoutADefaultManagerParameterTheSourceIsDefault(): void
    {
        $container = $this->container(null, null, null);

        (new ContactFormPrivateEntityManagerPass())->process($container);

        self::assertSame('default', $container->getParameter(NowoContactFormExtension::PRIVATE_SOURCE_MANAGER_PARAMETER));
        self::assertSame([['connection' => 'default']], $container->getDefinition(NowoContactFormExtension::PRIVATE_MIDDLEWARE_SERVICE)->getTag('doctrine.middleware'));
    }

    public function testADedicatedManagerIsTheSourceAndMustExist(): void
    {
        $container = $this->container('contact_form', 'default', ['default' => 'doctrine.orm.default_entity_manager', 'contact_form' => 'doctrine.orm.contact_form_entity_manager']);
        (new ContactFormPrivateEntityManagerPass())->process($container);
        self::assertSame('contact_form', $container->getParameter(NowoContactFormExtension::PRIVATE_SOURCE_MANAGER_PARAMETER));

        $container = $this->container('missing', 'default', ['default' => 'doctrine.orm.default_entity_manager']);
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_contact_form.doctrine.entity_manager is "missing"');
        (new ContactFormPrivateEntityManagerPass())->process($container);
    }

    /**
     * @return iterable<string, array{0: bool|null, 1: string|null, 2: string|null}>
     */
    public static function hostModes(): iterable
    {
        yield 'unset' => [null, null, NowoContactFormExtension::PRIVATE_ENTITY_MANAGER_UNSET_DEPRECATION];
        yield 'false' => [false, null, NowoContactFormExtension::PRIVATE_ENTITY_MANAGER_FALSE_DEPRECATION];
        yield 'unset, entity_manager names the default manager' => [null, 'main', NowoContactFormExtension::PRIVATE_ENTITY_MANAGER_UNSET_DEPRECATION];
        yield 'false, entity_manager names the default manager' => [false, 'main', NowoContactFormExtension::PRIVATE_ENTITY_MANAGER_FALSE_DEPRECATION];
    }

    /**
     * Host mode (the bundle flushes the application's default manager): the notice of the setting and the probe.
     */
    #[DataProvider('hostModes')]
    #[IgnoreDeprecations]
    public function testHostModeTriggersTheNoticeAndEnablesTheProbe(?bool $setting, ?string $entityManager, string $notice): void
    {
        $this->expectUserDeprecationMessage('Since nowo-tech/contact-form-bundle 1.13: ' . $notice);
        $container = $this->hostModeContainer($setting, $entityManager);

        (new ContactFormPrivateEntityManagerPass())->process($container);

        self::assertTrue($container->getDefinition(ContactFormHostFlushReporter::class)->getArgument(0));
    }

    /**
     * @return iterable<string, array{0: bool|null, 1: string|null}>
     */
    public static function noHostModes(): iterable
    {
        yield 'true' => [true, null];
        yield 'true, entity_manager names the default manager' => [true, 'main'];
        yield 'unset, dedicated manager' => [null, 'contact_form'];
        yield 'false, dedicated manager' => [false, 'contact_form'];
    }

    /**
     * The private manager or a dedicated one never flush the application's manager: no notice, no probe.
     */
    #[DataProvider('noHostModes')]
    public function testNoNoticeNorProbeOutsideHostMode(?bool $setting, ?string $entityManager): void
    {
        $container = $this->hostModeContainer($setting, $entityManager);

        (new ContactFormPrivateEntityManagerPass())->process($container);

        self::assertFalse($container->getDefinition(ContactFormHostFlushReporter::class)->getArgument(0));
    }

    private function hostModeContainer(?bool $setting, ?string $entityManager): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoContactFormExtension::PRIVATE_ENTITY_MANAGER_SETTING_PARAMETER, $setting);
        $container->setParameter(NowoContactFormExtension::PRIVATE_SOURCE_MANAGER_PARAMETER, $entityManager ?? '');
        $container->setParameter('doctrine.default_entity_manager', 'main');
        $container->setDefinition(ContactFormHostFlushReporter::class, new Definition(ContactFormHostFlushReporter::class, [false, null]));

        return $container;
    }

    /**
     * @param array<string, string>|null $managers
     */
    private function container(?string $source, ?string $defaultManager, ?array $managers): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(NowoContactFormExtension::PRIVATE_MIDDLEWARE_SERVICE, new Definition(PrivateEntityManagerMiddleware::class));
        if ($source !== null) {
            $container->setParameter(NowoContactFormExtension::PRIVATE_SOURCE_MANAGER_PARAMETER, $source);
        }
        if ($defaultManager !== null) {
            $container->setParameter('doctrine.default_entity_manager', $defaultManager);
        }
        if ($managers !== null) {
            $container->setParameter('doctrine.entity_managers', $managers);
        }

        return $container;
    }
}
