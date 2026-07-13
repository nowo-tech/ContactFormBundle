<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Form;

use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Symfony\Component\Form\Flow\AbstractFlowType;
use Symfony\Component\Form\Flow\FormFlowBuilderInterface;
use Symfony\Component\Form\Flow\Type\NavigatorFlowType;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function in_array;

/**
 * Admin wizard for contact form fields.
 */
class ContactFormFieldFlowType extends AbstractFlowType
{
    public const STEP_DEFINITION = 'definition';

    public const STEP_SELECT_OPTIONS = 'select_options';

    public const STEP_PHONE_PREFIXES = 'phone_prefixes';

    public const STEP_CONTENT = 'content';

    /** @var list<string> */
    public const STEPS = [
        self::STEP_DEFINITION,
        self::STEP_SELECT_OPTIONS,
        self::STEP_PHONE_PREFIXES,
        self::STEP_CONTENT,
    ];

    public const STEP_REQUIREMENT = 'definition|select_options|phone_prefixes|content';

    public static function isValidStep(string $step): bool
    {
        return in_array($step, self::STEPS, true);
    }

    public function buildFormFlow(FormFlowBuilderInterface $builder, array $options): void
    {
        $builder->addStep('definition', ContactFormFieldDefinitionStepType::class);
        $builder->addStep(
            'select_options',
            ContactFormFieldSelectOptionsStepType::class,
            skip: static fn (ContactFormField $field): bool => $field->getType() !== ContactFieldType::Select,
        );
        $builder->addStep(
            'phone_prefixes',
            ContactFormFieldPhonePrefixesStepType::class,
            skip: static fn (ContactFormField $field): bool => $field->getType() !== ContactFieldType::Phone,
        );
        $builder->addStep('content', ContactFormFieldContentStepType::class);
        $builder->add('navigator', NavigatorFlowType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => ContactFormField::class,
            'step_property_path' => 'flowStep',
            'clear_missing'      => false,
            'translation_domain' => 'NowoContactFormBundle',
        ]);
    }
}
