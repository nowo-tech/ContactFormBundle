<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Form;

use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
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
            ->add($this->createHiddenField($builder, 'type', static fn (?ContactFieldType $type): string => ($type ?? ContactFieldType::Text)->value, static fn (?string $value): ?ContactFieldType => ($value !== null && $value !== '') ? ContactFieldType::from($value) : null))
            ->add($this->createHiddenField($builder, 'required', static fn (?bool $required): string => ($required ?? false) ? '1' : '0', static fn (mixed $value): bool => in_array($value, [true, 1, '1', 'true', 'on'], true)))
            ->add($this->createHiddenField($builder, 'sortOrder', static fn (?int $sortOrder): string => (string) ($sortOrder ?? 0), static fn (mixed $value): int => (int) $value));
    }

    /**
     * @param FormBuilderInterface<ContactFormField|null> $builder
     */
    protected function addPreservedDefinitionFieldsPreSubmitListener(FormBuilderInterface $builder): void
    {
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $submitted = $event->getData();

            if (!is_array($submitted)) {
                return;
            }

            $field = $event->getForm()->getConfig()->getData();

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
