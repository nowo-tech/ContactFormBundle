<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

use function is_array;
use function is_string;

/**
 * Content locales of the admin (one translation per locale): `kernel.enabled_locales`, or `en` when the host enables
 * none. Configuration only: safe in worker mode.
 */
final readonly class ContactFormEnabledLocales
{
    public function __construct(
        private ParameterBagInterface $parameterBag,
    ) {
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        $locales = $this->parameterBag->has('kernel.enabled_locales') ? $this->parameterBag->get('kernel.enabled_locales') : null;
        $enabled = [];

        foreach (is_array($locales) ? $locales : [] as $locale) {
            if (is_string($locale) && $locale !== '') {
                $enabled[] = $locale;
            }
        }

        return $enabled === [] ? ['en'] : $enabled;
    }
}
