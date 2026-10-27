<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\ContactFormBundle\DependencyInjection\Configuration;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fails closed when the admin UI is enabled without CSRF protection (REQ-SEC-005).
 *
 * The admin mutations (contact form / field wizard forms) rely on Symfony's form CSRF extension and the deletes on
 * the CSRF token manager. A host that disables `framework.form.csrf_protection` (or has no token manager) would
 * silently turn those checks off, so the container refuses to compile instead.
 */
final class ContactFormCsrfProtectionPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $parameter = Configuration::ALIAS . '.web_ui.enabled';
        if (!$container->hasParameter($parameter) || !$container->getParameter($parameter)) {
            return;
        }

        $formCsrfDisabled = $container->hasParameter('form.type_extension.csrf.enabled')
            && $container->getParameter('form.type_extension.csrf.enabled') === false;

        if (!$container->has('security.csrf.token_manager') || !$container->hasDefinition('form.type_extension.csrf') || $formCsrfDisabled) {
            throw new LogicException('NowoContactFormBundle: the admin UI (web_ui.enabled: true) requires CSRF protection. Enable framework.csrf_protection and framework.form.csrf_protection (symfony/security-csrf), or set nowo_contact_form.web_ui.enabled: false.');
        }
    }
}
