<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Form;

use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Url;

/**
 * Admin form type for contact form definitions.
 *
 * @extends AbstractType<ContactForm>
 *
 * @final since 1.5, will be final in 2.0 (not an extension point; decorate or replace the service instead)
 */
#[FormKitConfig('contact_form')]
class ContactFormType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $prefix = $options['label_prefix'];

        $this->withBuilder($builder, function () use ($prefix, $options): void {
            // empty_data + NotBlank: a blank submission is a validation error, not a TypeError on the string setter.
            $this->addTextField('name', [
                'label'       => $prefix . 'name',
                'help'        => $prefix . 'name_help',
                'empty_data'  => '',
                'constraints' => [new NotBlank(), new Length(max: 120)],
            ]);
            $this->addTextField('slug', [
                'label'       => $prefix . 'slug',
                'help'        => $prefix . 'slug_help',
                'empty_data'  => '',
                'constraints' => [new NotBlank(), new Length(max: 120)],
            ]);
            $this->addCheckboxField('enabled', [
                'label'    => $prefix . 'enabled',
                'help'     => $prefix . 'enabled_help',
                'required' => false,
            ]);
            // Rendered as a link on the public page: http(s) only (no javascript: / data: URLs).
            $this->addUrlField('privacyPolicyUrl', [
                'label'            => $prefix . 'privacy_policy_url',
                'help'             => $prefix . 'privacy_policy_url_help',
                'required'         => false,
                'default_protocol' => 'https',
                'constraints'      => [new Url(protocols: ['http', 'https'], requireTld: true), new Length(max: 500)],
            ]);
            // 0 = keep forever; a negative value would make the retention cleanup delete every submission.
            $this->addIntegerField('retentionDays', [
                'label'       => $prefix . 'retention_days',
                'help'        => $prefix . 'retention_days_help',
                'constraints' => [new NotBlank(), new Range(min: ContactForm::RETENTION_DAYS_MIN, max: ContactForm::RETENTION_DAYS_MAX)],
            ]);
            $this->addEmailField('notificationEmail', [
                'constraints' => [new Email(), new Length(max: 255)],
                'label'       => $prefix . 'notification_email',
                'help'        => $prefix . 'notification_email_help',
                'required'    => false,
            ]);
            $this->addCheckboxField('requireConsent', [
                'label'    => $prefix . 'require_consent',
                'help'     => $prefix . 'require_consent_help',
                'required' => false,
            ]);
            $this->addTypedField('translations', CollectionType::class, [
                'entry_type'    => ContactFormTranslationType::class,
                'allow_add'     => false,
                'allow_delete'  => false,
                'by_reference'  => false,
                'label'         => false,
                'entry_options' => [
                    'label'              => false,
                    'error_bubbling'     => false,
                    'hide_locale'        => true,
                    'label_prefix'       => $options['label_prefix'] . 'translation.',
                    'translation_domain' => $options['translation_domain'],
                ],
            ]);
        });
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
