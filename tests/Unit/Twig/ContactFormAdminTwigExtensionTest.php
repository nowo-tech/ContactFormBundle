<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Twig;

use Nowo\ContactFormBundle\Twig\ContactFormAdminTwigExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[CoversClass(ContactFormAdminTwigExtension::class)]
final class ContactFormAdminTwigExtensionTest extends TestCase
{
    public function testGlobalsExposeLayoutAndCssFramework(): void
    {
        $params = $this->createMock(ParameterBagInterface::class);
        $params->method('get')->with('kernel.enabled_locales')->willReturn(['es', 'en']);

        $extension = new ContactFormAdminTwigExtension(
            $params,
            '@App/layout.html.twig',
            'tailwind',
            'svg_inline',
        );

        self::assertSame(['en', 'es'], $extension->getEnabledLocales());
        self::assertSame([
            ContactFormAdminTwigExtension::GLOBAL_LAYOUT_TEMPLATE => '@App/layout.html.twig',
            ContactFormAdminTwigExtension::GLOBAL_CSS_FRAMEWORK   => 'tailwind',
            ContactFormAdminTwigExtension::GLOBAL_ICON_SET        => 'svg_inline',
        ], $extension->getGlobals());
    }

    public function testEnabledLocalesFallbackToEnglish(): void
    {
        $params = $this->createMock(ParameterBagInterface::class);
        $params->method('get')->willReturn([]);

        $extension = new ContactFormAdminTwigExtension($params);

        self::assertSame(['en'], $extension->getEnabledLocales());
    }
}
