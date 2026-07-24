<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Form;

use Nowo\ContactFormBundle\Entity\ContactForm;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Admin form type for contact form definitions.
 *
 * @extends AbstractType<ContactForm>
 */
class ContactFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $prefix = $options['label_prefix'];

        $builder
            ->add('name', TextType::class, [
                'label' => $prefix . 'name',
                'help'  => $prefix . 'name_help',
            ])
            ->add('slug', TextType::class, [
                'label' => $prefix . 'slug',
                'help'  => $prefix . 'slug_help',
            ])
            ->add('enabled', CheckboxType::class, [
                'label'    => $prefix . 'enabled',
                'help'     => $prefix . 'enabled_help',
                'required' => false,
            ])
            ->add('privacyPolicyUrl', UrlType::class, [
                'label'    => $prefix . 'privacy_policy_url',
                'help'     => $prefix . 'privacy_policy_url_help',
                'required' => false,
            ])
            ->add('retentionDays', IntegerType::class, [
                'label' => $prefix . 'retention_days',
                'help'  => $prefix . 'retention_days_help',
            ])
            ->add('notificationEmail', EmailType::class, [
                'label'    => $prefix . 'notification_email',
                'help'     => $prefix . 'notification_email_help',
                'required' => false,
            ])
            ->add('requireConsent', CheckboxType::class, [
                'label'    => $prefix . 'require_consent',
                'help'     => $prefix . 'require_consent_help',
                'required' => false,
            ])
            ->add('translations', CollectionType::class, [
                'entry_type'    => ContactFormTranslationType::class,
                'allow_add'     => false,
                'allow_delete'  => false,
                'by_reference'  => false,
                'label'         => false,
                'entry_options' => [
                    'label'              => false,
                    'hide_locale'        => true,
                    'label_prefix'       => $options['label_prefix'] . 'translation.',
                    'translation_domain' => $options['translation_domain'],
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => ContactForm::class,
            'translation_domain' => 'NowoContactFormBundle',
            'label_prefix'       => 'nowo_contact_form.admin.form.fields.',
        ]);
    }
}
