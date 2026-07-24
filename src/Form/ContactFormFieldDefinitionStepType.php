<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Form;

use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * First wizard step: field identity and behavior.
 *
 * @extends AbstractType<ContactFormField>
 */
class ContactFormFieldDefinitionStepType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $prefix = $options['label_prefix'];

        $typeChoices = [];
        foreach (ContactFieldType::cases() as $case) {
            $typeChoices[$options['type_label_prefix'] . $case->value] = $case;
        }

        $builder
            ->add('name', TextType::class, [
                'label' => $prefix . 'name',
                'help'  => $prefix . 'name_help',
            ])
            ->add('type', ChoiceType::class, [
                'label'   => $prefix . 'type',
                'help'    => $prefix . 'type_help',
                'choices' => $typeChoices,
            ])
            ->add('required', CheckboxType::class, [
                'label'    => $prefix . 'required',
                'help'     => $prefix . 'required_help',
                'required' => false,
            ])
            ->add('sortOrder', IntegerType::class, [
                'label' => $prefix . 'sort_order',
                'help'  => $prefix . 'sort_order_help',
            ]);
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
