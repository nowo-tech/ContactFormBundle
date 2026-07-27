<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\DependencyInjection\Compiler;

use Nowo\ContactFormBundle\DependencyInjection\Configuration;
use Nowo\ContactFormBundle\EventSubscriber\ContactFormAdminAccessSubscriber;
use Nowo\ContactFormBundle\Security\ContactFormAccessCheckerInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Enforces SecurityBundle for admin UI unless allow_unauthenticated is true (REQ-UI-002).
 */
final class ContactFormSecurityPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(Configuration::ALIAS . '.web_ui.enabled')) {
            return;
        }
        if (!(bool) $container->getParameter(Configuration::ALIAS . '.web_ui.enabled')) {
            return;
        }

        $allowUnauthenticated = (bool) $container->getParameter(Configuration::ALIAS . '.security.allow_unauthenticated');
        $hasSecurity          = $container->has('security.authorization_checker');

        if (!$hasSecurity && !$allowUnauthenticated) {
            throw new InvalidConfigurationException('nowo_contact_form admin UI requires symfony/security-bundle (security.authorization_checker), or set nowo_contact_form.security.allow_unauthenticated: true (dev/demo only — never in production).');
        }

        if ($allowUnauthenticated) {
            return;
        }

        /** @var list<string> $accessRoles */
        $accessRoles = $container->getParameter(Configuration::ALIAS . '.security.access_roles');
        if ($accessRoles === []) {
            return;
        }

        if ($container->hasDefinition(ContactFormAdminAccessSubscriber::class)) {
            return;
        }

        $container->register(ContactFormAdminAccessSubscriber::class)
            ->setArgument('$accessChecker', new Reference(ContactFormAccessCheckerInterface::class))
            ->addTag('kernel.event_subscriber');
    }
}
