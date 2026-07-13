<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Service;

use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactFormFieldTranslation;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\Form\ContactPhoneType;
use Nowo\ContactFormBundle\Repository\ContactFormFieldRepository;
use Nowo\ContactFormBundle\Service\ContactFormRichTextSanitizer;
use Nowo\ContactFormBundle\Service\ContactPhoneInputAvailability;
use Nowo\ContactFormBundle\Service\ContactPhoneInputOptionsResolver;
use Nowo\ContactFormBundle\Service\ContactPhonePrefixResolver;
use Nowo\ContactFormBundle\Service\DynamicContactFormBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\Translation\TranslatorInterface;

use function sprintf;

#[CoversClass(DynamicContactFormBuilder::class)]
final class DynamicContactFormBuilderTest extends TestCase
{
    private FormFactoryInterface $formFactory;

    private TranslatorInterface $translator;

    private ContactFormRichTextSanitizer $richTextSanitizer;

    private ContactPhonePrefixResolver $phonePrefixResolver;

    private ContactPhoneInputOptionsResolver $phoneInputOptionsResolver;

    private ContactPhoneInputAvailability $phoneInputAvailability;

    protected function setUp(): void
    {
        $this->formFactory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new ContactPhoneType())
            ->getFormFactory();

        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string => match ($id) {
                'nowo_contact_form.public.consent_required' => 'Consent is required.',
                'nowo_contact_form.public.field_required'   => sprintf('The field "%s" is required.', $parameters['%field%'] ?? ''),
                default                                     => $id,
            },
        );

        $this->richTextSanitizer   = new ContactFormRichTextSanitizer();
        $this->phonePrefixResolver = new ContactPhonePrefixResolver([
            '+34' => 'ES (+34)',
            '+1'  => 'US (+1)',
        ]);
        $this->phoneInputOptionsResolver = new ContactPhoneInputOptionsResolver([
            'value_format'            => 'CONCATENATED',
            'default_country'         => 'ES',
            'country_prefix_selector' => true,
            'show_flag'               => true,
        ]);
        $this->phoneInputAvailability = new ContactPhoneInputAvailability();
    }

    private function createBuilder(ContactFormFieldRepository $fieldRepository): DynamicContactFormBuilder
    {
        return new DynamicContactFormBuilder(
            $this->formFactory,
            $fieldRepository,
            $this->translator,
            $this->richTextSanitizer,
            $this->phonePrefixResolver,
            $this->phoneInputOptionsResolver,
            $this->phoneInputAvailability,
        );
    }

    public function testCreateFormAddsFieldsConsentAndCsrf(): void
    {
        $contactForm = (new ContactForm())
            ->setRequireConsent(true)
            ->addTranslation(
                (new ContactFormTranslation())
                    ->setLocale('en')
                    ->setTitle('Contact')
                    ->setConsentLabel('I agree'),
            );

        $emailField = (new ContactFormField())
            ->setName('email')
            ->setType(ContactFieldType::Email)
            ->setRequired(true)
            ->setForm($contactForm)
            ->addTranslation(
                (new ContactFormFieldTranslation())
                    ->setLocale('en')
                    ->setLabel('Email')
                    ->setPlaceholder('you@example.com'),
            );

        $fieldRepository = $this->createMock(ContactFormFieldRepository::class);
        $fieldRepository->method('findByFormOrdered')->willReturn([$emailField]);

        $builder = $this->createBuilder($fieldRepository);
        $form    = $builder->createForm($contactForm, 'en');

        self::assertTrue($form->has('email'));
        self::assertTrue($form->has('gdpr_consent'));
        self::assertSame('I agree', $form->get('gdpr_consent')->getConfig()->getOption('label'));
        self::assertTrue($form->get('gdpr_consent')->getConfig()->getOption('label_html'));
    }

    public function testConsentLabelSupportsSanitizedHtml(): void
    {
        $contactForm = (new ContactForm())
            ->setRequireConsent(true)
            ->addTranslation(
                (new ContactFormTranslation())
                    ->setLocale('en')
                    ->setConsentLabel('I agree to the <strong>privacy policy</strong> and <a href="https://example.com/privacy">terms</a>.'),
            );

        $fieldRepository = $this->createMock(ContactFormFieldRepository::class);
        $fieldRepository->method('findByFormOrdered')->willReturn([]);

        $builder = $this->createBuilder($fieldRepository);
        $form    = $builder->createForm($contactForm, 'en');
        $label   = $form->get('gdpr_consent')->getConfig()->getOption('label');

        self::assertTrue($form->get('gdpr_consent')->getConfig()->getOption('label_html'));
        self::assertStringContainsString('<strong>privacy policy</strong>', $label);
        self::assertStringContainsString('href="https://example.com/privacy"', $label);
        self::assertStringContainsString('rel="noopener noreferrer"', $label);
    }

    public function testCreateFormWithoutConsentOmitsCheckbox(): void
    {
        $contactForm = (new ContactForm())->setRequireConsent(false);

        $fieldRepository = $this->createMock(ContactFormFieldRepository::class);
        $fieldRepository->method('findByFormOrdered')->willReturn([]);

        $builder = $this->createBuilder($fieldRepository);
        $form    = $builder->createForm($contactForm, 'en');

        self::assertFalse($form->has('gdpr_consent'));
    }

    public function testRequiredConsentBlocksSubmissionWhenUnchecked(): void
    {
        $contactForm = (new ContactForm())
            ->setRequireConsent(true)
            ->addTranslation(
                (new ContactFormTranslation())
                    ->setLocale('en')
                    ->setConsentLabel('I agree'),
            );

        $fieldRepository = $this->createMock(ContactFormFieldRepository::class);
        $fieldRepository->method('findByFormOrdered')->willReturn([]);

        $builder = $this->createBuilder($fieldRepository);
        $form    = $builder->createForm($contactForm, 'en');
        $form->submit(['gdpr_consent' => false]);

        self::assertFalse($form->isValid());
    }

    public function testOptionalFieldDoesNotRequireValue(): void
    {
        $contactForm = new ContactForm();

        $emailField = (new ContactFormField())
            ->setName('email')
            ->setType(ContactFieldType::Email)
            ->setRequired(false)
            ->setForm($contactForm)
            ->addTranslation(
                (new ContactFormFieldTranslation())
                    ->setLocale('en')
                    ->setLabel('Email'),
            );

        $fieldRepository = $this->createMock(ContactFormFieldRepository::class);
        $fieldRepository->method('findByFormOrdered')->willReturn([$emailField]);

        $builder = $this->createBuilder($fieldRepository);
        $form    = $builder->createForm($contactForm, 'en');
        $form->submit(['email' => '']);

        self::assertTrue($form->isValid());
    }

    public function testSelectFieldUsesTranslatedOptionLabels(): void
    {
        $contactForm = new ContactForm();

        $themeField = (new ContactFormField())
            ->setName('theme')
            ->setType(ContactFieldType::Select)
            ->setRequired(true)
            ->setOptions(['general_inquiry', 'technical_support'])
            ->setForm($contactForm)
            ->addTranslation(
                (new ContactFormFieldTranslation())
                    ->setLocale('en')
                    ->setLabel('Topic')
                    ->setSelectOptions(['General inquiry', 'Technical support']),
            )
            ->addTranslation(
                (new ContactFormFieldTranslation())
                    ->setLocale('es')
                    ->setLabel('Temática')
                    ->setSelectOptions(['Consulta general', 'Soporte técnico']),
            );

        $fieldRepository = $this->createMock(ContactFormFieldRepository::class);
        $fieldRepository->method('findByFormOrdered')->willReturn([$themeField]);

        $builder = $this->createBuilder($fieldRepository);

        $englishForm = $builder->createForm($contactForm, 'en');
        self::assertSame(
            ['General inquiry' => 'general_inquiry', 'Technical support' => 'technical_support'],
            $englishForm->get('theme')->getConfig()->getOption('choices'),
        );

        $spanishForm = $builder->createForm($contactForm, 'es');
        self::assertSame(
            ['Consulta general' => 'general_inquiry', 'Soporte técnico' => 'technical_support'],
            $spanishForm->get('theme')->getConfig()->getOption('choices'),
        );
    }

    public function testCheckboxFieldUsesIsTrueWhenRequired(): void
    {
        $contactForm = new ContactForm();

        $newsletterField = (new ContactFormField())
            ->setName('newsletter')
            ->setType(ContactFieldType::Checkbox)
            ->setRequired(true)
            ->setForm($contactForm)
            ->addTranslation(
                (new ContactFormFieldTranslation())
                    ->setLocale('en')
                    ->setLabel('Subscribe to newsletter'),
            );

        $fieldRepository = $this->createMock(ContactFormFieldRepository::class);
        $fieldRepository->method('findByFormOrdered')->willReturn([$newsletterField]);

        $form = $this->createBuilder($fieldRepository)->createForm($contactForm, 'en');
        $form->submit(['newsletter' => false]);

        self::assertFalse($form->isValid());
    }

    public function testFileFieldSetsMultipartEncoding(): void
    {
        $contactForm = new ContactForm();

        $attachmentField = (new ContactFormField())
            ->setName('attachment')
            ->setType(ContactFieldType::File)
            ->setRequired(false)
            ->setForm($contactForm)
            ->addTranslation(
                (new ContactFormFieldTranslation())
                    ->setLocale('en')
                    ->setLabel('Attachment'),
            );

        $fieldRepository = $this->createMock(ContactFormFieldRepository::class);
        $fieldRepository->method('findByFormOrdered')->willReturn([$attachmentField]);

        $form = $this->createBuilder($fieldRepository)->createForm($contactForm, 'en');

        self::assertSame('multipart/form-data', $form->getConfig()->getOption('attr')['enctype'] ?? null);
    }

    public function testPhoneFieldUsesPrefixSelector(): void
    {
        $contactForm = new ContactForm();

        $phoneField = (new ContactFormField())
            ->setName('phone')
            ->setType(ContactFieldType::Phone)
            ->setRequired(true)
            ->setOptions(['widget' => 'symfony', 'prefixes' => ['+34', '+1']])
            ->setForm($contactForm)
            ->addTranslation(
                (new ContactFormFieldTranslation())
                    ->setLocale('en')
                    ->setLabel('Phone'),
            );

        $fieldRepository = $this->createMock(ContactFormFieldRepository::class);
        $fieldRepository->method('findByFormOrdered')->willReturn([$phoneField]);

        $form = $this->createBuilder($fieldRepository)->createForm($contactForm, 'en');

        self::assertTrue($form->has('phone'));
        self::assertTrue($form->get('phone')->has('prefix'));
        self::assertTrue($form->get('phone')->has('number'));

        $form->submit([
            'phone' => [
                'prefix' => '+34',
                'number' => '600 111 222',
            ],
        ]);

        self::assertTrue($form->isValid());
        self::assertSame('+34600111222', $form->get('phone')->getData());
    }
}
