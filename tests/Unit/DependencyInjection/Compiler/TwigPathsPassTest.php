<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\ContactFormBundle\DependencyInjection\Compiler\TwigPathsPass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

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

        $calls = $loader->getMethodCalls();
        self::assertNotEmpty($calls);
        self::assertSame('addPath', $calls[0][0]);
        self::assertSame('NowoContactFormBundle', $calls[0][1][1]);
        self::assertStringContainsString('Resources/views', $calls[0][1][0]);
    }

    public function testProcessSkipsWhenTwigLoaderMissing(): void
    {
        $container = new ContainerBuilder();
        $pass      = new TwigPathsPass();

        $pass->process($container);

        self::assertFalse($container->hasDefinition('twig.loader.native'));
    }

    public function testProcessUsesAliasWhenDefined(): void
    {
        $container = new ContainerBuilder();
        $loader    = new Definition();
        $container->setDefinition('twig.loader.native_filesystem', $loader);
        $container->setAlias('twig.loader.native', 'twig.loader.native_filesystem');

        $pass = new TwigPathsPass();
        $pass->process($container);

        self::assertSame('addPath', $loader->getMethodCalls()[0][0]);
    }
}
