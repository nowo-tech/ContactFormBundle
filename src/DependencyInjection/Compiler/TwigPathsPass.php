<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function dirname;

/**
 * Registers the bundle Twig views path so application overrides win.
 */
final class TwigPathsPass implements CompilerPassInterface
{
    private const TWIG_NAMESPACE = 'NowoContactFormBundle';

    public function process(ContainerBuilder $container): void
    {
        $loaderId = $this->getNativeLoaderServiceId($container);
        if ($loaderId === null) {
            return;
        }

        $viewsPath = dirname(__DIR__, 2) . '/Resources/views';

        $container->getDefinition($loaderId)
            ->addMethodCall('addPath', [$viewsPath, self::TWIG_NAMESPACE]);
    }

    private function getNativeLoaderServiceId(ContainerBuilder $container): ?string
    {
        if ($container->hasAlias('twig.loader.native')) {
            return (string) $container->getAlias('twig.loader.native');
        }
        if ($container->hasDefinition('twig.loader.native')) {
            return 'twig.loader.native';
        }
        if ($container->hasDefinition('twig.loader.native_filesystem')) {
            return 'twig.loader.native_filesystem';
        }

        return null;
    }
}
