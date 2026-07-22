<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactFormFieldTranslation;
use Nowo\ContactFormBundle\Enum\ContactFieldType;

use function array_is_list;
use function is_array;

/**
 * Keeps stable select option values on the field while labels live in translations.
 */
final class ContactFormFieldSelectOptionsSynchronizer
{
    public function synchronize(ContactFormField $field): void
    {
        if ($field->getType() !== ContactFieldType::Select) {
            $field->setOptions(null);

            foreach ($field->getTranslations() as $translation) {
                $translation->setSelectOptions(null);
            }

            return;
        }

        $referenceLabels = $this->resolveReferenceLabels($field);

        if ($referenceLabels === []) {
            $field->setOptions(null);

            return;
        }

        $field->setOptions($this->resolveOptionValues($field, $referenceLabels));

        foreach ($field->getTranslations() as $translation) {
            if ($translation->getSelectOptions() === null) {
                $translation->setSelectOptions($referenceLabels);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function resolveReferenceLabels(ContactFormField $field): array
    {
        $preferredLocales = ['en', 'es', 'fr', 'de', 'it', 'pt'];

        foreach ($preferredLocales as $locale) {
            $labels = $this->extractLabels($field->findTranslation($locale));

            if ($labels !== []) {
                return $labels;
            }
        }

        foreach ($field->getTranslations() as $translation) {
            $labels = $this->extractLabels($translation);

            if ($labels !== []) {
                return $labels;
            }
        }

        $options = $field->getOptions();

        return is_array($options) && array_is_list($options) ? $options : [];
    }

    /**
     * @return list<string>
     */
    private function extractLabels(?ContactFormFieldTranslation $translation): array
    {
        if (!$translation instanceof ContactFormFieldTranslation) {
            return [];
        }

        $labels = $translation->getSelectOptions();

        return $labels ?? [];
    }

    /**
     * @param list<string> $referenceLabels
     *
     * @return list<string>
     */
    private function resolveOptionValues(ContactFormField $field, array $referenceLabels): array
    {
        $existingOptions = $field->getOptions();
        $existing        = is_array($existingOptions) && array_is_list($existingOptions) ? $existingOptions : [];
        $values          = [];

        foreach ($referenceLabels as $index => $label) {
            if (isset($existing[$index]) && $existing[$index] !== '') {
                $values[] = $existing[$index];

                continue;
            }

            $values[] = $this->slugify($label, $index);
        }

        return $values;
    }

    private function slugify(string $label, int $index): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '_', $label), '_'));

        if ($slug === '') {
            return 'option_' . ($index + 1);
        }

        return $slug;
    }
}
