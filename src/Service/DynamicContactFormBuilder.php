<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactFormFieldTranslation;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\Enum\ContactPhoneWidget;
use Nowo\ContactFormBundle\Form\ContactPhoneType;
use Nowo\ContactFormBundle\Phone\ContactFormFieldPhoneOptions;
use Nowo\ContactFormBundle\Repository\ContactFormFieldRepository;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_is_list;
use function array_key_exists;
use function count;
use function is_array;
use function is_subclass_of;

/**
 * Builds dynamic Symfony forms from ContactForm entity definitions.
 *
 * Field chrome (attr / row_attr / …) comes from the FormKit profile {@see self::FORM_KIT_PROFILE}
 * so hosts can tune spacing and classes in {@code nowo_form_kit.profiles.contact_form} without
 * relying on Twig theme fallbacks.
 */
final readonly class DynamicContactFormBuilder
{
    public const FORM_KIT_PROFILE = 'contact_form';

    /** Stable FormKit {@code by_form} key for public dynamic fields. */
    public const FORM_KIT_FORM_NAME = 'public_contact';

    public function __construct(
        private FormFactoryInterface $formFactory,
        private ContactFormFieldRepository $fieldRepository,
        private TranslatorInterface $translator,
        private ContactFormRichTextSanitizer $richTextSanitizer,
        private ContactPhonePrefixResolver $phonePrefixResolver,
        private ContactPhoneInputOptionsResolver $phoneInputOptionsResolver,
        private ContactPhoneInputAvailability $phoneInputAvailability,
        private FormOptionsMerger $formOptionsMerger,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return FormInterface<mixed>
     */
    public function createForm(ContactForm $form, string $locale, array $options = []): FormInterface
    {
        $fields = $this->fieldRepository->findByFormOrdered($form);

        if ($this->requiresMultipart($fields)) {
            $options['attr']['enctype'] = 'multipart/form-data';
        }

        $builder = $this->formFactory->createBuilder(options: $options);

        foreach ($fields as $field) {
            $this->addField($builder, $field, $locale);
        }

        if ($form->isRequireConsent()) {
            $translation  = $form->getTranslationForLocale($locale);
            $consentLabel = $translation->getConsentLabel() ?? $this->translator->trans(
                'nowo_contact_form.public.consent_default_label',
                [],
                'NowoContactFormBundle',
                $locale,
            );

            $builder->add('gdpr_consent', CheckboxType::class, $this->withProfileOptions('gdpr_consent', CheckboxType::class, [
                'label'       => $this->richTextSanitizer->sanitize($consentLabel),
                'label_html'  => true,
                'mapped'      => false,
                'required'    => false,
                'help'        => false,
                'constraints' => [
                    new IsTrue(message: $this->translator->trans(
                        'nowo_contact_form.public.consent_required',
                        [],
                        'NowoContactFormBundle',
                        $locale,
                    )),
                ],
            ]));
        }

        return $builder->getForm();
    }

    /**
     * @param list<ContactFormField> $fields
     */
    private function requiresMultipart(array $fields): bool
    {
        foreach ($fields as $field) {
            if ($field->getType() === ContactFieldType::File) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param FormBuilderInterface<mixed> $builder
     */
    private function addField(FormBuilderInterface $builder, ContactFormField $field, string $locale): void
    {
        $translation = $field->getTranslationForLocale($locale);
        $constraints = $this->buildConstraints($field, $translation, $locale);

        $options = [
            'label'       => $translation->getLabel(),
            'required'    => $field->isRequired(),
            'constraints' => $constraints,
        ];

        if ($translation->getPlaceholder() !== null) {
            $options['attr']['placeholder'] = $translation->getPlaceholder();
        }

        if ($translation->getHelp() !== null) {
            $options['help'] = $translation->getHelp();
        }

        match ($field->getType()) {
            ContactFieldType::Email => $builder->add($field->getName(), EmailType::class, $this->withProfileOptions(
                $field->getName(),
                EmailType::class,
                array_merge($options, [
                    'constraints' => array_merge($constraints, [new Email()]),
                ]),
            )),
            ContactFieldType::Phone    => $this->addPhoneField($builder, $field, $options),
            ContactFieldType::Textarea => $builder->add($field->getName(), TextareaType::class, $this->withProfileOptions(
                $field->getName(),
                TextareaType::class,
                $options,
            )),
            ContactFieldType::Select => $builder->add($field->getName(), ChoiceType::class, $this->withProfileOptions(
                $field->getName(),
                ChoiceType::class,
                array_merge($options, [
                    'choices'     => $this->buildSelectChoices($field, $translation),
                    'placeholder' => $translation->getPlaceholder(),
                ]),
            )),
            ContactFieldType::Checkbox => $builder->add($field->getName(), CheckboxType::class, $this->withProfileOptions(
                $field->getName(),
                CheckboxType::class,
                [
                    'label'        => $translation->getLabel(),
                    'required'     => false,
                    'false_values' => [null, '', false],
                    'help'         => $translation->getHelp() ?? false,
                    'constraints'  => $constraints,
                ],
            )),
            ContactFieldType::Number => $builder->add($field->getName(), NumberType::class, $this->withProfileOptions(
                $field->getName(),
                NumberType::class,
                array_merge($options, [
                    'html5' => true,
                ]),
            )),
            ContactFieldType::Date => $builder->add($field->getName(), DateType::class, $this->withProfileOptions(
                $field->getName(),
                DateType::class,
                array_merge($options, [
                    'widget' => 'single_text',
                ]),
            )),
            ContactFieldType::Url => $builder->add($field->getName(), UrlType::class, $this->withProfileOptions(
                $field->getName(),
                UrlType::class,
                array_merge($options, [
                    'default_protocol' => 'https',
                    'constraints'      => array_merge($constraints, [new Url()]),
                ]),
            )),
            ContactFieldType::File => $builder->add($field->getName(), FileType::class, $this->withProfileOptions(
                $field->getName(),
                FileType::class,
                $options,
            )),
            ContactFieldType::Text => $builder->add($field->getName(), TextType::class, $this->withProfileOptions(
                $field->getName(),
                TextType::class,
                $options,
            )),
        };
    }

    /**
     * Merge FormKit {@code contact_form} profile defaults (attr / row_attr / field_types).
     * Labels, help and placeholders stay CMS strings — conventions are disabled.
     *
     * @param class-string $type
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function withProfileOptions(string $fieldName, string $type, array $options): array
    {
        if (!array_key_exists('translation_domain', $options)) {
            $options['translation_domain'] = false;
        }

        if (!array_key_exists('help', $options)) {
            $options['help'] = false;
        }

        $hasPlaceholder = array_key_exists('placeholder', $options)
            || (isset($options['attr']) && is_array($options['attr']) && array_key_exists('placeholder', $options['attr']));
        if (!$hasPlaceholder) {
            $options['placeholder'] = false;
        }

        return $this->formOptionsMerger->resolve(
            self::FORM_KIT_FORM_NAME,
            $fieldName,
            $type,
            $options,
            self::FORM_KIT_PROFILE,
        );
    }

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    private function addPhoneField(FormBuilderInterface $builder, ContactFormField $field, array $options): void
    {
        $phoneOptions = ContactFormFieldPhoneOptions::fromField($field);

        if (
            $phoneOptions->widget === ContactPhoneWidget::PhoneInput
            && $this->phoneInputAvailability->isAvailable()
        ) {
            $phoneInputType = 'Nowo\\PhoneInputBundle\\Form\\Type\\PhoneType';

            if (!is_subclass_of($phoneInputType, FormTypeInterface::class)) {
                return;
            }

            $builder->add(
                $field->getName(),
                $phoneInputType,
                $this->withProfileOptions(
                    $field->getName(),
                    $phoneInputType,
                    array_merge($options, $this->phoneInputOptionsResolver->resolveForField($field)),
                ),
            );

            return;
        }

        $prefixes = $this->phonePrefixResolver->resolveForField($field);

        if ($prefixes === []) {
            $builder->add($field->getName(), TelType::class, $this->withProfileOptions(
                $field->getName(),
                TelType::class,
                $options,
            ));

            return;
        }

        $builder->add($field->getName(), ContactPhoneType::class, $this->withProfileOptions(
            $field->getName(),
            ContactPhoneType::class,
            array_merge($options, [
                'prefixes' => $prefixes,
            ]),
        ));
    }

    /**
     * @return list<Constraint>
     */
    private function buildConstraints(
        ContactFormField $field,
        ContactFormFieldTranslation $translation,
        string $locale,
    ): array {
        if (!$field->isRequired()) {
            return [];
        }

        if ($field->getType() === ContactFieldType::Checkbox) {
            return [
                new IsTrue(message: $this->translator->trans(
                    'nowo_contact_form.public.field_required',
                    ['%field%' => $translation->getLabel()],
                    'NowoContactFormBundle',
                    $locale,
                )),
            ];
        }

        return [
            new NotBlank(message: $this->translator->trans(
                'nowo_contact_form.public.field_required',
                ['%field%' => $translation->getLabel()],
                'NowoContactFormBundle',
                $locale,
            )),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function buildSelectChoices(ContactFormField $field, ContactFormFieldTranslation $translation): array
    {
        $storedValues = $field->getOptions();
        $values       = is_array($storedValues) && array_is_list($storedValues) ? $storedValues : [];
        $labels       = $translation->getSelectOptions();

        if ($labels === null || count($labels) !== count($values)) {
            $labels = $values;
        }

        $choices = [];

        foreach ($values as $index => $value) {
            $choices[$labels[$index] ?? $value] = $value;
        }

        return $choices;
    }
}
