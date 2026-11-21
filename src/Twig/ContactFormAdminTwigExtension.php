<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Twig;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

use function in_array;
use function is_array;
use function is_string;

/**
 * Exposes admin helpers (enabled / default content locales) to Twig templates. 2.0 removed the 1.x Web UI globals
 * `nowo_contact_form_layout_template`, `nowo_contact_form_css_framework` and `nowo_contact_form_icon_set` (deprecated
 * since 1.6): read `nowo_ui_layout` and `nowo_ui_kit.*` instead.
 */
final class ContactFormAdminTwigExtension extends AbstractExtension
{
    /**
     * @param string|null $layoutTemplate deprecated since 2.0, ignored (it fed a removed global); removed in 3.0
     * @param string|null $cssFramework deprecated since 2.0, ignored (it fed a removed global); removed in 3.0
     * @param string|null $iconSet deprecated since 2.0, ignored (it fed a removed global); removed in 3.0
     */
    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
        ?string $layoutTemplate = null,
        ?string $cssFramework = null,
        ?string $iconSet = null,
    ) {
        unset($layoutTemplate, $cssFramework, $iconSet);
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('contact_form_admin_locales', $this->getEnabledLocales(...)),
            new TwigFunction('contact_form_admin_default_locale', $this->getDefaultLocale(...)),
        ];
    }

    /**
     * @return list<string>
     */
    public function getEnabledLocales(): array
    {
        $locales = $this->parameterBag->get('kernel.enabled_locales');

        if (!is_array($locales) || $locales === []) {
            return ['en'];
        }

        $locales = array_values($locales);
        sort($locales);

        return $locales;
    }

    /**
     * Default content locale for the translation tabs: kernel.default_locale when enabled, else the first enabled one.
     */
    public function getDefaultLocale(): string
    {
        $locales = $this->getEnabledLocales();
        $default = $this->parameterBag->has('kernel.default_locale') ? $this->parameterBag->get('kernel.default_locale') : null;

        return is_string($default) && in_array($default, $locales, true) ? $default : $locales[0];
    }
}
