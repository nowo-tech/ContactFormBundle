<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Form;

use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\Enum\ContactPhoneWidget;
use Nowo\ContactFormBundle\Phone\ContactFormFieldPhoneOptions;
use Nowo\ContactFormBundle\Service\ContactPhoneInputAvailability;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

use function count;

/**
 * Wizard step for phone field widget and prefix configuration.
 */
class ContactFormFieldPhonePrefixesStepType extends AbstractType
{
    use ContactFormFieldWizardStepTrait;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly ContactPhoneInputAvailability $phoneInputAvailability,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $prefix = $options['label_prefix'];

        $this->addPreservedDefinitionFields($builder);
        $this->addPreservedDefinitionFieldsPreSubmitListener($builder);

        $widgetChoices = [
            $prefix . 'phone_widget_symfony' => ContactPhoneWidget::Symfony->value,
        ];

        if ($this->phoneInputAvailability->isAvailable()) {
            $widgetChoices[$prefix . 'phone_widget_phone_input'] = ContactPhoneWidget::PhoneInput->value;
        }

        $builder
            ->add('phoneWidget', ChoiceType::class, [
                'label'              => $prefix . 'phone_widget',
                'help'               => $prefix . 'phone_widget_help',
                'choices'            => $widgetChoices,
                'required'           => true,
                'mapped'             => false,
                'translation_domain' => $options['translation_domain'],
            ])
            ->add('phonePrefixesLines', TextareaType::class, [
                'label'              => $prefix . 'phone_prefixes',
                'help'               => $prefix . 'phone_prefixes_help',
                'required'           => false,
                'mapped'             => false,
                'translation_domain' => $options['translation_domain'],
            ])
            ->add('phoneDefaultCountry', TextType::class, [
                'label'              => $prefix . 'phone_default_country',
                'help'               => $prefix . 'phone_default_country_help',
                'required'           => false,
                'mapped'             => false,
                'translation_domain' => $options['translation_domain'],
            ])
            ->add('phoneAllowedCountriesLines', TextareaType::class, [
                'label'              => $prefix . 'phone_allowed_countries',
                'help'               => $prefix . 'phone_allowed_countries_help',
                'required'           => false,
                'mapped'             => false,
                'translation_domain' => $options['translation_domain'],
            ]);

        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event): void {
            $field = $this->resolveField($event);

            if (!$field instanceof ContactFormField) {
                return;
            }

            $phoneOptions = ContactFormFieldPhoneOptions::fromField($field);
            $form         = $event->getForm();

            if ($form->has('phoneWidget')) {
                $form->get('phoneWidget')->setData($phoneOptions->widget->value);
            }

            if ($form->has('phonePrefixesLines')) {
                $form->get('phonePrefixesLines')->setData(implode("\n", $phoneOptions->prefixes));
            }

            if ($form->has('phoneDefaultCountry')) {
                $form->get('phoneDefaultCountry')->setData($phoneOptions->defaultCountry);
            }

            if ($form->has('phoneAllowedCountriesLines')) {
                $form->get('phoneAllowedCountriesLines')->setData(
                    $phoneOptions->allowedCountries !== null ? implode("\n", $phoneOptions->allowedCountries) : '',
                );
            }
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $field = $this->resolveField($event);

            if (!$field instanceof ContactFormField || $field->getType() !== ContactFieldType::Phone) {
                return;
            }

            $form = $event->getForm();

            if (!$form->has('phoneWidget')) {
                return;
            }

            $widget = ContactPhoneWidget::tryFrom((string) $form->get('phoneWidget')->getData()) ?? ContactPhoneWidget::Symfony;

            if ($widget === ContactPhoneWidget::PhoneInput && !$this->phoneInputAvailability->isAvailable()) {
                $form->get('phoneWidget')->addError(new FormError(
                    $this->translator->trans('nowo_contact_form.admin.field.fields.phone_widget_unavailable', [], 'NowoContactFormBundle'),
                ));

                return;
            }

            $prefixes = $form->has('phonePrefixesLines')
                ? $this->parseLines((string) $form->get('phonePrefixesLines')->getData())
                : [];

            if ($widget === ContactPhoneWidget::Symfony && !$this->validatePrefixes($form, $prefixes)) {
                return;
            }

            $defaultCountry = $form->has('phoneDefaultCountry')
                ? strtoupper(trim((string) $form->get('phoneDefaultCountry')->getData()))
                : 'ES';

            if ($widget === ContactPhoneWidget::PhoneInput && ($defaultCountry === '' || preg_match('/^[A-Z]{2}$/', $defaultCountry) !== 1)) {
                $form->get('phoneDefaultCountry')->addError(new FormError(
                    $this->translator->trans('nowo_contact_form.admin.field.fields.phone_default_country_invalid', [], 'NowoContactFormBundle'),
                ));

                return;
            }

            $allowedCountries = $form->has('phoneAllowedCountriesLines')
                ? $this->parseLines((string) $form->get('phoneAllowedCountriesLines')->getData())
                : [];

            foreach ($allowedCountries as $index => $iso) {
                $allowedCountries[$index] = strtoupper($iso);
                if (preg_match('/^[A-Z]{2}$/', $allowedCountries[$index]) !== 1) {
                    $form->get('phoneAllowedCountriesLines')->addError(new FormError(
                        $this->translator->trans('nowo_contact_form.admin.field.fields.phone_allowed_countries_invalid', [], 'NowoContactFormBundle'),
                    ));

                    return;
                }
            }

            if (count($allowedCountries) !== count(array_unique($allowedCountries))) {
                $form->get('phoneAllowedCountriesLines')->addError(new FormError(
                    $this->translator->trans('nowo_contact_form.admin.field.fields.phone_allowed_countries_duplicate', [], 'NowoContactFormBundle'),
                ));

                return;
            }

            $field->setOptions(
                (new ContactFormFieldPhoneOptions(
                    widget: $widget,
                    prefixes: $prefixes,
                    defaultCountry: $defaultCountry !== '' ? $defaultCountry : 'ES',
                    allowedCountries: $allowedCountries === [] ? null : $allowedCountries,
                ))->toStorage(),
            );
        });
    }

    /**
     * @param list<string> $values
     */
    private function validatePrefixes(FormInterface $form, array $values): bool
    {
        if (!$form->has('phonePrefixesLines')) {
            return true;
        }

        $linesField = $form->get('phonePrefixesLines');
        $valid      = true;

        foreach ($values as $value) {
            if (preg_match('/^\+\d{1,4}$/', $value) === 1) {
                continue;
            }

            $linesField->addError(new FormError(
                $this->translator->trans('nowo_contact_form.admin.field.fields.phone_prefixes_invalid', [], 'NowoContactFormBundle'),
            ));
            $valid = false;
        }

        if (count($values) !== count(array_unique($values))) {
            $linesField->addError(new FormError(
                $this->translator->trans('nowo_contact_form.admin.field.fields.phone_prefixes_duplicate', [], 'NowoContactFormBundle'),
            ));
            $valid = false;
        }

        return $valid;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'inherit_data'       => true,
            'clear_missing'      => false,
            'translation_domain' => 'NowoContactFormBundle',
            'label_prefix'       => 'nowo_contact_form.admin.field.fields.',
        ]);
    }
}
