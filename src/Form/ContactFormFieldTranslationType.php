<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Form;

use Nowo\ContactFormBundle\Entity\ContactFormFieldTranslation;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Embedded form type for contact form field translations.
 *
 * @extends AbstractType<ContactFormFieldTranslation>
 */
#[FormKitConfig('contact_form')]
class ContactFormFieldTranslationType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $prefix = $options['label_prefix'];

        $this->addWithDefaults($builder, 'locale', $options['hide_locale'] ? HiddenType::class : TextType::class, [
            'label'      => $prefix . 'locale',
            'empty_data' => static function (FormInterface $form): string {
                $translation = $form->getParent()?->getData();

                return $translation instanceof ContactFormFieldTranslation
                    ? $translation->getLocale()
                    : 'en';
            },
        ]);
        $this->addText($builder, 'label', [
            'label'      => $prefix . 'label',
            'help'       => $prefix . 'label_help',
            'empty_data' => static function (FormInterface $form): string {
                $translation = $form->getParent()?->getData();

                return $translation instanceof ContactFormFieldTranslation
                    ? $translation->getLabel()
                    : '';
            },
        ]);
        $this->addText($builder, 'placeholder', [
            'label'    => $prefix . 'placeholder',
            'help'     => $prefix . 'placeholder_help',
            'required' => false,
        ]);
        $this->addTextarea($builder, 'help', [
            'label'    => $prefix . 'help',
            'help'     => $prefix . 'help_help',
            'required' => false,
        ]);

        if ($options['show_select_options']) {
            $this->addTextarea($builder, 'selectOptionsLines', [
                'label'    => $prefix . 'select_options',
                'help'     => $prefix . 'select_options_help',
                'required' => false,
                'mapped'   => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'          => ContactFormFieldTranslation::class,
            'clear_missing'       => false,
            'translation_domain'  => 'NowoContactFormBundle',
            'label_prefix'        => 'nowo_contact_form.admin.field.fields.translation.',
            'hide_locale'         => false,
            'show_select_options' => false,
        ]);

        $resolver->setAllowedTypes('hide_locale', 'bool');
        $resolver->setAllowedTypes('show_select_options', 'bool');
    }
}
