<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormSecurityPass;
use Nowo\ContactFormBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\ContactFormBundle\DependencyInjection\NowoContactFormExtension;
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

        $entityDir = __DIR__ . '/Entity';
        if (is_dir($entityDir)) {
            $container->addCompilerPass(DoctrineOrmMappingsPass::createAttributeMappingDriver(
                ['Nowo\\ContactFormBundle\\Entity'],
                [$entityDir],
            ));
        }
    }

    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            $this->extension = new NowoContactFormExtension();
        }

        $extension = $this->extension;

        /* @phpstan-ignore identical.alwaysFalse */
        return $extension === false ? null : $extension;
    }
}
