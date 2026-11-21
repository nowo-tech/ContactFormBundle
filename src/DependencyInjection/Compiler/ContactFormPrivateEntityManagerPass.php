<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\DependencyInjection\Compiler;

use Nowo\ContactFormBundle\DependencyInjection\NowoContactFormExtension;
use Nowo\ContactFormBundle\EventSubscriber\ContactFormEntityManagerClearSubscriber;
use Nowo\ContactFormBundle\Service\ContactFormEntityManagerResolver;
use Nowo\TransactionDoctrine\PrivateManager\DependencyInjection\PrivateEntityManagerPass;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function is_string;
use function str_starts_with;

/**
 * REQ-ARCH-001 rule 6 (2.0), once the application's Doctrine managers are known. The bundle never flushes the
 * application's manager:
 *
 * - `doctrine.entity_manager` unset, or naming the application's default entity manager
 *   (`doctrine.default_entity_manager`): the private entity manager is created on that manager (so a default manager
 *   not named "default" works without configuration), and transaction-doctrine's {@see PrivateEntityManagerPass}
 *   checks the manager exists and tags the bundle's PrivateEntityManagerMiddleware `doctrine.middleware` for its
 *   connection only;
 * - `doctrine.entity_manager` naming a dedicated manager (the DDD option, unchanged): the bundle uses that manager
 *   directly, so the private manager the extension registered is removed again — unless the deprecated
 *   `private_entity_manager: true` is set, which keeps the 1.13 behaviour (the private manager on the dedicated one).
 *
 * The 1.x host mode (the bundle flushing the application's manager) and its deprecation notices are gone; the
 * configuration refuses `private_entity_manager: false`.
 *
 * Runs before DoctrineBundle's MiddlewaresPass (priority 0).
 */
final class ContactFormPrivateEntityManagerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(NowoContactFormExtension::PRIVATE_MIDDLEWARE_SERVICE)) {
            return;
        }

        $default = $container->hasParameter('doctrine.default_entity_manager') ? $container->getParameter('doctrine.default_entity_manager') : null;
        $default = is_string($default) && $default !== '' ? $default : 'default';
        $source  = $container->hasParameter(NowoContactFormExtension::PRIVATE_SOURCE_MANAGER_PARAMETER)
            ? $container->getParameter(NowoContactFormExtension::PRIVATE_SOURCE_MANAGER_PARAMETER)
            : null;
        $named   = is_string($source) && $source !== '' ? $source : null;
        $setting = $container->hasParameter(NowoContactFormExtension::PRIVATE_ENTITY_MANAGER_SETTING_PARAMETER)
            && $container->getParameter(NowoContactFormExtension::PRIVATE_ENTITY_MANAGER_SETTING_PARAMETER) === true;

        if ($named !== null && $named !== $default && !$setting) {
            $this->useDedicatedManager($container);

            return;
        }

        if ($named === null) {
            $container->setParameter(NowoContactFormExtension::PRIVATE_SOURCE_MANAGER_PARAMETER, $default);
        }

        (new PrivateEntityManagerPass(
            NowoContactFormExtension::PRIVATE_MIDDLEWARE_SERVICE,
            NowoContactFormExtension::PRIVATE_SOURCE_MANAGER_PARAMETER,
            'nowo_contact_form.doctrine.entity_manager',
        ))->process($container);
    }

    /**
     * A dedicated `doctrine.entity_manager`: the repositories and the resolver get the application's registry back
     * (autowired again: the `$registry` argument is dropped before autowiring runs) and the private manager's services
     * are removed. {@see ContactFormEntityManagerPass} hands the dedicated manager to the boundary subscriber.
     */
    private function useDedicatedManager(ContainerBuilder $container): void
    {
        $container->setParameter(NowoContactFormExtension::PRIVATE_ENTITY_MANAGER_PARAMETER, false);

        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass() ?? $id;
            if ($class !== ContactFormEntityManagerResolver::class && !str_starts_with($class, 'Nowo\\ContactFormBundle\\Repository\\')) {
                continue;
            }

            $arguments = $definition->getArguments();
            $registry  = $arguments['$registry'] ?? null;
            if ($registry instanceof Reference && (string) $registry === NowoContactFormExtension::PRIVATE_REGISTRY_SERVICE) {
                unset($arguments['$registry']);
                $definition->setArguments($arguments);
            }
        }

        if ($container->hasDefinition(ContactFormEntityManagerClearSubscriber::class)) {
            $container->getDefinition(ContactFormEntityManagerClearSubscriber::class)->setArgument('$resettables', new IteratorArgument([]));
        }

        $container->removeDefinition(NowoContactFormExtension::PRIVATE_MIDDLEWARE_SERVICE);
        $container->removeDefinition(NowoContactFormExtension::PRIVATE_REGISTRY_SERVICE);
        $container->removeDefinition(NowoContactFormExtension::PRIVATE_ENTITY_MANAGER_SERVICE);
    }
}
