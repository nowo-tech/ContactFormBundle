<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\DependencyInjection\Compiler;

use Nowo\ContactFormBundle\DependencyInjection\NowoContactFormExtension;
use Nowo\ContactFormBundle\Doctrine\ContactFormHostFlushReporter;
use Nowo\TransactionDoctrine\PrivateManager\DependencyInjection\PrivateEntityManagerPass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function is_string;
use function trigger_deprecation;

/**
 * 1.13 (REQ-ARCH-001 rule 6), once the application's Doctrine managers are known:
 *
 * - host mode: `doctrine.private_entity_manager` unset or false, and `doctrine.entity_manager` unset or naming the
 *   application's default entity manager (`doctrine.default_entity_manager`): the bundle flushes the application's
 *   manager. The deprecation notice is triggered (one text for unset, one for false) and
 *   {@see ContactFormHostFlushReporter} is enabled. A dedicated manager (any other name) is neither;
 * - `private_entity_manager: true`: resolves the application's manager the private one is created on —
 *   `doctrine.entity_manager`, else the default entity manager (so a default manager not named "default" works
 *   without configuration) — then runs transaction-doctrine's {@see PrivateEntityManagerPass}, which checks that
 *   manager exists and tags the bundle's PrivateEntityManagerMiddleware `doctrine.middleware` for its connection only.
 *
 * Runs before DoctrineBundle's MiddlewaresPass (priority 0).
 */
final class ContactFormPrivateEntityManagerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $default = $container->hasParameter('doctrine.default_entity_manager') ? $container->getParameter('doctrine.default_entity_manager') : null;
        $default = is_string($default) && $default !== '' ? $default : 'default';
        $source  = $container->hasParameter(NowoContactFormExtension::PRIVATE_SOURCE_MANAGER_PARAMETER)
            ? $container->getParameter(NowoContactFormExtension::PRIVATE_SOURCE_MANAGER_PARAMETER)
            : null;
        $named = is_string($source) && $source !== '' ? $source : null;

        $this->decideHostMode($container, $named === null || $named === $default);

        if (!$container->hasDefinition(NowoContactFormExtension::PRIVATE_MIDDLEWARE_SERVICE)) {
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
     * @param bool $onApplicationManager the bundle entities live on the application's default manager
     */
    private function decideHostMode(ContainerBuilder $container, bool $onApplicationManager): void
    {
        if (!$container->hasParameter(NowoContactFormExtension::PRIVATE_ENTITY_MANAGER_SETTING_PARAMETER)) {
            return;
        }

        $setting  = $container->getParameter(NowoContactFormExtension::PRIVATE_ENTITY_MANAGER_SETTING_PARAMETER);
        $hostMode = $setting !== true && $onApplicationManager;
        if ($container->hasDefinition(ContactFormHostFlushReporter::class)) {
            $container->getDefinition(ContactFormHostFlushReporter::class)->replaceArgument(0, $hostMode);
        }

        if ($hostMode) {
            trigger_deprecation('nowo-tech/contact-form-bundle', '1.13', $setting === false ? NowoContactFormExtension::PRIVATE_ENTITY_MANAGER_FALSE_DEPRECATION : NowoContactFormExtension::PRIVATE_ENTITY_MANAGER_UNSET_DEPRECATION);
        }
    }
}
