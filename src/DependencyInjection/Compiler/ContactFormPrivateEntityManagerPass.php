<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\DependencyInjection\Compiler;

use Nowo\ContactFormBundle\DependencyInjection\NowoContactFormExtension;
use Nowo\TransactionDoctrine\PrivateManager\DependencyInjection\PrivateEntityManagerPass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function is_string;

/**
 * 1.13 (`doctrine.private_entity_manager: true`): resolves the application's manager the private one is created on —
 * `doctrine.entity_manager`, else the application's default entity manager (`doctrine.default_entity_manager`, so a
 * default manager not named "default" works without configuration) — then runs transaction-doctrine's
 * {@see PrivateEntityManagerPass}, which checks that manager exists and tags the bundle's
 * PrivateEntityManagerMiddleware `doctrine.middleware` for its connection only.
 *
 * Runs before DoctrineBundle's MiddlewaresPass (priority 0). Does nothing in host mode.
 */
final class ContactFormPrivateEntityManagerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(NowoContactFormExtension::PRIVATE_MIDDLEWARE_SERVICE)) {
            return;
        }

        $source = $container->hasParameter(NowoContactFormExtension::PRIVATE_SOURCE_MANAGER_PARAMETER)
            ? $container->getParameter(NowoContactFormExtension::PRIVATE_SOURCE_MANAGER_PARAMETER)
            : null;
        if (!is_string($source) || $source === '') {
            $default = $container->hasParameter('doctrine.default_entity_manager') ? $container->getParameter('doctrine.default_entity_manager') : null;
            $source  = is_string($default) && $default !== '' ? $default : 'default';
            $container->setParameter(NowoContactFormExtension::PRIVATE_SOURCE_MANAGER_PARAMETER, $source);
        }

        (new PrivateEntityManagerPass(
            NowoContactFormExtension::PRIVATE_MIDDLEWARE_SERVICE,
            NowoContactFormExtension::PRIVATE_SOURCE_MANAGER_PARAMETER,
            'nowo_contact_form.doctrine.entity_manager',
        ))->process($container);
    }
}
