<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormPrivateEntityManagerPass;
use Nowo\ContactFormBundle\DependencyInjection\NowoContactFormExtension;
use Nowo\ContactFormBundle\EventSubscriber\ContactFormEntityManagerClearSubscriber;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Nowo\ContactFormBundle\Service\ContactFormEntityManagerResolver;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManagerMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(ContactFormPrivateEntityManagerPass::class)]
final class ContactFormPrivateEntityManagerPassTest extends TestCase
{
    public function testAContainerWithoutTheBundlesPrivateManagerIsLeftAlone(): void
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

    /**
     * The deprecated `private_entity_manager: true` with a dedicated manager keeps the 1.13 behaviour: the private
     * manager on that manager, which must exist.
     */
    public function testTrueWithADedicatedManagerMakesItTheSourceAndItMustExist(): void
    {
        $container = $this->container('contact_form', 'default', ['default' => 'doctrine.orm.default_entity_manager', 'contact_form' => 'doctrine.orm.contact_form_entity_manager'], true);
        (new ContactFormPrivateEntityManagerPass())->process($container);
        self::assertSame('contact_form', $container->getParameter(NowoContactFormExtension::PRIVATE_SOURCE_MANAGER_PARAMETER));
        self::assertTrue($container->hasDefinition(NowoContactFormExtension::PRIVATE_ENTITY_MANAGER_SERVICE));

        $container = $this->container('missing', 'default', ['default' => 'doctrine.orm.default_entity_manager'], true);
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_contact_form.doctrine.entity_manager is "missing"');
        (new ContactFormPrivateEntityManagerPass())->process($container);
    }

    /**
     * `doctrine.entity_manager` naming the default manager is the same manager as unset: the private manager on it.
     */
    public function testEntityManagerNamingTheDefaultManagerUsesThePrivateManager(): void
    {
        $container = $this->container('main', 'main', ['main' => 'doctrine.orm.main_entity_manager']);

        (new ContactFormPrivateEntityManagerPass())->process($container);

        self::assertTrue($container->getParameter(NowoContactFormExtension::PRIVATE_ENTITY_MANAGER_PARAMETER));
        self::assertSame('main', $container->getParameter(NowoContactFormExtension::PRIVATE_SOURCE_MANAGER_PARAMETER));
        self::assertTrue($container->hasDefinition(NowoContactFormExtension::PRIVATE_ENTITY_MANAGER_SERVICE));
        self::assertSame([['connection' => 'default']], $container->getDefinition(NowoContactFormExtension::PRIVATE_MIDDLEWARE_SERVICE)->getTag('doctrine.middleware'));
    }

    /**
     * A dedicated manager (the DDD option, unchanged since 1.7): the bundle uses it directly. The private manager's
     * services are removed, the repositories and the resolver lose the private registry (autowired again) and the
     * boundary subscriber resets nothing of it.
     */
    public function testADedicatedManagerIsUsedDirectly(): void
    {
        $container = $this->container('contact_form', 'default', ['default' => 'doctrine.orm.default_entity_manager', 'contact_form' => 'doctrine.orm.contact_form_entity_manager']);
        $registry  = new Reference(NowoContactFormExtension::PRIVATE_REGISTRY_SERVICE);
        $container->setDefinition(ContactFormRepository::class, (new Definition(ContactFormRepository::class))->setArgument('$registry', $registry));
        $container->setDefinition(ContactFormEntityManagerResolver::class, (new Definition(ContactFormEntityManagerResolver::class))->setArguments(['$registry' => $registry, '$managerName' => 'contact_form']));
        $container->setDefinition('app.other_repository', (new Definition('Nowo\\ContactFormBundle\\Repository\\OtherRepository'))->setArgument('$registry', new Reference('doctrine')));
        $container->setDefinition('app.unrelated', (new Definition('stdClass'))->setArgument('$registry', $registry));
        $container->setDefinition(ContactFormEntityManagerClearSubscriber::class, (new Definition(ContactFormEntityManagerClearSubscriber::class))->setArgument('$resettables', new IteratorArgument([new Reference(NowoContactFormExtension::PRIVATE_ENTITY_MANAGER_SERVICE)])));

        (new ContactFormPrivateEntityManagerPass())->process($container);

        self::assertFalse($container->getParameter(NowoContactFormExtension::PRIVATE_ENTITY_MANAGER_PARAMETER));
        self::assertSame('contact_form', $container->getParameter(NowoContactFormExtension::PRIVATE_SOURCE_MANAGER_PARAMETER));
        foreach ([NowoContactFormExtension::PRIVATE_ENTITY_MANAGER_SERVICE, NowoContactFormExtension::PRIVATE_REGISTRY_SERVICE, NowoContactFormExtension::PRIVATE_MIDDLEWARE_SERVICE] as $id) {
            self::assertFalse($container->hasDefinition($id), $id);
        }
        self::assertSame([], $container->getDefinition(ContactFormRepository::class)->getArguments());
        self::assertSame(['$managerName' => 'contact_form'], $container->getDefinition(ContactFormEntityManagerResolver::class)->getArguments());
        self::assertSame('doctrine', (string) $container->getDefinition('app.other_repository')->getArgument('$registry'), 'another registry is kept');
        self::assertSame(NowoContactFormExtension::PRIVATE_REGISTRY_SERVICE, (string) $container->getDefinition('app.unrelated')->getArgument('$registry'), 'not a bundle service');
        $resettables = $container->getDefinition(ContactFormEntityManagerClearSubscriber::class)->getArgument('$resettables');
        self::assertInstanceOf(IteratorArgument::class, $resettables);
        self::assertSame([], $resettables->getValues());

        // Without the boundary subscriber (a stripped container) the pass still removes the private manager.
        $container = $this->container('contact_form', 'default', ['default' => 'doctrine.orm.default_entity_manager', 'contact_form' => 'doctrine.orm.contact_form_entity_manager']);
        (new ContactFormPrivateEntityManagerPass())->process($container);
        self::assertFalse($container->hasDefinition(NowoContactFormExtension::PRIVATE_MIDDLEWARE_SERVICE));
    }

    /**
     * @param array<string, string>|null $managers
     */
    private function container(?string $source, ?string $defaultManager, ?array $managers, bool $privateSetting = false): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(NowoContactFormExtension::PRIVATE_MIDDLEWARE_SERVICE, new Definition(PrivateEntityManagerMiddleware::class));
        $container->setDefinition(NowoContactFormExtension::PRIVATE_REGISTRY_SERVICE, new Definition('stdClass'));
        $container->setDefinition(NowoContactFormExtension::PRIVATE_ENTITY_MANAGER_SERVICE, new Definition('stdClass'));
        $container->setParameter(NowoContactFormExtension::PRIVATE_ENTITY_MANAGER_PARAMETER, true);
        $container->setParameter(NowoContactFormExtension::PRIVATE_ENTITY_MANAGER_SETTING_PARAMETER, $privateSetting ? true : null);
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
