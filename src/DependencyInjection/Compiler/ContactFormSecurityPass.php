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
 * Enforces SecurityBundle for admin UI unless allow_unauthenticated is true (REQ-UI-002) and always
 * registers the admin access subscriber (404 on admin routes when the web UI is disabled).
 */
final class ContactFormSecurityPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(Configuration::ALIAS . '.web_ui.enabled')) {
            return;
        }

        $webUiEnabled         = (bool) $container->getParameter(Configuration::ALIAS . '.web_ui.enabled');
        $allowUnauthenticated = (bool) $container->getParameter(Configuration::ALIAS . '.security.allow_unauthenticated');

        if ($webUiEnabled && !$allowUnauthenticated && !$container->has('security.authorization_checker')) {
            throw new InvalidConfigurationException('nowo_contact_form admin UI requires symfony/security-bundle (security.authorization_checker), or set nowo_contact_form.security.allow_unauthenticated: true (dev/demo only — never in production).');
        }

        // services.yaml excludes the class, which leaves an abstract `container.excluded` placeholder:
        // only a host-defined subscriber may short-circuit the registration.
        if ($container->hasDefinition(ContactFormAdminAccessSubscriber::class)
            && !$container->getDefinition(ContactFormAdminAccessSubscriber::class)->hasTag('container.excluded')) {
            return;
        }

        // Always registered: admin routes may be imported even when the web UI is disabled (they then 404).
        // Empty access_roles is fail-closed via the checker; allow_unauthenticated skips the check (null checker).
        $accessChecker = $webUiEnabled && !$allowUnauthenticated
            ? new Reference(ContactFormAccessCheckerInterface::class)
            : null;

        $container->register(ContactFormAdminAccessSubscriber::class, ContactFormAdminAccessSubscriber::class)
            ->setArgument('$accessChecker', $accessChecker)
            ->setArgument('$webUiEnabled', $webUiEnabled)
            ->addTag('kernel.event_subscriber');
    }
}
