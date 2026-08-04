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

/**
 * Admin form type for contact form definitions.
 *
 * @extends AbstractType<ContactForm>
 */
#[FormKitConfig('contact_form')]
class ContactFormType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $prefix = $options['label_prefix'];

        $this->withBuilder($builder, function () use ($prefix, $options): void {
            $this->addTextField('name', [
                'label' => $prefix . 'name',
                'help'  => $prefix . 'name_help',
            ]);
            $this->addTextField('slug', [
                'label' => $prefix . 'slug',
                'help'  => $prefix . 'slug_help',
            ]);
            $this->addCheckboxField('enabled', [
                'label'    => $prefix . 'enabled',
                'help'     => $prefix . 'enabled_help',
                'required' => false,
            ]);
            $this->addUrlField('privacyPolicyUrl', [
                'label'    => $prefix . 'privacy_policy_url',
                'help'     => $prefix . 'privacy_policy_url_help',
                'required' => false,
            ]);
            $this->addIntegerField('retentionDays', [
                'label' => $prefix . 'retention_days',
                'help'  => $prefix . 'retention_days_help',
            ]);
            $this->addEmailField('notificationEmail', [
                'label'    => $prefix . 'notification_email',
                'help'     => $prefix . 'notification_email_help',
                'required' => false,
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
