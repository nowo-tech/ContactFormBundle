<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Support;

use Nowo\FormKitBundle\Form\Constraint\ConstraintDefinitionFactory;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

use function call_user_func;
use function dirname;
use function method_exists;

/**
 * Injects a minimal FormOptionsMerger (profile {@code contact_form}, as seeded by the extension) into Form Kit form
 * types under test.
 */
final class FormKitTestSupport
{
    /**
     * @param TranslatorInterface|null $translator with a catalogue (TranslatorBagInterface): convention keys are only
     *                                             applied when translated, as in production
     */
    public static function merger(?TranslatorInterface $translator = null): FormOptionsMerger
    {
        $profile = [
            'translation_domain'              => 'NowoContactFormBundle',
            'convention_requires_translation' => true,
            'defaults'                        => [
                'attr'     => ['class' => 'nowo-ui-input'],
                'row_attr' => [],
            ],
            'field_types' => [],
        ];

        return new FormOptionsMerger(
            [
                'contact_form' => $profile,
                'default'      => $profile,
            ],
            'contact_form',
            new ConstraintDefinitionFactory(),
            translator: $translator,
        );
    }

    /**
     * @template T of object
     *
     * @param T $formType
     *
     * @return T
     */
    public static function withMerger(object $formType, ?TranslatorInterface $translator = null): object
    {
        if (!method_exists($formType, 'setFormOptionsMerger')) {
            return $formType;
        }

        call_user_func([$formType, 'setFormOptionsMerger'], self::merger($translator));

        return $formType;
    }

    /**
     * Translator with the bundle catalogues (locales en / es), for the FormKit convention check.
     */
    public static function translator(string $locale = 'en'): Translator
    {
        $translator = new Translator($locale);
        $translator->setFallbackLocales(['en']);
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (['en', 'es'] as $catalogue) {
            $translator->addResource('yaml', dirname(__DIR__, 2) . '/src/Resources/translations/NowoContactFormBundle.' . $catalogue . '.yaml', $catalogue, 'NowoContactFormBundle');
        }

        return $translator;
    }
}
