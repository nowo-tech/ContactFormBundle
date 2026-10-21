<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Form;

use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * First wizard step: field identity and behavior.
 *
 * @extends AbstractType<ContactFormField>
 *
 * @final since 1.5, will be final in 2.0 (not an extension point; decorate or replace the service instead)
 */
#[FormKitConfig('contact_form')]
class ContactFormFieldDefinitionStepType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $prefix = $options['label_prefix'];

        $typeChoices = [];
        foreach (ContactFieldType::cases() as $case) {
            $typeChoices[$options['type_label_prefix'] . $case->value] = $case;
        }

        $this->withBuilder($builder, function () use ($prefix, $typeChoices): void {
            $this->addTextField('name', [
                'label' => $prefix . 'name',
                'help'  => $prefix . 'name_help',
            ]);
            $this->addChoiceField('type', [
                'label'   => $prefix . 'type',
                'help'    => $prefix . 'type_help',
                'choices' => $typeChoices,
            ]);
            $this->addCheckboxField('required', [
                'label'    => $prefix . 'required',
                'help'     => $prefix . 'required_help',
                'required' => false,
            ]);
            $this->addIntegerField('sortOrder', [
                'label' => $prefix . 'sort_order',
                'help'  => $prefix . 'sort_order_help',
            ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'inherit_data'       => true,
            'clear_missing'      => false,
            'translation_domain' => 'NowoContactFormBundle',
            'label_prefix'       => 'nowo_contact_form.admin.field.fields.',
            'type_label_prefix'  => 'nowo_contact_form.admin.field.type.',
        ]);
    }
}
