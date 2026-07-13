<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Form;

use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Embedded form type for contact form translations.
 */
class ContactFormTranslationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $prefix = $options['label_prefix'];

        $builder
            ->add('locale', $options['hide_locale'] ? HiddenType::class : TextType::class, [
                'label' => $prefix . 'locale',
            ])
            ->add('title', TextType::class, [
                'label' => $prefix . 'title',
                'help'  => $prefix . 'title_help',
            ])
            ->add('description', TextareaType::class, [
                'label'    => $prefix . 'description',
                'help'     => $prefix . 'description_help',
                'required' => false,
            ])
            ->add('successMessage', TextareaType::class, [
                'label'    => $prefix . 'success_message',
                'help'     => $prefix . 'success_message_help',
                'required' => false,
            ])
            ->add('consentLabel', TextareaType::class, [
                'label'    => $prefix . 'consent_label',
                'help'     => $prefix . 'consent_label_help',
                'required' => false,
            ])
            ->add('privacyPolicyText', TextareaType::class, [
                'label'    => $prefix . 'privacy_policy_text',
                'help'     => $prefix . 'privacy_policy_text_help',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => ContactFormTranslation::class,
            'translation_domain' => 'NowoContactFormBundle',
            'label_prefix'       => 'nowo_contact_form.admin.form.fields.translation.',
            'hide_locale'        => false,
        ]);

        $resolver->setAllowedTypes('hide_locale', 'bool');
    }
}
