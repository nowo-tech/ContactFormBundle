<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\DependencyInjection\Compiler;

use Nowo\ContactFormBundle\DependencyInjection\NowoContactFormExtension;
use Nowo\ContactFormBundle\Doctrine\ExcludedEntityNamespaceDriver;
use Nowo\ContactFormBundle\EventSubscriber\ContactFormConflictListener;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_keys;
use function array_unique;
use function array_values;
use function is_array;
use function is_string;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_starts_with;

/**
 * 1.11 (REQ-RUNTIME-003 rule 6): scopes the commit tracking of the conflict answer (audit UE-CF-1).
 *
 * - nowo-tech/transaction-doctrine's bundle registered with `commit_detection`: its {@see CommitLedger} service exists
 *   AND its own tagged middleware (`nowo_transaction_doctrine.commit_tracking_middleware`) already counts every commit,
 *   so the bundle's middleware and ledger are removed and the conflict listener reads transaction-doctrine's ledger.
 *   No connection is wrapped twice. A `CommitLedger` service without that middleware (a host's own, or one registered
 *   by hand) is fed by nothing, so the bundle keeps its own tracking (audit UE3-CF-2).
 * - `conflict_response.commit_tracking: bundle` (default): the bundle's middleware wraps only the connections of the
 *   bundle's entity managers, never the host's other connections. With `doctrine.entity_manager` set, that manager's
 *   connection; unset, the connections of the default manager AND of every manager whose mapping covers
 *   `Nowo\ContactFormBundle\Entity`, because `getManagerForClass()` may resolve any of them at runtime (audit UE3-CF-1).
 * - `all`: every connection (the tag stays without a `connection` attribute).
 * - `off`: the extension registered nothing; this pass does nothing.
 *
 * Runs before DoctrineBundle's MiddlewaresPass (priority 0).
 */
final class ContactFormCommitTrackingPass implements CompilerPassInterface
{
    /** transaction-doctrine's own commit-tracking middleware, registered only with its `commit_detection` on. */
    public const TRANSACTION_DOCTRINE_MIDDLEWARE = 'nowo_transaction_doctrine.commit_tracking_middleware';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(NowoContactFormExtension::COMMIT_TRACKING_MIDDLEWARE)) {
            return;
        }

        if ($this->transactionDoctrineTracksCommits($container)) {
            $container->removeDefinition(NowoContactFormExtension::COMMIT_TRACKING_MIDDLEWARE);
            $container->removeDefinition(NowoContactFormExtension::COMMIT_LEDGER_SERVICE);
            if ($container->hasDefinition(ContactFormConflictListener::class)) {
                $container->getDefinition(ContactFormConflictListener::class)->replaceArgument(1, new Reference(CommitLedger::class));
            }

            return;
        }

        $mode = $container->hasParameter(NowoContactFormExtension::COMMIT_TRACKING_PARAMETER) ? $container->getParameter(NowoContactFormExtension::COMMIT_TRACKING_PARAMETER) : 'bundle';
        if ($mode === 'all') {
            return;
        }

        $middleware = $container->getDefinition(NowoContactFormExtension::COMMIT_TRACKING_MIDDLEWARE)->clearTag('doctrine.middleware');
        foreach ($this->bundleConnections($container) as $connection) {
            $middleware->addTag('doctrine.middleware', ['connection' => $connection]);
        }
    }

    /**
     * transaction-doctrine's ledger is fed only when its commit-tracking middleware is registered and tagged.
     */
    private function transactionDoctrineTracksCommits(ContainerBuilder $container): bool
    {
        return $container->has(CommitLedger::class)
            && $container->hasDefinition(self::TRANSACTION_DOCTRINE_MIDDLEWARE)
            && $container->getDefinition(self::TRANSACTION_DOCTRINE_MIDDLEWARE)->hasTag('doctrine.middleware');
    }

    /**
     * The connections of the bundle's entity managers; the default connection when none can be resolved.
     *
     * @return list<string>
     */
    private function bundleConnections(ContainerBuilder $container): array
    {
        $manager = $container->hasParameter(NowoContactFormExtension::ENTITY_MANAGER_PARAMETER) ? $container->getParameter(NowoContactFormExtension::ENTITY_MANAGER_PARAMETER) : null;
        if (is_string($manager) && $manager !== '') {
            $managers = [$manager];
        } else {
            $default  = $container->hasParameter('doctrine.default_entity_manager') ? $container->getParameter('doctrine.default_entity_manager') : 'default';
            $managers = [is_string($default) ? $default : 'default', ...$this->managersMappingTheEntities($container)];
        }

        $connections = [];
        foreach ($managers as $name) {
            $connection = $this->connectionOf($container, $name);
            if ($connection !== null) {
                $connections[] = $connection;
            }
        }

        if ($connections === []) {
            $default       = $container->hasParameter('doctrine.default_connection') ? $container->getParameter('doctrine.default_connection') : null;
            $connections[] = is_string($default) ? $default : 'default';
        }

        return array_values(array_unique($connections));
    }

    /**
     * Every configured manager whose metadata driver chain has a driver for a namespace covering the bundle entities.
     *
     * @return list<string>
     */
    private function managersMappingTheEntities(ContainerBuilder $container): array
    {
        $managers = $container->hasParameter('doctrine.entity_managers') ? $container->getParameter('doctrine.entity_managers') : [];
        $found    = [];
        foreach (array_keys(is_array($managers) ? $managers : []) as $name) {
            $chainId = sprintf('doctrine.orm.%s_metadata_driver', $name);
            if (!$container->hasDefinition($chainId)) {
                continue;
            }

            foreach ($container->getDefinition($chainId)->getMethodCalls() as $call) {
                if ($call[0] === 'addDriver' && $this->mapsTheEntities($call[1])) {
                    $found[] = (string) $name;

                    break;
                }
            }
        }

        return $found;
    }

    /**
     * @param array<mixed> $arguments the `addDriver(driver, namespace)` arguments
     */
    private function mapsTheEntities(array $arguments): bool
    {
        $driver    = $arguments[0] ?? null;
        $namespace = $arguments[1] ?? null;
        if (!is_string($namespace) || ($driver instanceof Definition && $driver->getClass() === ExcludedEntityNamespaceDriver::class)) {
            return false;
        }

        $mapped = rtrim($namespace, '\\') . '\\';
        $bundle = ContactFormEntityManagerPass::ENTITY_NAMESPACE . '\\';

        return str_starts_with($bundle, $mapped) || str_starts_with($mapped, $bundle);
    }

    private function connectionOf(ContainerBuilder $container, string $manager): ?string
    {
        $id       = sprintf('doctrine.orm.%s_entity_manager', $manager);
        $argument = $container->hasDefinition($id) ? ($container->getDefinition($id)->getArguments()[0] ?? null) : null;
        if ($argument instanceof Reference && preg_match('/^doctrine\.dbal\.(.+)_connection$/D', (string) $argument, $match) === 1) {
            return $match[1];
        }

        return null;
    }
}
