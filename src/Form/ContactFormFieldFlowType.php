<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Form;

use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\Flow\AbstractFlowType;
use Symfony\Component\Form\Flow\FormFlowBuilderInterface;
use Symfony\Component\Form\Flow\Type\NavigatorFlowType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function in_array;

/**
 * Admin wizard for contact form fields.
 *
 * @final since 1.5, will be final in 2.0 (not an extension point; decorate or replace the service instead)
 */
#[FormKitConfig('contact_form')]
class ContactFormFieldFlowType extends AbstractFlowType
{
    use FormOptionsTrait;

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
        $this->addWithDefaults($builder, 'navigator', NavigatorFlowType::class, []);

        // Steps are inherit_data children: Symfony does not dispatch their *_SET_DATA events, so the flow hands the
        // field to each step (unmapped textareas, dynamic option-label fields) once its own data is set.
        $builder->addEventListener(FormEvents::POST_SET_DATA, static function (FormEvent $event): void {
            $field = $event->getData();

            foreach ($event->getForm() as $child) {
                $step = $child->getConfig()->getType()->getInnerType();

                if ($field instanceof ContactFormField && $step instanceof ContactFormFieldStepDataInterface) {
                    $step->mapFieldToStep($child, $field);
                }
            }
        });
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
