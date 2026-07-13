<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Form;

use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

use function count;

/**
 * Wizard step for select field machine names (stable stored values).
 */
class ContactFormFieldSelectOptionsStepType extends AbstractType
{
    use ContactFormFieldWizardStepTrait;

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $prefix = $options['label_prefix'];

        $this->addPreservedDefinitionFields($builder);
        $this->addPreservedDefinitionFieldsPreSubmitListener($builder);

        $builder->add('optionsMachineNamesLines', TextareaType::class, [
            'label'              => $prefix . 'options_machine_names',
            'help'               => $prefix . 'options_machine_names_help',
            'required'           => false,
            'mapped'             => false,
            'translation_domain' => $options['translation_domain'],
        ]);

        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event): void {
            $field = $this->resolveField($event);

            if (!$field instanceof ContactFormField || !$event->getForm()->has('optionsMachineNamesLines')) {
                return;
            }

            $values = $field->getOptions() ?? [];
            $event->getForm()->get('optionsMachineNamesLines')->setData(implode("\n", $values));
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $field = $this->resolveField($event);

            if (!$field instanceof ContactFormField || $field->getType() !== ContactFieldType::Select) {
                return;
            }

            $form = $event->getForm();

            if (!$form->has('optionsMachineNamesLines')) {
                return;
            }

            $values = $this->parseLines((string) $form->get('optionsMachineNamesLines')->getData());

            if (!$this->validateMachineNames($form, $values)) {
                return;
            }

            $field->setOptions($values === [] ? null : $values);
        });
    }

    /**
     * @param list<string> $values
     */
    private function validateMachineNames(FormInterface $form, array $values): bool
    {
        if (!$form->has('optionsMachineNamesLines')) {
            return true;
        }

        $linesField = $form->get('optionsMachineNamesLines');
        $valid      = true;

        foreach ($values as $value) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', $value) === 1) {
                continue;
            }

            $linesField->addError(new FormError(
                $this->translator->trans('nowo_contact_form.admin.field.fields.options_machine_names_invalid', [], 'NowoContactFormBundle'),
            ));
            $valid = false;
        }

        if (count($values) !== count(array_unique($values))) {
            $linesField->addError(new FormError(
                $this->translator->trans('nowo_contact_form.admin.field.fields.options_machine_names_duplicate', [], 'NowoContactFormBundle'),
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
