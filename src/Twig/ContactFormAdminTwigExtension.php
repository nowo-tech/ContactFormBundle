<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Twig;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

use function is_array;

/**
 * Exposes admin helpers and Web UI globals to Twig templates.
 */
final class ContactFormAdminTwigExtension extends AbstractExtension implements GlobalsInterface
{
    public const GLOBAL_LAYOUT_TEMPLATE = 'nowo_contact_form_layout_template';

    public const GLOBAL_CSS_FRAMEWORK = 'nowo_contact_form_css_framework';

    public const GLOBAL_ICON_SET = 'nowo_contact_form_icon_set';

    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
        private readonly string $layoutTemplate = '@NowoContactFormBundle/admin/layout.html.twig',
        private readonly string $cssFramework = 'bootstrap5',
        private readonly string $iconSet = 'bootstrap-icons',
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('contact_form_admin_locales', $this->getEnabledLocales(...)),
        ];
    }

    /** @return array<string, mixed> */
    public function getGlobals(): array
    {
        return [
            self::GLOBAL_LAYOUT_TEMPLATE => $this->layoutTemplate,
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
}
