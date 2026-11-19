<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\DependencyInjection\Compiler;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\MiddlewaresPass;
use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormCommitTrackingPass;
use Nowo\ContactFormBundle\DependencyInjection\NowoContactFormExtension;
use Nowo\ContactFormBundle\EventSubscriber\ContactFormConflictListener;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

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

        (new ContactFormCommitTrackingPass())->process($container);

        self::assertFalse($container->hasDefinition(self::MIDDLEWARE));
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

    private function compile(ContainerBuilder $container): void
    {
        (new ContactFormCommitTrackingPass())->process($container);
        (new MiddlewaresPass())->process($container);
    }
}
