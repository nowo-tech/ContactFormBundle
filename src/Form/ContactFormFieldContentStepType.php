<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Form;

use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactFormFieldTranslation;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\Service\ContactFormFieldSelectOptionsSynchronizer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_key_exists;
use function count;
use function is_array;
use function is_string;

/**
 * Final wizard step: localized labels and type-specific copy.
 *
 * @extends AbstractType<ContactFormField>
 */
class ContactFormFieldContentStepType extends AbstractType
{
    use ContactFormFieldWizardStepTrait;

    public function __construct(
        private readonly ContactFormFieldSelectOptionsSynchronizer $selectOptionsSynchronizer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $prefix = $options['label_prefix'];

        $this->addPreservedDefinitionFields($builder);

        $builder->add('translations', CollectionType::class, [
            'entry_type'    => ContactFormFieldTranslationType::class,
            'allow_add'     => false,
            'allow_delete'  => false,
            'by_reference'  => false,
            'label'         => false,
            'entry_options' => [
                'label'               => false,
                'hide_locale'         => true,
                'show_select_options' => false,
                'label_prefix'        => $prefix . 'translation.',
                'translation_domain'  => $options['translation_domain'],
            ],
        ]);

        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $submitted = $event->getData();

            if (!is_array($submitted)) {
                return;
            }

            $field = $event->getForm()->getConfig()->getData();

            if (!$field instanceof ContactFormField) {
                return;
            }

            foreach ([
                'name'      => $field->getName(),
                'type'      => $field->getType()->value,
                'required'  => $field->isRequired() ? '1' : '0',
                'sortOrder' => (string) $field->getSortOrder(),
            ] as $property => $fallback) {
                if (!array_key_exists($property, $submitted) || $submitted[$property] === null || $submitted[$property] === '') {
                    $submitted[$property] = $fallback;
                }
            }

            if (!isset($submitted['translations']) || !is_array($submitted['translations'])) {
                $event->setData($submitted);

                return;
            }

            $existingByIndex = array_values($field->getTranslations()->toArray());

            foreach ($submitted['translations'] as $index => $row) {
                if (!is_array($row)) {
                    continue;
                }

                $existing = $existingByIndex[(int) $index] ?? null;

                if (!$existing instanceof ContactFormFieldTranslation) {
                    continue;
                }

                $locale = $row['locale'] ?? null;

                if (!is_string($locale) || $locale === '') {
                    $submitted['translations'][$index]['locale'] = $existing->getLocale();
                }

                foreach (['label', 'placeholder', 'help'] as $property) {
                    if (!array_key_exists($property, $row) || $row[$property] === null) {
                        $submitted['translations'][$index][$property] = match ($property) {
                            'label'       => $existing->getLabel(),
                            'placeholder' => $existing->getPlaceholder(),
                            'help'        => $existing->getHelp(),
                        };
                    }
                }
            }

            $event->setData($submitted);
        });

        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) use ($prefix, $options): void {
            $field = $this->resolveField($event);

            if (!$field instanceof ContactFormField) {
                return;
            }

            $this->configureSelectOptionsFields($event->getForm(), $field, $prefix . 'translation.', $options['translation_domain']);
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $field = $this->resolveField($event);

            if (!$field instanceof ContactFormField) {
                return;
            }

            if ($field->getType() !== ContactFieldType::Select) {
                return;
            }

            $form         = $event->getForm();
            $machineNames = $field->getOptions() ?? [];

            if (!$form->has('translations')) {
                return;
            }

            foreach ($form->get('translations') as $translationForm) {
                $translation = $translationForm->getData();

                if (!$translation instanceof ContactFormFieldTranslation) {
                    continue;
                }

                if ($translationForm->has('selectOptionsLines')) {
                    $labels = $this->parseLines((string) $translationForm->get('selectOptionsLines')->getData());

                    if ($machineNames !== [] && count($labels) !== count($machineNames)) {
                        $translationForm->get('selectOptionsLines')->addError(new FormError(
                            $this->translator->trans(
                                'nowo_contact_form.admin.field.fields.translation.select_options_count_mismatch',
                                ['%count%' => count($machineNames)],
                                'NowoContactFormBundle',
                            ),
                        ));
                    }

                    $translation->setSelectOptions($labels === [] ? null : $labels);

                    continue;
                }

                $translation->setSelectOptions(null);
            }

            if ($form->isValid()) {
                $this->selectOptionsSynchronizer->synchronize($field);
            }
        });
    }

    /**
     * @param FormInterface<ContactFormField|null> $form
     */
    private function configureSelectOptionsFields(
        FormInterface $form,
        ContactFormField $field,
        string $labelPrefix,
        string $translationDomain,
    ): void {
        if (!$form->has('translations')) {
            return;
        }

        $isSelect = $field->getType() === ContactFieldType::Select;

        foreach ($form->get('translations') as $translationForm) {
            if ($isSelect) {
                if (!$translationForm->has('selectOptionsLines')) {
                    $translationForm->add('selectOptionsLines', TextareaType::class, [
                        'label'              => $labelPrefix . 'select_options',
                        'help'               => $labelPrefix . 'select_options_help',
                        'required'           => false,
                        'mapped'             => false,
                        'translation_domain' => $translationDomain,
                    ]);
                }

                $this->populateSelectOptionsLinesForTranslation($translationForm);

                continue;
            }

            if ($translationForm->has('selectOptionsLines')) {
                $translationForm->remove('selectOptionsLines');
            }
        }
    }

    /**
     * @param FormInterface<ContactFormFieldTranslation|null> $translationForm
     */
    private function populateSelectOptionsLinesForTranslation(FormInterface $translationForm): void
    {
        if (!$translationForm->has('selectOptionsLines')) {
            return;
        }

        $translation = $translationForm->getData();

        if (!$translation instanceof ContactFormFieldTranslation) {
            return;
        }

        $labels = $translation->getSelectOptions() ?? [];
        $translationForm->get('selectOptionsLines')->setData(implode("\n", $labels));
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
