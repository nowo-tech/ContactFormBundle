<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactFormFieldTranslation;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Entity\ContactSubmissionValue;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\Form\ContactFormFieldContentStepType;
use Nowo\ContactFormBundle\Form\ContactFormFieldDefinitionStepType;
use Nowo\ContactFormBundle\Form\ContactFormFieldFlowType;
use Nowo\ContactFormBundle\Form\ContactFormFieldPhonePrefixesStepType;
use Nowo\ContactFormBundle\Form\ContactFormFieldSelectOptionsStepType;
use Nowo\ContactFormBundle\Form\ContactFormFieldTranslationType;
use Nowo\ContactFormBundle\Form\ContactFormTranslationType;
use Nowo\ContactFormBundle\Form\ContactFormType;
use Nowo\ContactFormBundle\Form\ContactPhoneType;
use Nowo\ContactFormBundle\Repository\ContactFormFieldRepository;
use Nowo\ContactFormBundle\Service\ContactFormFieldSelectOptionsSynchronizer;
use Nowo\ContactFormBundle\Service\ContactFormRichTextSanitizer;
use Nowo\ContactFormBundle\Service\ContactPhoneInputAvailability;
use Nowo\ContactFormBundle\Service\ContactPhoneInputOptionsResolver;
use Nowo\ContactFormBundle\Service\ContactPhonePrefixResolver;
use Nowo\ContactFormBundle\Service\DynamicContactFormBuilder;
use Nowo\ContactFormBundle\Ui\ContactFormPageFactory;
use Nowo\ContactFormBundle\Ui\ContactFormPages;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\FormKitBundle\Form\Extension\LocaleTabsExtension;
use Nowo\FormKitBundle\Form\FormTypeMap;
use Nowo\FormKitBundle\Form\Type\CsrfOnlyType;
use Nowo\UiKitBundle\View\Pagination;
use ReflectionProperty;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Flow\DataStorage\InMemoryDataStorage;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\Translation\TranslatorInterface;

use function http_build_query;

/**
 * Page contexts of the REQ-UI-003 page contract test: the view models come from {@see ContactFormPageFactory} (the
 * service the controllers use), the FormViews from the real form types (FormKit profile merger, locale tabs, CSRF),
 * the rest mirrors the controller variables.
 */
final class PageFixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function formIndex(bool $empty = false, ?ContactFormPageFactory $factory = null): array
    {
        $forms       = $empty ? [] : [self::contactForm(1, 'Support', 'support', true), self::contactForm(2, 'Job application', 'job-application', false)];
        $deleteForms = [];
        foreach ($forms as $form) {
            $deleteForms[(int) $form->getId()] = self::csrfForm('/admin/contact-forms/' . $form->getId() . '/delete', 'delete-contact-form-' . $form->getId());
        }
        $pagination = $empty
            ? new Pagination(1, 20, 0, 1, 'nowo_contact_form_admin_index')
            : new Pagination(1, 2, 3, 2, 'nowo_contact_form_admin_index');

        return ['page' => ($factory ?? self::factory())->formIndex($forms, $pagination, $deleteForms)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function formForm(bool $edit = false): array
    {
        $contactForm = $edit ? self::contactForm(1, 'Support', 'support', true) : self::contactForm(null, '', '', true);
        $form        = self::forms()->create(ContactFormType::class, $contactForm)->createView();
        $deleteForm  = $edit ? self::csrfForm('/admin/contact-forms/1/delete', 'delete-contact-form-1') : null;

        return [
            'page'        => self::factory()->formForm($form, $contactForm, !$edit, $deleteForm),
            'contactForm' => $contactForm,
            'isNew'       => !$edit,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fieldIndex(bool $empty = false): array
    {
        $contactForm = self::contactForm(1, 'Support', 'support', true)->setRequireConsent(!$empty);
        $fields      = $empty ? [] : [self::field(1, 'email', ContactFieldType::Email, true), self::field(2, 'topic', ContactFieldType::Select, false)];
        $deleteForms = [];
        foreach ($fields as $field) {
            $deleteForms[(int) $field->getId()] = self::csrfForm('/admin/contact-forms/1/fields/' . $field->getId() . '/delete', 'delete-contact-field-' . $field->getId());
        }

        return [
            'page'        => self::factory()->fieldIndex($contactForm, $fields, $deleteForms),
            'contactForm' => $contactForm,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fieldWizard(string $step, bool $edit = false): array
    {
        $contactForm = self::contactForm(1, 'Support', 'support', true);
        $field       = self::field($edit ? 1 : null, 'topic', ContactFieldType::Select, true)->setFlowStep($step);
        $field->setForm($contactForm);
        $flow  = self::forms()->create(ContactFormFieldFlowType::class, $field, ['data_storage' => new InMemoryDataStorage('contact_form_field_flow')]);
        $steps = [ContactFormFieldFlowType::STEP_DEFINITION, ContactFormFieldFlowType::STEP_SELECT_OPTIONS, ContactFormFieldFlowType::STEP_CONTENT];

        return [
            'page'        => self::factory()->fieldWizard($contactForm, $edit ? 1 : null, $flow->createView(), $steps, $step),
            'contactForm' => $contactForm,
            'field'       => $field,
            'isNew'       => !$edit,
            'currentStep' => $step,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function submissionIndex(bool $empty = false): array
    {
        $contactForm = self::contactForm(1, 'Support', 'support', true);
        $submissions = $empty ? [] : [self::submission(1, $contactForm, false), self::submission(2, $contactForm, true)];
        $deleteForms = [];
        foreach ($submissions as $submission) {
            $deleteForms[(int) $submission->getId()] = self::csrfForm('/admin/contact-forms/1/submissions/' . $submission->getId() . '/delete', 'delete-submission-' . $submission->getId());
        }
        $pagination = new Pagination(1, 20, $empty ? 0 : 2, 1, 'nowo_contact_form_submissions_index', [], ['formId' => 1]);

        return [
            'page'        => self::factory()->submissionIndex($contactForm, $submissions, $pagination, $deleteForms),
            'contactForm' => $contactForm,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function submissionShow(bool $anonymous = false, ?ContactFormPageFactory $factory = null): array
    {
        $contactForm = self::contactForm(1, 'Support', 'support', true);
        $submission  = self::submission(1, $contactForm, $anonymous);

        return [
            'page'        => ($factory ?? self::factory())->submissionShow($contactForm, $submission, self::csrfForm('/admin/contact-forms/1/submissions/1/delete', 'delete-submission-1')),
            'contactForm' => $contactForm,
            'submission'  => $submission,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function publicForm(): array
    {
        $contactForm = self::contactForm(1, 'Support', 'support', true)
            ->setRequireConsent(true)
            ->setPrivacyPolicyUrl('https://example.com/privacy');
        $translation = $contactForm->getTranslationForLocale('en')
            ->setDescription('We answer within one business day.')
            ->setConsentLabel('I agree to the processing of my data.')
            ->setPrivacyPolicyText('Privacy policy');
        $fields = [
            self::field(1, 'name', ContactFieldType::Text, true),
            self::field(2, 'email', ContactFieldType::Email, true),
            self::field(3, 'phone', ContactFieldType::Phone, false),
            self::field(4, 'message', ContactFieldType::Textarea, true),
        ];
        foreach ($fields as $field) {
            $field->setForm($contactForm);
        }
        $form    = self::publicBuilder($fields)->createForm($contactForm, 'en', ['csrf_protection' => false])->createView();
        $factory = self::factory();

        return [
            'header'      => $factory->publicHeader($translation),
            'page_layout' => $factory->publicLayout(),
            'contactForm' => $contactForm,
            'translation' => $translation,
            'form'        => $form,
        ];
    }

    public static function factory(?ContactFormPages $pages = null): ContactFormPageFactory
    {
        return new ContactFormPageFactory(self::urlGenerator(), $pages ?? new ContactFormPages());
    }

    public static function urlGenerator(): UrlGeneratorInterface
    {
        return new class implements UrlGeneratorInterface {
            private RequestContext $context;

            public function __construct()
            {
                $this->context = new RequestContext();
            }

            public function setContext(RequestContext $context): void
            {
                $this->context = $context;
            }

            public function getContext(): RequestContext
            {
                return $this->context;
            }

            /**
             * @param array<string, mixed> $parameters
             */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return '/' . $name . ($parameters === [] ? '' : '?' . http_build_query($parameters));
            }
        };
    }

    public static function contactForm(?int $id, string $name, string $slug, bool $enabled): ContactForm
    {
        $form = (new ContactForm())->setName($name)->setSlug($slug)->setEnabled($enabled);
        if ($id !== null) {
            self::setId($form, $id);
        }
        foreach (['en' => 'Contact us', 'es' => 'Contacto'] as $locale => $title) {
            $form->addTranslation((new ContactFormTranslation())->setLocale($locale)->setTitle($title));
        }

        return $form;
    }

    public static function field(?int $id, string $name, ContactFieldType $type, bool $required): ContactFormField
    {
        $field = (new ContactFormField())->setName($name)->setType($type)->setRequired($required);
        if ($id !== null) {
            self::setId($field, $id);
        }
        if ($type === ContactFieldType::Select) {
            $field->setOptions(['general', 'sales']);
        }
        foreach (['en', 'es'] as $locale) {
            $translation = (new ContactFormFieldTranslation())->setLocale($locale)->setLabel($name . ' (' . $locale . ')');
            if ($type === ContactFieldType::Select) {
                $translation->setSelectOptions(['General', 'Sales']);
            }
            $field->addTranslation($translation);
        }

        return $field;
    }

    private static function submission(int $id, ContactForm $form, bool $anonymous): ContactSubmission
    {
        $submission = (new ContactSubmission())
            ->setForm($form)
            ->setLocale('en')
            ->setCreatedAt(new DateTimeImmutable('2026-10-27 09:30:00'))
            ->setConsentGivenAt(new DateTimeImmutable('2026-10-27 09:30:00'));
        if (!$anonymous) {
            $submission->setClientId(42)->setClientLabel('ada@example.com');
        }
        self::setId($submission, $id);
        foreach (['email' => 'ada@example.com', 'message' => 'Hello, I would like a demo.'] as $name => $value) {
            $submission->addValue((new ContactSubmissionValue())->setFieldName($name)->setValue($value));
        }

        return $submission;
    }

    private static function setId(object $entity, int $id): void
    {
        (new ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }

    private static function csrfForm(string $action, string $tokenId): FormView
    {
        return (new CsrfOnlyFormFactory(self::forms()))->create($action, $tokenId)->createView();
    }

    /**
     * @param list<ContactFormField> $fields
     */
    private static function publicBuilder(array $fields): DynamicContactFormBuilder
    {
        $repository = new class($fields) extends ContactFormFieldRepository {
            /**
             * @param list<ContactFormField> $fields
             */
            public function __construct(private readonly array $fields)
            {
            }

            public function findByFormOrdered(ContactForm $form): array
            {
                return $this->fields;
            }
        };

        return new DynamicContactFormBuilder(
            self::forms(),
            $repository,
            self::translator(),
            new ContactFormRichTextSanitizer(),
            new ContactPhonePrefixResolver(['+34' => 'ES (+34)', '+1' => 'US (+1)']),
            new ContactPhoneInputOptionsResolver(['value_format' => 'CONCATENATED', 'default_country' => 'ES', 'country_prefix_selector' => true, 'show_flag' => true]),
            new ContactPhoneInputAvailability(),
            FormKitTestSupport::merger(FormKitTestSupport::translator()),
        );
    }

    private static function forms(): FormFactoryInterface
    {
        $translator = self::translator();
        $catalogue  = FormKitTestSupport::translator();

        return Forms::createFormFactoryBuilder()
            ->addExtension(new CsrfExtension(self::csrfTokens()))
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addTypeExtension(new LocaleTabsExtension())
            ->addTypes([
                FormKitTestSupport::withMerger(new ContactFormType(), $catalogue),
                FormKitTestSupport::withMerger(new ContactFormTranslationType(), $catalogue),
                FormKitTestSupport::withMerger(new ContactFormFieldFlowType(), $catalogue),
                FormKitTestSupport::withMerger(new ContactFormFieldDefinitionStepType(), $catalogue),
                FormKitTestSupport::withMerger(new ContactFormFieldSelectOptionsStepType($translator), $catalogue),
                FormKitTestSupport::withMerger(new ContactFormFieldPhonePrefixesStepType($translator, new ContactPhoneInputAvailability()), $catalogue),
                FormKitTestSupport::withMerger(new ContactFormFieldContentStepType(new ContactFormFieldSelectOptionsSynchronizer(), $translator), $catalogue),
                FormKitTestSupport::withMerger(new ContactFormFieldTranslationType(), $catalogue),
                FormKitTestSupport::withMerger(new ContactPhoneType(), $catalogue),
                new CsrfOnlyType(FormKitTestSupport::merger($catalogue), new FormTypeMap([])),
            ])
            ->getFormFactory();
    }

    private static function translator(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            /**
             * @param array<string, mixed> $parameters
             */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id;
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };
    }

    private static function csrfTokens(): CsrfTokenManagerInterface
    {
        return new class implements CsrfTokenManagerInterface {
            public function getToken(string $tokenId): CsrfToken
            {
                return new CsrfToken($tokenId, 'REDACTED-TEST-' . $tokenId);
            }

            public function refreshToken(string $tokenId): CsrfToken
            {
                return $this->getToken($tokenId);
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function isTokenValid(CsrfToken $token): bool
            {
                return true;
            }
        };
    }
}
