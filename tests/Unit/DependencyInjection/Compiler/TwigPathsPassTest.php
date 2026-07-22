<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\ContactFormBundle\DependencyInjection\Compiler\TwigPathsPass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

use function dirname;

#[CoversClass(TwigPathsPass::class)]
final class TwigPathsPassTest extends TestCase
{
    public function testProcessRegistersBundleViewsPath(): void
    {
        $container = new ContainerBuilder();
        $loader    = new Definition();
        $container->setDefinition('twig.loader.native', $loader);

        $pass = new TwigPathsPass();
        $pass->process($container);

        self::assertSame(
            [['addPath', [dirname(__DIR__, 4) . '/src/Resources/views', 'NowoContactFormBundle']]],
            $loader->getMethodCalls(),
        );
    }

    public function testProcessPrependsOverridePathWhenPresent(): void
    {
        $container  = new ContainerBuilder();
        $loader     = new Definition();
        $projectDir = sys_get_temp_dir() . '/contact-form-twig-paths-' . bin2hex(random_bytes(4));
        mkdir($projectDir . '/templates/bundles/NowoContactFormBundle', 0777, true);

        $container->setDefinition('twig.loader.native', $loader);
        $container->setParameter('kernel.project_dir', $projectDir);

        try {
            (new TwigPathsPass())->process($container);
        } finally {
            rmdir($projectDir . '/templates/bundles/NowoContactFormBundle');
            rmdir($projectDir . '/templates/bundles');
            rmdir($projectDir . '/templates');
            rmdir($projectDir);
        }

        self::assertSame(
            [
                ['prependPath', [$projectDir . '/templates/bundles/NowoContactFormBundle', 'NowoContactFormBundle']],
                ['addPath', [dirname(__DIR__, 4) . '/src/Resources/views', 'NowoContactFormBundle']],
            ],
            $loader->getMethodCalls(),
        );
    }

    public function testProcessSkipsWhenTwigLoaderMissing(): void
    {
        $container = new ContainerBuilder();

        (new TwigPathsPass())->process($container);

        self::assertFalse($container->hasDefinition('twig.loader.native'));
    }

    public function testProcessUsesAliasWhenDefined(): void
    {
        $container = new ContainerBuilder();
        $loader    = new Definition();
        $container->setDefinition('twig.loader.native_filesystem', $loader);
        $container->setAlias('twig.loader.native', new Alias('twig.loader.native_filesystem'));

        (new TwigPathsPass())->process($container);

        self::assertSame('addPath', $loader->getMethodCalls()[0][0]);
    }
}
