<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormCsrfProtectionPass;
use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormEntityManagerPass;
use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormFileUploaderPass;
use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormOptionalServicesPass;
use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormSecurityPass;
use Nowo\ContactFormBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\ContactFormBundle\DependencyInjection\NowoContactFormExtension;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony bundle for configurable multilingual contact forms with GDPR support.
 */
final class NowoContactFormBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new TwigPathsPass());
        $container->addCompilerPass(new ContactFormSecurityPass());
        $container->addCompilerPass(new ContactFormCsrfProtectionPass());
        $container->addCompilerPass(new ContactFormOptionalServicesPass());
        // 1.8: file_upload.service and the file port the bundle's consumers use (host definitions are loaded here).
        $container->addCompilerPass(new ContactFormFileUploaderPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);

        // Before the mapping pass: an unknown doctrine.entity_manager fails with a clear message.
        $container->addCompilerPass(new ContactFormEntityManagerPass(true), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);

        $entityDir = __DIR__ . '/Entity';
        if (is_dir($entityDir)) {
            $container->addCompilerPass(DoctrineOrmMappingsPass::createAttributeMappingDriver(
                ['Nowo\\ContactFormBundle\\Entity'],
                [$entityDir],
                // 1.7: on doctrine.entity_manager when set, else the default entity manager (unchanged).
                [NowoContactFormExtension::ENTITY_MANAGER_PARAMETER],
            ));
        }

        // After the mapping pass above (and DoctrineBundle's auto_mapping): keep the entities on one manager.
        $container->addCompilerPass(new ContactFormEntityManagerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -10);
    }

    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            // @igor-ignore - Boot-time Symfony Bundle extension cache (not request state).
            $this->extension = new NowoContactFormExtension();
        }

        $extension = $this->extension;

        /* @phpstan-ignore identical.alwaysFalse */
        return $extension === false ? null : $extension;
    }
}
