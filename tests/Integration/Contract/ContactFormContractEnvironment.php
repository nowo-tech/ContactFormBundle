<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Contract;

use Nowo\ContactFormBundle\Twig\ContactFormAdminTwigExtension;
use Nowo\FormKitBundle\Twig\LocaleTabsTwigExtension;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Twig\Environment;

use function dirname;

/**
 * Twig environment of the UiKit page contract test for the bundle: bundle and FormKit views and catalogues, the bundle
 * Twig extension (enabled locales en / es), strict missing-translation rule.
 *
 * @phpstan-require-extends \Nowo\UiKitBundle\Test\PageContractTestCase
 */
trait ContactFormContractEnvironment
{
    protected static function templatePaths(): array
    {
        $root = dirname(__DIR__, 3);

        return [
            'NowoContactFormBundle' => $root . '/src/Resources/views',
            'NowoFormKitBundle'     => $root . '/vendor/nowo-tech/form-kit-bundle/src/Resources/views',
        ];
    }

    protected static function translationDirs(): array
    {
        $root = dirname(__DIR__, 3);

        return [$root . '/src/Resources/translations', $root . '/vendor/nowo-tech/form-kit-bundle/src/Resources/translations'];
    }

    protected static function templatesDocument(): string
    {
        return dirname(__DIR__, 3) . '/docs/TEMPLATES.md';
    }

    protected static function strictMissingTranslations(): bool
    {
        return true;
    }

    protected function configureTwig(Environment $twig): void
    {
        $twig->addExtension(new ContactFormAdminTwigExtension(new ParameterBag([
            'kernel.enabled_locales' => ['en', 'es'],
            'kernel.default_locale'  => 'en',
        ])));
        $twig->addExtension(new LocaleTabsTwigExtension());
    }
}
