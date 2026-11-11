<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormEntityManagerPass;
use Nowo\ContactFormBundle\DependencyInjection\NowoContactFormExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_map;

final class ContactFormEntityManagerPassTest extends TestCase
{
    public function testDoesNothingWithoutTheParameterOrWithTheDefaultManager(): void
    {
        $container = $this->container(null);
        (new ContactFormEntityManagerPass())->process($container);
        self::assertCount(2, $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls());

        $container = $this->container(null);
        $container->getParameterBag()->remove(NowoContactFormExtension::ENTITY_MANAGER_PARAMETER);
        (new ContactFormEntityManagerPass())->process($container);
        self::assertCount(2, $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls());
    }

    public function testRemovesTheBundleMappingFromTheOtherManagers(): void
    {
        $container = $this->container('contact_form');
        (new ContactFormEntityManagerPass())->process($container);

        $defaultCalls = $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls();
        self::assertSame(['App\\Entity'], array_map(static fn (array $call): mixed => $call[1][1], $defaultCalls));
        self::assertCount(1, $container->getDefinition('doctrine.orm.contact_form_metadata_driver')->getMethodCalls());
    }

    public function testValidateOnlyChecksTheManagerAndKeepsTheChains(): void
    {
        $container = $this->container('contact_form');
        (new ContactFormEntityManagerPass(true))->process($container);
        self::assertCount(2, $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls());

        $this->expectException(InvalidConfigurationException::class);
        (new ContactFormEntityManagerPass(true))->process($this->container('missing'));
    }

    public function testFailsWhenTheManagerIsUnknown(): void
    {
        $container = $this->container('missing');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"missing", but no such Doctrine entity manager');
        (new ContactFormEntityManagerPass())->process($container);
    }

    public function testFailsWhenNoManagerIsConfiguredAtAll(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoContactFormExtension::ENTITY_MANAGER_PARAMETER, 'contact_form');

        $this->expectException(InvalidConfigurationException::class);
        (new ContactFormEntityManagerPass())->process($container);
    }

    public function testFailsWhenTheConfiguredManagerDoesNotMapTheEntities(): void
    {
        $container = $this->container('contact_form');
        $container->getDefinition('doctrine.orm.contact_form_metadata_driver')->setMethodCalls([]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('does not map');
        (new ContactFormEntityManagerPass())->process($container);
    }

    private function container(?string $entityManager): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoContactFormExtension::ENTITY_MANAGER_PARAMETER, $entityManager);
        $container->setParameter('doctrine.entity_managers', [
            'default'      => 'doctrine.orm.default_entity_manager',
            'contact_form' => 'doctrine.orm.contact_form_entity_manager',
            'no_chain'     => 'doctrine.orm.no_chain_entity_manager',
        ]);

        $container->setDefinition('doctrine.orm.default_metadata_driver', (new Definition())
            ->addMethodCall('addDriver', [new Reference('attribute'), 'App\\Entity'])
            ->addMethodCall('addDriver', [new Reference('attribute'), ContactFormEntityManagerPass::ENTITY_NAMESPACE]));
        $container->setDefinition('doctrine.orm.contact_form_metadata_driver', (new Definition())
            ->addMethodCall('addDriver', [new Reference('bundle'), ContactFormEntityManagerPass::ENTITY_NAMESPACE]));

        return $container;
    }
}
