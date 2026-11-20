<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormPrivateEntityManagerPass;
use Nowo\ContactFormBundle\DependencyInjection\NowoContactFormExtension;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManagerMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
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
