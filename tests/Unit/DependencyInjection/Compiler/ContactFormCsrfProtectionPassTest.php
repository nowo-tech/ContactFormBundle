<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormCsrfProtectionPass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * P2: the admin UI refuses to compile without form CSRF protection and a CSRF token manager (fail closed).
 */
#[CoversClass(ContactFormCsrfProtectionPass::class)]
final class ContactFormCsrfProtectionPassTest extends TestCase
{
    public function testAcceptsTheAdminWithCsrfProtection(): void
    {
        $container = $this->container(true);
        (new ContactFormCsrfProtectionPass())->process($container);
        $this->addToAssertionCount(1);
    }

    public function testIgnoresADisabledAdminAndMissingParameters(): void
    {
        (new ContactFormCsrfProtectionPass())->process(new ContainerBuilder());
        $disabled = new ContainerBuilder();
        $disabled->setParameter('nowo_contact_form.web_ui.enabled', false);
        (new ContactFormCsrfProtectionPass())->process($disabled);
        $this->addToAssertionCount(1);
    }

    public function testFailsWithoutTokenManager(): void
    {
        $container = $this->container(true);
        $container->removeDefinition('security.csrf.token_manager');

        $this->expectException(LogicException::class);
        (new ContactFormCsrfProtectionPass())->process($container);
    }

    public function testFailsWithoutTheFormCsrfExtension(): void
    {
        $container = $this->container(true);
        $container->removeDefinition('form.type_extension.csrf');

        $this->expectException(LogicException::class);
        (new ContactFormCsrfProtectionPass())->process($container);
    }

    public function testFailsWhenFormCsrfIsDisabled(): void
    {
        $container = $this->container(true);
        $container->setParameter('form.type_extension.csrf.enabled', false);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires CSRF protection');
        (new ContactFormCsrfProtectionPass())->process($container);
    }

    private function container(bool $webUi): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_contact_form.web_ui.enabled', $webUi);
        $container->setParameter('form.type_extension.csrf.enabled', true);
        $container->register('security.csrf.token_manager');
        $container->register('form.type_extension.csrf');

        return $container;
    }
}
