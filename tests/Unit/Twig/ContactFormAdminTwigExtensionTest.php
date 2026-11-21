<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Twig;

use Nowo\ContactFormBundle\Twig\ContactFormAdminTwigExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

use function array_map;

#[CoversClass(ContactFormAdminTwigExtension::class)]
final class ContactFormAdminTwigExtensionTest extends TestCase
{
    public function testTheRemovedWebUiGlobalsAreGoneAndTheirArgumentsAreIgnored(): void
    {
        $params = $this->createMock(ParameterBagInterface::class);
        $params->method('get')->with('kernel.enabled_locales')->willReturn(['es', 'en']);

        // 2.0 removed the three 1.6 globals; the constructor still accepts (and ignores) their arguments.
        $extension = new ContactFormAdminTwigExtension(
            $params,
            '@App/layout.html.twig',
            'tailwind',
            'svg_inline',
        );

        self::assertSame(['en', 'es'], $extension->getEnabledLocales());
        self::assertFalse((new ReflectionClass($extension))->implementsInterface(GlobalsInterface::class));
        self::assertSame(['contact_form_admin_locales', 'contact_form_admin_default_locale'], array_map(static fn (TwigFunction $function): string => $function->getName(), $extension->getFunctions()));
    }

    public function testEnabledLocalesFallbackToEnglish(): void
    {
        $params = $this->createMock(ParameterBagInterface::class);
        $params->method('get')->willReturn([]);

        $extension = new ContactFormAdminTwigExtension($params);

        self::assertSame(['en'], $extension->getEnabledLocales());
    }

    public function testDefaultLocaleIsTheKernelDefaultWhenEnabledElseTheFirstEnabledLocale(): void
    {
        $extension = new ContactFormAdminTwigExtension(new ParameterBag(['kernel.enabled_locales' => ['es', 'en'], 'kernel.default_locale' => 'es']));
        self::assertSame('es', $extension->getDefaultLocale());

        $extension = new ContactFormAdminTwigExtension(new ParameterBag(['kernel.enabled_locales' => ['es', 'en'], 'kernel.default_locale' => 'fr']));
        self::assertSame('en', $extension->getDefaultLocale());

        $extension = new ContactFormAdminTwigExtension(new ParameterBag(['kernel.enabled_locales' => ['es', 'de']]));
        self::assertSame('de', $extension->getDefaultLocale());
    }
}
