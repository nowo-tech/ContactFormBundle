<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Twig;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

use function in_array;
use function is_array;
use function is_string;

/**
 * Exposes admin helpers (enabled / default content locales) and the deprecated 1.x Web UI globals to Twig templates.
 */
final class ContactFormAdminTwigExtension extends AbstractExtension implements GlobalsInterface
{
    /** @deprecated since 1.6 (REQ-UI-003: the pages resolve their layout through the view models) */
    public const GLOBAL_LAYOUT_TEMPLATE = 'nowo_contact_form_layout_template';

    /** @deprecated since 1.6 (read nowo_ui_kit.css_framework) */
    public const GLOBAL_CSS_FRAMEWORK = 'nowo_contact_form_css_framework';

    /** @deprecated since 1.6 (read nowo_ui_kit.icon_set) */
    public const GLOBAL_ICON_SET = 'nowo_contact_form_icon_set';

    /** Value of the deprecated layout global when web_ui.layout_template is null. */
    public const DEFAULT_LAYOUT_TEMPLATE = '@NowoUiKit/layout/admin.html.twig';

    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
        private readonly ?string $layoutTemplate = null,
        private readonly ?string $cssFramework = null,
        private readonly ?string $iconSet = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('contact_form_admin_locales', $this->getEnabledLocales(...)),
            new TwigFunction('contact_form_admin_default_locale', $this->getDefaultLocale(...)),
        ];
    }

    /**
     * Deprecated since 1.6, removed in 2.0 (REQ-UI-003: bundles must not add layout globals; the bundle pages do not
     * read them): `nowo_contact_form_layout_template` (web_ui.layout_template, else the UiKit admin layout),
     * `nowo_contact_form_css_framework` / `nowo_contact_form_icon_set` (null unless the deprecated web_ui keys are set).
     *
     * @return array<string, mixed>
     */
    public function getGlobals(): array
    {
        return [
            self::GLOBAL_LAYOUT_TEMPLATE => $this->layoutTemplate ?? self::DEFAULT_LAYOUT_TEMPLATE,
            self::GLOBAL_CSS_FRAMEWORK   => $this->cssFramework,
            self::GLOBAL_ICON_SET        => $this->iconSet,
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
