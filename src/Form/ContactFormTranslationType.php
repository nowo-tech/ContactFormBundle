<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Form;

use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Service\ContactFormRichTextSanitizer;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Embedded form type for contact form translations.
 *
 * @extends AbstractType<ContactFormTranslation>
 */
#[FormKitConfig('contact_form')]
class ContactFormTranslationType extends AbstractType
{
    use FormOptionsTrait;

    public function __construct(
        private readonly ContactFormRichTextSanitizer $richTextSanitizer = new ContactFormRichTextSanitizer(),
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $prefix = $options['label_prefix'];

        $this->withBuilder($builder, function () use ($prefix, $options): void {
            $this->addTypedField('locale', $options['hide_locale'] ? HiddenType::class : TextType::class, [
                'label' => $prefix . 'locale',
            ]);
            $this->addTextField('title', [
                'label' => $prefix . 'title',
                'help'  => $prefix . 'title_help',
            ]);
            $this->addTextareaField('description', [
                'label'    => $prefix . 'description',
                'help'     => $prefix . 'description_help',
                'required' => false,
            ]);
            $this->addTextareaField('successMessage', [
                'label'    => $prefix . 'success_message',
                'help'     => $prefix . 'success_message_help',
                'required' => false,
            ]);
            $this->addTextareaField('consentLabel', [
                'label'    => $prefix . 'consent_label',
                'help'     => $prefix . 'consent_label_help',
                'required' => false,
            ]);
            $this->addTextareaField('privacyPolicyText', [
                'label'    => $prefix . 'privacy_policy_text',
                'help'     => $prefix . 'privacy_policy_text_help',
                'required' => false,
            ]);
        });

        // Persist sanitized HTML so XSS payloads never rest in the DB (defense in depth with render-time sanitize).
        $builder->get('consentLabel')->addModelTransformer(new CallbackTransformer(
            static fn (?string $value): ?string => $value,
            function (?string $value): ?string {
                if ($value === null || $value === '') {
                    return $value;
                }

                return $this->richTextSanitizer->sanitize($value);
            },
        ));
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
