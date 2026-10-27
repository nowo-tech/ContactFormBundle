<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Form;

use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

use function array_key_exists;
use function in_array;
use function is_array;

/**
 * Shared helpers for multi-step contact form field admin wizards.
 */
trait ContactFormFieldWizardStepTrait
{
    /**
     * @param FormBuilderInterface<ContactFormField|null> $builder
     */
    protected function addPreservedDefinitionFields(FormBuilderInterface $builder): void
    {
        $builder
            ->add('name', HiddenType::class)
            ->add($this->createHiddenField($builder, 'type', static fn (?ContactFieldType $type): string => ($type ?? ContactFieldType::Text)->value, static fn (?string $value): ?ContactFieldType => ($value !== null && $value !== '') ? (ContactFieldType::tryFrom($value) ?? throw new TransformationFailedException('Unknown contact field type.')) : null))
            ->add($this->createHiddenField($builder, 'required', static fn (?bool $required): string => ($required ?? false) ? '1' : '0', static fn (mixed $value): bool => in_array($value, [true, 1, '1', 'true', 'on'], true)))
            ->add($this->createHiddenField($builder, 'sortOrder', static fn (?int $sortOrder): string => (string) ($sortOrder ?? 0), static fn (mixed $value): int => (int) $value));

        // The hidden name of the later steps is re-validated: a tampered value is refused like on the definition step.
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $name      = (string) $event->getForm()->get('name')->getData();
            $violation = ContactFormFieldDefinitionStepType::nameViolation($name);

            if ($violation !== null) {
                $event->getForm()->addError(new FormError($this->translator->trans('nowo_contact_form.admin.field.fields.' . $violation, ['%name%' => $name], 'NowoContactFormBundle')));
            }
        });
    }

    /**
     * @param FormBuilderInterface<ContactFormField|null> $builder
     */
    protected function addPreservedDefinitionFieldsPreSubmitListener(FormBuilderInterface $builder): void
    {
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $submitted = $event->getData();

            if (!is_array($submitted)) {
                return;
            }

            // The step is an inherit_data child of the flow: the field is the flow data, not the step config data.
            $field = $this->resolveField($event);

            if (!$field instanceof ContactFormField) {
                return;
            }

            foreach ([
                'name'      => $field->getName(),
                'type'      => $field->getType()->value,
                'required'  => $field->isRequired() ? '1' : '0',
                'sortOrder' => (string) $field->getSortOrder(),
            ] as $property => $fallback) {
                if (!array_key_exists($property, $submitted) || $submitted[$property] === null || $submitted[$property] === '') {
                    $submitted[$property] = $fallback;
                }
            }

            $event->setData($submitted);
        });
    }

    protected function resolveField(FormEvent $event): ?ContactFormField
    {
        $form = $event->getForm();

        if ($form->getConfig()->getInheritData() && $form->getParent() instanceof FormInterface) {
            $field = $form->getData();

            return $field instanceof ContactFormField ? $field : null;
        }

        $field = $event->getData();

        if ($field instanceof ContactFormField) {
            return $field;
        }

        $field = $form->getConfig()->getData();

        return $field instanceof ContactFormField ? $field : null;
    }

    /**
     * @param FormBuilderInterface<ContactFormField|null> $builder
     * @param callable(mixed): string $modelToNormalized
     * @param callable(?string): mixed $normalizedToModel
     *
     * @return FormBuilderInterface<mixed>
     */
    protected function createHiddenField(
        FormBuilderInterface $builder,
        string $name,
        callable $modelToNormalized,
        callable $normalizedToModel,
    ): FormBuilderInterface {
        $field = $builder->create($name, HiddenType::class);
        $field->addModelTransformer(new CallbackTransformer($modelToNormalized, $normalizedToModel));

        return $field;
    }

    /**
     * @return list<string>
     */
    protected function parseLines(string $raw): array
    {
        return array_values(array_filter(array_map(trim(...), preg_split('/\r\n|\r|\n/', $raw) ?: [])));
    }
}
