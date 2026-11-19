<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\DependencyInjection\Compiler;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\MiddlewaresPass;
use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormCommitTrackingPass;
use Nowo\ContactFormBundle\DependencyInjection\NowoContactFormExtension;
use Nowo\ContactFormBundle\Doctrine\ExcludedEntityNamespaceDriver;
use Nowo\ContactFormBundle\EventSubscriber\ContactFormConflictListener;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function sprintf;

/**
 * Audit UE-CF-1: the bundle's commit-tracking middleware never wraps the host's other connections by default, and is
 * not registered at all when transaction-doctrine's commit_detection already counts commits.
 */
#[CoversClass(ContactFormCommitTrackingPass::class)]
final class ContactFormCommitTrackingPassTest extends TestCase
{
    private const MIDDLEWARE = NowoContactFormExtension::COMMIT_TRACKING_MIDDLEWARE;

    public function testByDefaultOnlyTheConnectionOfTheBundleEntityManagerIsWrapped(): void
    {
        $container = $this->container('bundle', 'contact_form');

        $this->compile($container);

        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.contact'), 'the bundle connection');
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.default'), 'never the host connection');
        self::assertSame(NowoContactFormExtension::COMMIT_LEDGER_SERVICE, (string) $container->getDefinition(ContactFormConflictListener::class)->getArgument(1));
    }

    public function testWithoutDoctrineEntityManagerTheDefaultManagerConnectionIsWrapped(): void
    {
        $container = $this->container('bundle', null);
        $container->setParameter('doctrine.default_entity_manager', 'contact_form');

        $this->compile($container);

        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.contact'));
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.default'));
    }

    public function testAnUnresolvableManagerFallsBackToTheDefaultConnection(): void
    {
        $container = $this->container('bundle', 'missing');
        $container->setParameter('doctrine.default_connection', 'contact');
        $this->compile($container);
        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.contact'));
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.default'));

        $container = $this->container('bundle', null);
        $container->getParameterBag()->remove(NowoContactFormExtension::COMMIT_TRACKING_PARAMETER);
        $this->compile($container);
        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.default'), 'no parameter: bundle mode; no default manager or connection: "default"');
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.contact'));
    }

    public function testAllWrapsEveryConnection(): void
    {
        $container = $this->container('all', 'contact_form');

        $this->compile($container);

        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.contact'));
        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.default'));
    }

    public function testTransactionDoctrineCommitDetectionIsReusedAndNoConnectionIsWrappedTwice(): void
    {
        $container = $this->container('all', 'contact_form');
        $container->setDefinition('nowo_transaction_doctrine.commit_ledger', new Definition(CommitLedger::class));
        $container->setAlias(CommitLedger::class, 'nowo_transaction_doctrine.commit_ledger');
        $this->registerTransactionDoctrineMiddleware($container);

        $this->compile($container);

        self::assertFalse($container->hasDefinition(self::MIDDLEWARE));
        self::assertFalse($container->hasDefinition(NowoContactFormExtension::COMMIT_LEDGER_SERVICE));
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.contact'));
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.default'));
        self::assertSame(CommitLedger::class, (string) $container->getDefinition(ContactFormConflictListener::class)->getArgument(1));
    }

    public function testTransactionDoctrineLedgerWithoutTheListenerOnlyDropsTheMiddleware(): void
    {
        $container = $this->container('bundle', 'contact_form');
        $container->removeDefinition(ContactFormConflictListener::class);
        $container->setDefinition(CommitLedger::class, new Definition(CommitLedger::class));
        $this->registerTransactionDoctrineMiddleware($container);

        (new ContactFormCommitTrackingPass())->process($container);

        self::assertFalse($container->hasDefinition(self::MIDDLEWARE));
    }

    /**
     * Audit UE3-CF-2: a CommitLedger service that transaction-doctrine's middleware does not feed counts nothing; the
     * bundle keeps its own ledger and middleware so CONFLICT_002 is still detected.
     */
    public function testAStrayCommitLedgerWithoutTransactionDoctrineMiddlewareKeepsTheBundleTracking(): void
    {
        $container = $this->container('bundle', 'contact_form');
        $container->setDefinition(CommitLedger::class, new Definition(CommitLedger::class));

        $this->compile($container);

        self::assertTrue($container->hasDefinition(NowoContactFormExtension::COMMIT_LEDGER_SERVICE));
        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.contact'));
        self::assertSame(NowoContactFormExtension::COMMIT_LEDGER_SERVICE, (string) $container->getDefinition(ContactFormConflictListener::class)->getArgument(1));
    }

    public function testAnUntaggedTransactionDoctrineMiddlewareKeepsTheBundleTracking(): void
    {
        $container = $this->container('bundle', 'contact_form');
        $container->setDefinition(CommitLedger::class, new Definition(CommitLedger::class));
        $container->setDefinition(ContactFormCommitTrackingPass::TRANSACTION_DOCTRINE_MIDDLEWARE, new Definition(CommitTrackingMiddleware::class, [new Reference(CommitLedger::class)]));

        $this->compile($container);

        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.contact'));
        self::assertSame(NowoContactFormExtension::COMMIT_LEDGER_SERVICE, (string) $container->getDefinition(ContactFormConflictListener::class)->getArgument(1));
    }

    /**
     * Audit UE3-CF-1: without doctrine.entity_manager, a host mapping the bundle entities on a non-default manager gets
     * that manager's connection wrapped too (getManagerForClass() may resolve it), next to the default manager's.
     */
    public function testWithoutDoctrineEntityManagerEveryManagerMappingTheEntitiesIsWrapped(): void
    {
        $container = $this->container('bundle', null);
        $container->setParameter('doctrine.connections', [
            'default' => 'doctrine.dbal.default_connection',
            'contact' => 'doctrine.dbal.contact_connection',
            'other'   => 'doctrine.dbal.other_connection',
            'parent'  => 'doctrine.dbal.parent_connection',
        ]);
        foreach (['other', 'parent'] as $connection) {
            $container->setDefinition(sprintf('doctrine.dbal.%s_connection.configuration', $connection), new Definition());
        }

        $container->setParameter('doctrine.entity_managers', [
            'default'      => 'doctrine.orm.default_entity_manager',
            'contact_form' => 'doctrine.orm.contact_form_entity_manager',
            'other'        => 'doctrine.orm.other_entity_manager',
            'parent'       => 'doctrine.orm.parent_entity_manager',
            'no_chain'     => 'doctrine.orm.no_chain_entity_manager',
        ]);
        $container->setDefinition('doctrine.orm.default_entity_manager', new Definition(null, [new Reference('doctrine.dbal.default_connection')]));
        $container->setDefinition('doctrine.orm.other_entity_manager', new Definition(null, [new Reference('doctrine.dbal.other_connection')]));
        $container->setDefinition('doctrine.orm.parent_entity_manager', new Definition(null, [new Reference('doctrine.dbal.parent_connection')]));
        $container->setDefinition('doctrine.orm.default_metadata_driver', (new Definition())
            ->addMethodCall('addDriver', [new Definition(), 'App\\Entity']));
        $container->setDefinition('doctrine.orm.contact_form_metadata_driver', (new Definition())
            ->addMethodCall('setDefaultDriver', [new Definition()])
            ->addMethodCall('addDriver', [new Definition(), 'Nowo\\ContactFormBundle\\Entity']));
        $container->setDefinition('doctrine.orm.other_metadata_driver', (new Definition())
            ->addMethodCall('addDriver', [new Definition(ExcludedEntityNamespaceDriver::class), 'Nowo\\ContactFormBundle\\Entity'])
            ->addMethodCall('addDriver', [new Definition(), 'Nowo\\ContactFormBundleExtra\\Entity'])
            ->addMethodCall('addDriver', [new Definition(), null]));
        $container->setDefinition('doctrine.orm.parent_metadata_driver', (new Definition())
            ->addMethodCall('addDriver', [new Definition(), 'Nowo\\ContactFormBundle\\']));

        $this->compile($container);

        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.default'), 'the default manager (the bundle mapping driver targets it)');
        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.contact'), 'the non-default manager mapping the entities');
        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.parent'), 'a parent namespace covers the entities');
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.other'), 'an excluded or unrelated namespace does not');
    }

    public function testOffRegistersNothingAndThePassLeavesTheContainerAlone(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoContactFormExtension::COMMIT_TRACKING_PARAMETER, 'off');

        (new ContactFormCommitTrackingPass())->process($container);

        self::assertFalse($container->hasDefinition(self::MIDDLEWARE));
    }

    private function container(string $mode, ?string $entityManager): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoContactFormExtension::COMMIT_TRACKING_PARAMETER, $mode);
        $container->setParameter(NowoContactFormExtension::ENTITY_MANAGER_PARAMETER, $entityManager);
        $container->setParameter('doctrine.connections', ['default' => 'doctrine.dbal.default_connection', 'contact' => 'doctrine.dbal.contact_connection']);
        $container->setDefinition('doctrine.dbal.default_connection.configuration', new Definition());
        $container->setDefinition('doctrine.dbal.contact_connection.configuration', new Definition());
        $container->setDefinition('doctrine.orm.contact_form_entity_manager', new Definition(null, [new Reference('doctrine.dbal.contact_connection')]));
        $container->setDefinition(NowoContactFormExtension::COMMIT_LEDGER_SERVICE, new Definition(CommitLedger::class));
        $container->setDefinition(self::MIDDLEWARE, (new Definition(CommitTrackingMiddleware::class, [new Reference(NowoContactFormExtension::COMMIT_LEDGER_SERVICE)]))
            ->addTag('doctrine.middleware'));
        $container->setDefinition(ContactFormConflictListener::class, new Definition(ContactFormConflictListener::class, [null, new Reference(NowoContactFormExtension::COMMIT_LEDGER_SERVICE), null]));

        return $container;
    }

    private function registerTransactionDoctrineMiddleware(ContainerBuilder $container): void
    {
        $container->setDefinition(ContactFormCommitTrackingPass::TRANSACTION_DOCTRINE_MIDDLEWARE, (new Definition(CommitTrackingMiddleware::class, [new Reference(CommitLedger::class)]))
            ->addTag('doctrine.middleware'));
    }

    private function compile(ContainerBuilder $container): void
    {
        (new ContactFormCommitTrackingPass())->process($container);
        (new MiddlewaresPass())->process($container);
    }
}
