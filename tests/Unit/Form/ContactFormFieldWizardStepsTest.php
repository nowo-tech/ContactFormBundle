<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Form;

use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactFormFieldTranslation;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\Form\ContactFormFieldContentStepType;
use Nowo\ContactFormBundle\Form\ContactFormFieldDefinitionStepType;
use Nowo\ContactFormBundle\Form\ContactFormFieldFlowType;
use Nowo\ContactFormBundle\Form\ContactFormFieldPhonePrefixesStepType;
use Nowo\ContactFormBundle\Form\ContactFormFieldSelectOptionsStepType;
use Nowo\ContactFormBundle\Form\ContactFormFieldStepDataInterface;
use Nowo\ContactFormBundle\Form\ContactFormFieldTranslationType;
use Nowo\ContactFormBundle\Form\ContactFormFieldWizardStepTrait;
use Nowo\ContactFormBundle\Service\ContactFormFieldSelectOptionsSynchronizer;
use Nowo\ContactFormBundle\Service\ContactPhoneInputAvailability;
use Nowo\ContactFormBundle\Tests\Support\FormKitTestSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Flow\DataStorage\InMemoryDataStorage;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Field wizard steps as inherit_data children of a parent form (as in the FormFlow): preserved definition values,
 * type-specific validation, option labels per locale, and the flow handing the field to each step.
 */
#[CoversClass(ContactFormFieldSelectOptionsStepType::class)]
#[CoversClass(ContactFormFieldPhonePrefixesStepType::class)]
#[CoversClass(ContactFormFieldContentStepType::class)]
#[CoversClass(ContactFormFieldTranslationType::class)]
#[CoversClass(ContactFormFieldFlowType::class)]
#[CoversTrait(ContactFormFieldWizardStepTrait::class)]
final class ContactFormFieldWizardStepsTest extends TypeTestCase
{
    /**
     * @return list<ValidatorExtension>
     */
    protected function getExtensions(): array
    {
        return [new ValidatorExtension(Validation::createValidator())];
    }

    /**
     * @return list<object>
     */
    protected function getTypes(): array
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $parameters = []): string => $id . ($parameters === [] ? '' : json_encode($parameters)));

        return [
            FormKitTestSupport::withMerger(new ContactFormFieldFlowType()),
            FormKitTestSupport::withMerger(new ContactFormFieldDefinitionStepType()),
            FormKitTestSupport::withMerger(new ContactFormFieldSelectOptionsStepType($translator)),
            FormKitTestSupport::withMerger(new ContactFormFieldPhonePrefixesStepType($translator, new ContactPhoneInputAvailability(true))),
            FormKitTestSupport::withMerger(new ContactFormFieldContentStepType(new ContactFormFieldSelectOptionsSynchronizer(), $translator)),
            FormKitTestSupport::withMerger(new ContactFormFieldTranslationType()),
        ];
    }

    public function testSelectStepKeepsTheDefinitionWhenHiddenValuesAreMissing(): void
    {
        $field = $this->field('topic', ContactFieldType::Select)->setRequired(true)->setSortOrder(3);
        $form  = $this->stepForm(ContactFormFieldSelectOptionsStepType::class, $field);

        $form->submit(['step' => ['name' => '', 'optionsMachineNamesLines' => "general\n\nsales"]]);

        self::assertTrue($form->isValid());
        self::assertSame('topic', $field->getName());
        self::assertSame(ContactFieldType::Select, $field->getType());
        self::assertTrue($field->isRequired());
        self::assertSame(3, $field->getSortOrder());
        self::assertSame(['general', 'sales'], $field->getOptions());
    }

    public function testSelectStepRejectsDuplicatesAndIgnoresOtherTypes(): void
    {
        $select = $this->field('topic', ContactFieldType::Select);
        $form   = $this->stepForm(ContactFormFieldSelectOptionsStepType::class, $select);
        $form->submit(['step' => ['optionsMachineNamesLines' => "sales\nsales"]]);
        self::assertFalse($form->isValid());
        self::assertStringContainsString('options_machine_names_duplicate', $form->get('step')->get('optionsMachineNamesLines')->getErrors()->current()->getMessage());

        $text = $this->field('name', ContactFieldType::Text)->setOptions(['keep']);
        $form = $this->stepForm(ContactFormFieldSelectOptionsStepType::class, $text);
        $form->submit(['step' => ['optionsMachineNamesLines' => 'ignored']]);
        self::assertSame(['keep'], $text->getOptions());

        $empty = $this->field('topic', ContactFieldType::Select)->setOptions(['old']);
        $form  = $this->stepForm(ContactFormFieldSelectOptionsStepType::class, $empty);
        $form->submit(['step' => ['optionsMachineNamesLines' => '']]);
        self::assertNull($empty->getOptions());

        $nullStep = $this->stepForm(ContactFormFieldSelectOptionsStepType::class, $this->field('topic', ContactFieldType::Select));
        $nullStep->submit(['step' => null]);
        self::assertTrue($nullStep->isSubmitted());
    }

    public function testPhoneStepStoresPhoneInputOptions(): void
    {
        $field = $this->field('phone', ContactFieldType::Phone);
        $form  = $this->stepForm(ContactFormFieldPhonePrefixesStepType::class, $field);

        $form->submit(['step' => [
            'phoneWidget'                => 'phone_input',
            'phoneDefaultCountry'        => 'fr',
            'phoneAllowedCountriesLines' => "fr\nes",
        ]]);

        self::assertTrue($form->isValid());
        self::assertSame('phone_input', $field->getOptions()['widget'] ?? null);
        self::assertSame('FR', $field->getOptions()['default_country'] ?? null);
        self::assertSame(['FR', 'ES'], $field->getOptions()['allowed_countries'] ?? null);
    }

    public function testPhoneStepValidatesTheDefaultCountryAndIgnoresOtherTypes(): void
    {
        $field = $this->field('phone', ContactFieldType::Phone);
        $form  = $this->stepForm(ContactFormFieldPhonePrefixesStepType::class, $field);
        $form->submit(['step' => ['phoneWidget' => 'phone_input', 'phoneDefaultCountry' => 'Spain']]);
        self::assertFalse($form->isValid());
        self::assertNull($field->getOptions());

        $symfony = $this->field('phone', ContactFieldType::Phone)->setOptions(['widget' => 'symfony', 'prefixes' => ['+34']]);
        $form    = $this->stepForm(ContactFormFieldPhonePrefixesStepType::class, $symfony);
        self::assertSame('+34', $form->get('step')->get('phonePrefixesLines')->getData(), 'the flow hands the stored options to the step');
        $form->submit(['step' => ['phoneWidget' => 'symfony', 'phonePrefixesLines' => '']]);
        self::assertNull($symfony->getOptions(), 'no prefixes = bundle defaults');

        $email = $this->field('email', ContactFieldType::Email);
        $form  = $this->stepForm(ContactFormFieldPhonePrefixesStepType::class, $email);
        $form->submit(['step' => ['phoneWidget' => 'symfony', 'phonePrefixesLines' => 'bad']]);
        self::assertNull($email->getOptions());
    }

    public function testContentStepFillsMissingTranslationValuesAndStoresOptionLabels(): void
    {
        $field = $this->field('topic', ContactFieldType::Select)->setOptions(['general', 'sales']);
        $field->findTranslation('en')?->setPlaceholder('Pick one')->setHelp('Why');
        $form = $this->stepForm(ContactFormFieldContentStepType::class, $field);

        self::assertTrue($form->get('step')->get('translations')->get('0')->has('selectOptionsLines'));
        self::assertSame("General\nSales", $form->get('step')->get('translations')->get('0')->get('selectOptionsLines')->getData());

        $form->submit(['step' => ['translations' => [
            0       => ['locale' => '', 'selectOptionsLines' => "General\nSales"],
            1       => ['locale' => 'es', 'label' => 'Tema', 'selectOptionsLines' => "General\nVentas"],
            'extra' => 'not-a-row',
            7       => ['label' => 'no such translation'],
        ]]]);

        $en = $field->findTranslation('en');
        self::assertNotNull($en);
        self::assertSame('topic (en)', $en->getLabel(), 'label kept');
        self::assertSame('Pick one', $en->getPlaceholder());
        self::assertSame('Why', $en->getHelp());
        self::assertSame(['General', 'Sales'], $en->getSelectOptions());
        self::assertSame(['General', 'Ventas'], $field->findTranslation('es')?->getSelectOptions());
    }

    public function testContentStepRejectsAWrongNumberOfOptionLabels(): void
    {
        $field = $this->field('topic', ContactFieldType::Select)->setOptions(['general', 'sales']);
        $form  = $this->stepForm(ContactFormFieldContentStepType::class, $field);

        $form->submit(['step' => ['translations' => [
            ['locale' => 'en', 'label' => 'Topic', 'selectOptionsLines' => 'Only one'],
            ['locale' => 'es', 'label' => 'Tema', 'selectOptionsLines' => ''],
        ]]]);

        self::assertFalse($form->isValid());
        self::assertNull($field->findTranslation('es')?->getSelectOptions());
    }

    public function testContentStepDropsOptionLabelsForOtherTypes(): void
    {
        $field = $this->field('email', ContactFieldType::Email);
        $field->findTranslation('en')?->setSelectOptions(['stale']);
        $form = $this->stepForm(ContactFormFieldContentStepType::class, $field);
        self::assertFalse($form->get('step')->get('translations')->get('0')->has('selectOptionsLines'));

        $step = $form->get('step');
        $step->get('translations')->get('0')->add('selectOptionsLines', TextareaType::class, ['mapped' => false]);
        (new ContactFormFieldContentStepType(new ContactFormFieldSelectOptionsSynchronizer(), $this->createStub(TranslatorInterface::class)))->mapFieldToStep($step, $field);
        self::assertFalse($step->get('translations')->get('0')->has('selectOptionsLines'), 'removed when the type is not select');

        $form->submit(['step' => ['translations' => [['label' => 'Email'], ['label' => 'Correo']], 'name' => null]]);
        self::assertSame('Email', $field->findTranslation('en')?->getLabel());
        self::assertSame('email', $field->getName());

        $select = $this->field('topic', ContactFieldType::Select)->setOptions(['a']);
        $form   = $this->stepForm(ContactFormFieldContentStepType::class, $select);
        $form->get('step')->get('translations')->get('1')->remove('selectOptionsLines');
        $form->submit(['step' => ['translations' => [['label' => 'A', 'selectOptionsLines' => 'A'], ['label' => 'B']]]]);
        self::assertSame(['A'], $select->findTranslation('es')?->getSelectOptions(), 'no labels field: reset, then synchronized from the machine names');

        $noTranslations = $this->stepForm(ContactFormFieldContentStepType::class, $this->field('email', ContactFieldType::Email));
        $noTranslations->submit(['step' => ['name' => 'x']]);
        self::assertTrue($noTranslations->isSubmitted());
    }

    public function testStepsUsedAsRootFormsResolveTheFieldFromTheirData(): void
    {
        $field = $this->field('topic', ContactFieldType::Select);
        $form  = $this->factory->create(ContactFormFieldSelectOptionsStepType::class, $field, ['inherit_data' => false, 'data_class' => ContactFormField::class]);

        $form->submit(['optionsMachineNamesLines' => "a\nb"]);

        self::assertSame(['a', 'b'], $field->getOptions());

        $orphan = $this->factory->create(ContactFormFieldSelectOptionsStepType::class, null, ['inherit_data' => false, 'data_class' => ContactFormField::class, 'empty_data' => null]);
        $orphan->submit(['optionsMachineNamesLines' => 'a']);
        self::assertTrue($orphan->isSubmitted());

        $content = $this->factory->create(ContactFormFieldContentStepType::class, null, ['inherit_data' => false, 'data_class' => ContactFormField::class, 'empty_data' => null]);
        $content->submit(['name' => 'x']);
        self::assertTrue($content->isSubmitted(), 'no field: the content listeners do nothing');

        $nullStep = $this->stepForm(ContactFormFieldContentStepType::class, $this->field('email', ContactFieldType::Email));
        $nullStep->submit(['step' => null]);
        self::assertTrue($nullStep->isSubmitted());
    }

    public function testTranslationEntriesFallBackToTheirCurrentLocaleAndLabel(): void
    {
        $translation = (new ContactFormFieldTranslation())->setLocale('es')->setLabel('Correo');
        $form        = $this->factory->create(ContactFormFieldTranslationType::class, $translation);
        $form->submit(['locale' => '', 'label' => '']);
        self::assertSame('es', $translation->getLocale());
        self::assertSame('Correo', $translation->getLabel());

        $new = $this->factory->create(ContactFormFieldTranslationType::class, null, ['data_class' => null]);
        $new->submit(['locale' => '', 'label' => '']);
        self::assertSame('en', $new->get('locale')->getData());
        self::assertTrue($new->isSynchronized());
    }

    public function testTheFlowHandsTheFieldToItsCurrentStep(): void
    {
        $field = $this->field('topic', ContactFieldType::Select)->setOptions(['general'])->setFlowStep('select_options');
        $flow  = $this->factory->create(ContactFormFieldFlowType::class, $field, ['data_storage' => new InMemoryDataStorage('flow')]);

        self::assertSame('general', $flow->get('select_options')->get('optionsMachineNamesLines')->getData());

        $empty = $this->factory->create(ContactFormFieldFlowType::class, null, ['data_storage' => new InMemoryDataStorage('empty')]);
        self::assertFalse($empty->has('select_options'));
    }

    private function field(string $name, ContactFieldType $type): ContactFormField
    {
        $field = (new ContactFormField())->setName($name)->setType($type);
        foreach (['en', 'es'] as $locale) {
            $translation = (new ContactFormFieldTranslation())->setLocale($locale)->setLabel($name . ' (' . $locale . ')');
            if ($type === ContactFieldType::Select && $locale === 'en') {
                $translation->setSelectOptions(['General', 'Sales']);
            }
            $field->addTranslation($translation);
        }

        return $field;
    }

    /**
     * @param class-string $type
     *
     * @return FormInterface<ContactFormField>
     */
    private function stepForm(string $type, ContactFormField $field): FormInterface
    {
        /** @var class-string<FormTypeInterface<mixed>> $type */
        $form = $this->factory->createBuilder(FormType::class, $field, ['data_class' => ContactFormField::class])
            ->add('step', $type)
            ->getForm();

        // As in ContactFormFieldFlowType: steps are inherit_data children, the flow hands them the field.
        $step  = $form->get('step');
        $inner = $step->getConfig()->getType()->getInnerType();
        if ($inner instanceof ContactFormFieldStepDataInterface) {
            $inner->mapFieldToStep($step, $field);
        }

        return $form;
    }

    /**
     * N1: the definition rule is shared with the hidden name of the later steps.
     */
    public function testNameViolations(): void
    {
        self::assertNull(ContactFormFieldDefinitionStepType::nameViolation('email_address'));
        foreach (['gdpr_consent', '_token'] as $reserved) {
            self::assertSame('name_reserved', ContactFormFieldDefinitionStepType::nameViolation($reserved));
        }
        foreach (['Bad Name', '1st', 'x]evil', str_repeat('a', 121), ''] as $invalid) {
            self::assertSame('name_invalid', ContactFormFieldDefinitionStepType::nameViolation($invalid), $invalid);
        }

        $field = $this->field('topic', ContactFieldType::Select);
        $form  = $this->stepForm(ContactFormFieldSelectOptionsStepType::class, $field);
        $form->submit(['step' => ['name' => 'gdpr_consent', 'optionsMachineNamesLines' => 'a']]);
        self::assertFalse($form->isValid());
    }
}
