<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormSecurityPass;
use Nowo\ContactFormBundle\DependencyInjection\Configuration;
use Nowo\ContactFormBundle\EventSubscriber\ContactFormAdminAccessSubscriber;
use Nowo\ContactFormBundle\Security\ContactFormAccessCheckerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(ContactFormSecurityPass::class)]
final class ContactFormSecurityPassTest extends TestCase
{
    public function testNoopWhenWebUiParameterMissing(): void
    {
        $container = new ContainerBuilder();
        (new ContactFormSecurityPass())->process($container);
        self::assertFalse($container->hasDefinition(ContactFormAdminAccessSubscriber::class));
    }

    public function testRegistersNotFoundSubscriberWhenWebUiDisabled(): void
    {
        // Routes may still be imported: the subscriber must 404 admin routes (no security required).
        $container = $this->baseContainer(enabled: false, allowUnauthenticated: false);
        (new ContactFormSecurityPass())->process($container);

        self::assertTrue($container->hasDefinition(ContactFormAdminAccessSubscriber::class));
        $definition = $container->getDefinition(ContactFormAdminAccessSubscriber::class);
        self::assertFalse($definition->getArgument('$webUiEnabled'));
        self::assertNull($definition->getArgument('$accessChecker'));
        self::assertTrue($definition->hasTag('kernel.event_subscriber'));
    }

    public function testFailsWithoutSecurityWhenNotAllowUnauthenticated(): void
    {
        $container = $this->baseContainer(allowUnauthenticated: false);

        $this->expectException(InvalidConfigurationException::class);
        (new ContactFormSecurityPass())->process($container);
    }

    public function testAllowsMissingSecurityWhenAllowUnauthenticated(): void
    {
        $container = $this->baseContainer(allowUnauthenticated: true);
        (new ContactFormSecurityPass())->process($container);

        self::assertTrue($container->hasDefinition(ContactFormAdminAccessSubscriber::class));
        $definition = $container->getDefinition(ContactFormAdminAccessSubscriber::class);
        self::assertTrue($definition->getArgument('$webUiEnabled'));
        self::assertNull($definition->getArgument('$accessChecker'));
    }

    public function testRegistersSubscriberWhenSecurityPresent(): void
    {
        $container = $this->baseContainer(allowUnauthenticated: false);
        $container->setDefinition('security.authorization_checker', new Definition());
        $container->setAlias(ContactFormAccessCheckerInterface::class, 'security.authorization_checker');

        (new ContactFormSecurityPass())->process($container);

        self::assertTrue($container->hasDefinition(ContactFormAdminAccessSubscriber::class));
        $definition = $container->getDefinition(ContactFormAdminAccessSubscriber::class);
        self::assertInstanceOf(Reference::class, $definition->getArgument('$accessChecker'));
        self::assertSame(ContactFormAccessCheckerInterface::class, (string) $definition->getArgument('$accessChecker'));
        self::assertTrue($definition->getArgument('$webUiEnabled'));
    }

    public function testRegistersSubscriberWhenAccessRolesEmpty(): void
    {
        $container = $this->baseContainer(allowUnauthenticated: false, accessRoles: []);
        $container->setDefinition('security.authorization_checker', new Definition());

        (new ContactFormSecurityPass())->process($container);

        self::assertTrue($container->hasDefinition(ContactFormAdminAccessSubscriber::class));
    }

    public function testDoesNotDuplicateExistingSubscriber(): void
    {
        $container = $this->baseContainer(allowUnauthenticated: false);
        $container->setDefinition('security.authorization_checker', new Definition());
        $container->setDefinition(ContactFormAdminAccessSubscriber::class, new Definition());

        (new ContactFormSecurityPass())->process($container);

        self::assertTrue($container->hasDefinition(ContactFormAdminAccessSubscriber::class));
    }

    public function testReplacesExcludedPlaceholderLeftByResourceLoading(): void
    {
        // services.yaml excludes the subscriber class: Symfony registers an abstract `container.excluded` placeholder.
        $container = $this->baseContainer(allowUnauthenticated: false);
        $container->setDefinition('security.authorization_checker', new Definition());
        $container->register(ContactFormAdminAccessSubscriber::class, ContactFormAdminAccessSubscriber::class)
            ->setAbstract(true)
            ->addTag('container.excluded');

        (new ContactFormSecurityPass())->process($container);

        $definition = $container->getDefinition(ContactFormAdminAccessSubscriber::class);
        self::assertFalse($definition->isAbstract());
        self::assertFalse($definition->hasTag('container.excluded'));
        self::assertTrue($definition->hasTag('kernel.event_subscriber'));
    }

    /**
     * @param list<string> $accessRoles
     */
    private function baseContainer(
        bool $enabled = true,
        bool $allowUnauthenticated = true,
        array $accessRoles = ['ROLE_ADMIN'],
    ): ContainerBuilder {
        $container = new ContainerBuilder();
        $container->setParameter(Configuration::ALIAS . '.web_ui.enabled', $enabled);
        $container->setParameter(Configuration::ALIAS . '.security.allow_unauthenticated', $allowUnauthenticated);
        $container->setParameter(Configuration::ALIAS . '.security.access_roles', $accessRoles);

        return $container;
    }
}
