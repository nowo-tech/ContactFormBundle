<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\DependencyInjection\Compiler;

use Nowo\ContactFormBundle\DependencyInjection\NowoContactFormExtension;
use Nowo\ContactFormBundle\EventSubscriber\ContactFormEntityManagerClearSubscriber;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

use function array_keys;
use function implode;
use function is_array;
use function is_string;
use function sprintf;
use function str_starts_with;

/**
 * Keeps the bundle entities on `doctrine.entity_manager` only (1.7, architecture bar rule 6).
 *
 * DoctrineBundle's `auto_mapping` (the Flex default on the default manager) maps every bundle's `Entity` directory,
 * so with a dedicated manager configured the entities would be mapped twice and `getManagerForClass()` would return
 * the default manager. This pass removes the bundle namespace from the mapping driver chain of every other manager
 * and fails the build when the configured manager does not exist or does not map the entities. Since 1.7.1 it also
 * hands that manager to {@see ContactFormEntityManagerClearSubscriber} unless it is the host's default manager.
 */
final readonly class ContactFormEntityManagerPass implements CompilerPassInterface
{
    /**
     * @param bool $validateOnly true: only fail clearly on an unknown manager (registered before the mapping pass,
     *                           which would otherwise fail with a missing `doctrine.orm.<name>_metadata_driver`)
     */
    public function __construct(
        private bool $validateOnly = false,
    ) {
    }

    public const ENTITY_NAMESPACE = 'Nowo\\ContactFormBundle\\Entity';

    /**
     * Removes the bundle mapping from the managers other than `doctrine.entity_manager`.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(NowoContactFormExtension::ENTITY_MANAGER_PARAMETER)) {
            return;
        }

        $name = $container->getParameter(NowoContactFormExtension::ENTITY_MANAGER_PARAMETER);
        if (!is_string($name) || $name === '') {
            return;
        }

        $managers = $container->hasParameter('doctrine.entity_managers') ? $container->getParameter('doctrine.entity_managers') : [];
        $managers = is_array($managers) ? $managers : [];
        if (!isset($managers[$name])) {
            throw new InvalidConfigurationException(sprintf('nowo_contact_form.doctrine.entity_manager is "%s", but no such Doctrine entity manager is configured (known: "%s"). Add it under doctrine.orm.entity_managers or remove the option.', $name, implode('", "', array_keys($managers))));
        }

        if ($this->validateOnly) {
            return;
        }

        // 1.7.1 (REQ-RUNTIME-002 rule 4): the boundary subscriber clears the bundle-owned manager, never the host's
        // default one (doctrine.entity_manager may name it explicitly).
        $default = $container->hasParameter('doctrine.default_entity_manager') ? $container->getParameter('doctrine.default_entity_manager') : null;
        if ($name !== $default && $container->hasDefinition(ContactFormEntityManagerClearSubscriber::class)) {
            $container->getDefinition(ContactFormEntityManagerClearSubscriber::class)->setArgument('$entityManagers', new IteratorArgument([
                new Reference(sprintf('doctrine.orm.%s_entity_manager', $name), ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE),
            ]));
        }

        foreach (array_keys($managers) as $manager) {
            $chainId = sprintf('doctrine.orm.%s_metadata_driver', $manager);
            if (!$container->hasDefinition($chainId)) {
                continue;
            }

            $chain = $container->getDefinition($chainId);
            $calls = [];
            $maps  = false;
            foreach ($chain->getMethodCalls() as $call) {
                $isBundleDriver = $call[0] === 'addDriver' && is_string($call[1][1] ?? null) && str_starts_with($call[1][1], self::ENTITY_NAMESPACE);
                if ($isBundleDriver && $manager !== $name) {
                    continue;
                }

                $maps    = $maps || $isBundleDriver;
                $calls[] = $call;
            }

            $chain->setMethodCalls($calls);

            if ($manager === $name && !$maps) {
                throw new InvalidConfigurationException(sprintf('The Doctrine entity manager "%s" (nowo_contact_form.doctrine.entity_manager) does not map %s.', $name, self::ENTITY_NAMESPACE));
            }
        }
    }
}
