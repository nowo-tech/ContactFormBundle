<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit;

use Nowo\ContactFormBundle\NowoContactFormBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(NowoContactFormBundle::class)]
final class NowoContactFormBundleTest extends TestCase
{
    public function testBuildRegistersCompilerPasses(): void
    {
        $container = new ContainerBuilder();
        $bundle    = new NowoContactFormBundle();
        $bundle->build($container);

        self::assertNotEmpty($container->getCompilerPassConfig()->getPasses());
    }

    public function testGetContainerExtension(): void
    {
        $bundle = new NowoContactFormBundle();

        self::assertSame('nowo_contact_form', $bundle->getContainerExtension()?->getAlias());
    }
}
