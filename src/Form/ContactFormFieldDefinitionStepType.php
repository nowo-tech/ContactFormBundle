<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Form;

use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface;
use Nowo\ContactFormBundle\Service\NullContactFormFileUploadHandler;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

use function in_array;
use function preg_match;

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

    /** Field names are submission keys and form children: a safe identifier, never a name the public form uses. */
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,119}$/';

    /** Children of the public form the bundle adds itself. */
    public const RESERVED_NAMES = ['gdpr_consent', '_token'];

    public function __construct(
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?ContactFormFileUploadHandlerInterface $fileUploadHandler = null,
    ) {
    }

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

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form      = $event->getForm();
            $violation = self::nameViolation((string) $form->get('name')->getData());

            if ($violation !== null) {
                $form->get('name')->addError(new FormError($this->message($violation, ['%name%' => (string) $form->get('name')->getData()])));
            }

            // Without a file_upload.service the null handler throws on every upload: the public form would fail.
            if ($form->get('type')->getData() === ContactFieldType::File && $this->fileUploadHandler instanceof NullContactFormFileUploadHandler) {
                $form->get('type')->addError(new FormError($this->message('type_file_unavailable')));
            }
        });
    }

    /**
     * Translation key (under nowo_contact_form.admin.field.fields.) of what is wrong with a field name, or null. Shared
     * with the later wizard steps, which carry the name in a hidden field.
     */
    public static function nameViolation(string $name): ?string
    {
        if (in_array($name, self::RESERVED_NAMES, true)) {
            return 'name_reserved';
        }

        return preg_match(self::NAME_PATTERN, $name) === 1 ? null : 'name_invalid';
    }

    /**
     * @param array<string, string> $parameters
     */
    private function message(string $key, array $parameters = []): string
    {
        $id = 'nowo_contact_form.admin.field.fields.' . $key;

        return $this->translator instanceof TranslatorInterface ? $this->translator->trans($id, $parameters, 'NowoContactFormBundle') : $id;
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
