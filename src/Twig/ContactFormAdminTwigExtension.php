<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Twig;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

use function is_array;

/**
 * Exposes enabled locales to admin templates.
 */
final class ContactFormAdminTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('contact_form_admin_locales', $this->getEnabledLocales(...)),
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
