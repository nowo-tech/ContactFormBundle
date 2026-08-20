<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit;

use Nowo\ContactFormBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\ContactFormBundle\DependencyInjection\NowoContactFormExtension;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactFormFieldTranslation;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Entity\ContactSubmissionValue;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\Enum\ContactPhoneWidget;
use Nowo\ContactFormBundle\Form\ContactPhoneType;
use Nowo\ContactFormBundle\Notification\ContactSubmissionNotification;
use Nowo\ContactFormBundle\Notification\ContactSubmissionNotifierInterface;
use Nowo\ContactFormBundle\Notification\MailerContactSubmissionNotifier;
use Nowo\ContactFormBundle\Notification\NullContactSubmissionNotifier;
use Nowo\ContactFormBundle\Phone\ContactFormFieldPhoneOptions;
use Nowo\ContactFormBundle\Repository\ContactFormFieldRepository;
use Nowo\ContactFormBundle\Service\ClientLabelResolver;
use Nowo\ContactFormBundle\Service\ContactFormFieldSelectOptionsSynchronizer;
use Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface;
use Nowo\ContactFormBundle\Service\ContactFormRichTextSanitizer;
use Nowo\ContactFormBundle\Service\ContactFormSubmissionValueNormalizer;
use Nowo\ContactFormBundle\Service\ContactPhoneInputAvailability;
use Nowo\ContactFormBundle\Service\ContactPhoneInputOptionsResolver;
use Nowo\ContactFormBundle\Service\ContactPhonePrefixResolver;
use Nowo\ContactFormBundle\Service\ContactPhoneValue;
use Nowo\ContactFormBundle\Service\DynamicContactFormBuilder;
use Nowo\ContactFormBundle\Service\SecurityClientResolver;
use Nowo\ContactFormBundle\Tests\Support\FormKitTestSupport;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CoverageCompletionTest extends TestCase
{
    public function testEntityGettersAndTranslationFallbacks(): void
    {
        $form = (new ContactForm())
            ->setName('Support')
            ->setSlug('support')
            ->setEnabled(true)
            ->setPrivacyPolicyUrl('https://example.com/privacy')
            ->setRetentionDays(30)
            ->setRequireConsent(true)
            ->setNotificationEmail('admin@example.com')
            ->addTranslation(
                (new ContactFormTranslation())
                    ->setLocale('de')
                    ->setTitle('Kontakt')
                    ->setDescription('Desc')
                    ->setSuccessMessage('OK')
                    ->setConsentLabel('Consent')
                    ->setPrivacyPolicyText('Policy'),
            );

        $this->setEntityId($form, 1);
        self::assertSame(1, $form->getId());
        self::assertSame('support', $form->getSlug());
        self::assertTrue($form->isEnabled());
        self::assertSame('https://example.com/privacy', $form->getPrivacyPolicyUrl());
        self::assertTrue($form->isRequireConsent());
        self::assertSame('admin@example.com', $form->getNotificationEmail());
        self::assertCount(1, $form->getTranslations());
        self::assertCount(0, $form->getFields());

        $translation = $form->getTranslations()->first();
        self::assertInstanceOf(ContactFormTranslation::class, $translation);
        $this->setEntityId($translation, 10);
        self::assertSame(10, $translation->getId());
        self::assertSame('Desc', $translation->getDescription());
        self::assertSame('OK', $translation->getSuccessMessage());
        self::assertSame('Consent', $translation->getConsentLabel());
        self::assertSame('Policy', $translation->getPrivacyPolicyText());

        self::assertSame('Kontakt', $form->getTranslationForLocale('fr', 'en')->getTitle());

        $emptyForm = new ContactForm();
        self::assertSame('fr', $emptyForm->getTranslationForLocale('fr')->getLocale());

        $field = (new ContactFormField())
            ->setName('phone')
            ->setType(ContactFieldType::Phone)
            ->setRequired(true)
            ->setSortOrder(2)
            ->setOptions(['+34']);

        $this->setEntityId($field, 2);
        self::assertSame(2, $field->getId());
        self::assertSame(ContactFieldType::Phone, $field->getType());
        self::assertTrue($field->isRequired());
        self::assertSame(2, $field->getSortOrder());
        self::assertSame(['+34'], $field->getOptions());

        $fieldTranslation = (new ContactFormFieldTranslation())
            ->setLocale('en')
            ->setLabel('Phone')
            ->setPlaceholder('600')
            ->setHelp('Help text')
            ->setSelectOptions(['One']);

        $this->setEntityId($fieldTranslation, 3);
        self::assertSame(3, $fieldTranslation->getId());
        self::assertSame('600', $fieldTranslation->getPlaceholder());
        self::assertSame('Help text', $fieldTranslation->getHelp());
        self::assertSame(['One'], $fieldTranslation->getSelectOptions());

        self::assertSame('fr', $field->getTranslationForLocale('fr')->getLocale());

        $submission = (new ContactSubmission())
            ->setClientId(7)
            ->setClientLabel(null);

        $this->setEntityId($submission, 4);
        self::assertSame(4, $submission->getId());

        $value = (new ContactSubmissionValue())
            ->setFieldName('message')
            ->setValue('Hello');

        $this->setEntityId($value, 5);
        self::assertSame(5, $value->getId());
        self::assertSame('message', $value->getFieldName());
        self::assertSame('Hello', $value->getValue());
    }

    public function testContactPhoneWidgetValues(): void
    {
        self::assertSame(['symfony', 'phone_input'], ContactPhoneWidget::values());
    }

    public function testContactPhoneValueEdgeCases(): void
    {
        self::assertSame('', ContactPhoneValue::combine('+34', 'abc'));

        $parts = ContactPhoneValue::split('600111222', ['+34', '+1']);
        self::assertSame('+34', $parts['prefix']);
        self::assertSame('600111222', $parts['number']);
    }

    public function testContactPhonePrefixResolverEdgeCases(): void
    {
        $resolver = new ContactPhonePrefixResolver(['+34' => 'ES (+34)']);

        $textField = (new ContactFormField())->setType(ContactFieldType::Text);
        self::assertSame([], $resolver->resolveForField($textField));
        self::assertSame([], $resolver->resolveCodesForField($textField));

        $phoneField = (new ContactFormField())
            ->setType(ContactFieldType::Phone)
            ->setOptions(['widget' => 'symfony', 'prefixes' => ['+99']]);

        self::assertSame(['+99' => '+99'], $resolver->resolveForField($phoneField));
        self::assertSame(['+99'], $resolver->resolveCodesForField($phoneField));
    }

    public function testContactPhoneInputAvailabilityAndOptionsResolver(): void
    {
        self::assertSame(
            class_exists('Nowo\PhoneInputBundle\Form\Type\PhoneType'),
            (new ContactPhoneInputAvailability())->isAvailable(),
        );

        $field = (new ContactFormField())
            ->setType(ContactFieldType::Phone)
            ->setOptions([
                'widget'            => 'phone_input',
                'default_country'   => 'es',
                'allowed_countries' => ['es', 'us'],
            ]);

        $options = (new ContactPhoneInputOptionsResolver([
            'value_format'            => 'E164',
            'country_prefix_selector' => true,
        ]))->resolveForField($field);

        self::assertSame('ES', $options['default_country']);
        self::assertSame('E164', $options['value_format']);
        self::assertSame(['ES', 'US'], $options['allowed_countries']);
    }

    public function testContactFormFieldPhoneOptionsParsingAndStorage(): void
    {
        $textField = ContactFormFieldPhoneOptions::fromField(
            (new ContactFormField())->setType(ContactFieldType::Text),
        );
        self::assertSame(ContactPhoneWidget::Symfony, $textField->widget);

        $emptyPhone = ContactFormFieldPhoneOptions::fromField(
            (new ContactFormField())->setType(ContactFieldType::Phone),
        );
        self::assertNull($emptyPhone->toStorage());

        $symfonyOptions = new ContactFormFieldPhoneOptions(
            widget: ContactPhoneWidget::Symfony,
            prefixes: ['+34', '+1'],
        );
        self::assertSame(
            ['widget' => 'symfony', 'prefixes' => ['+34', '+1']],
            $symfonyOptions->toStorage(),
        );

        $field = (new ContactFormField())
            ->setType(ContactFieldType::Phone)
            ->setOptions([
                'widget'   => 'symfony',
                'prefixes' => ['+34', '', 123, '+1'],
            ]);

        $parsed = ContactFormFieldPhoneOptions::fromField($field);
        self::assertSame(['+34', '+1'], $parsed->prefixes);
    }

    public function testSubmissionValueNormalizerPhoneValues(): void
    {
        $handler    = $this->createMock(ContactFormFileUploadHandlerInterface::class);
        $normalizer = new ContactFormSubmissionValueNormalizer($handler);
        $field      = (new ContactFormField())->setType(ContactFieldType::Phone);
        $form       = new ContactForm();

        $e164Object = new class {
            public function getE164(): string
            {
                return '+34600111222';
            }
        };

        self::assertSame(
            '+34600111222',
            $normalizer->normalize($field, $e164Object, $form),
        );
        self::assertSame(
            '+34600111222',
            $normalizer->normalize($field, ['prefix' => '+34', 'number' => '600111222'], $form),
        );
        self::assertSame(
            '+34600111222',
            $normalizer->normalize($field, ['prefix' => '+34', 'national_number' => '600111222'], $form),
        );
        self::assertSame('', $normalizer->normalize($field, ['prefix' => '+34'], $form));
    }

    public function testClientLabelResolverUsesGetter(): void
    {
        $client = new class {
            public function getEmail(): string
            {
                return 'getter@example.com';
            }
        };

        $resolver = new ClientLabelResolver(null, 'email');
        self::assertSame('getter@example.com', $resolver->resolveLabel($client));
    }

    public function testRichTextSanitizerHrefAndAttributeEdgeCases(): void
    {
        $sanitizer = new ContactFormRichTextSanitizer();

        self::assertStringNotContainsString('href=', $sanitizer->sanitize('<a href="">Empty</a>'));
        self::assertStringNotContainsString('href=', $sanitizer->sanitize('<a href="//evil.example">Bad</a>'));
        self::assertStringContainsString('mailto:test@example.com', $sanitizer->sanitize('<a href="mailto:test@example.com">Mail</a>'));
        self::assertStringContainsString('/privacy', $sanitizer->sanitize('<a href="/privacy">Relative</a>'));
        self::assertStringNotContainsString('onclick', $sanitizer->sanitize('<a href="https://example.com" onclick="x">Link</a>'));
    }

    public function testSelectOptionsSynchronizerFallbacks(): void
    {
        $synchronizer = new ContactFormFieldSelectOptionsSynchronizer();

        $field = (new ContactFormField())
            ->setType(ContactFieldType::Select)
            ->addTranslation(
                (new ContactFormFieldTranslation())
                    ->setLocale('xx')
                    ->setSelectOptions(['Custom label']),
            );

        $synchronizer->synchronize($field);
        self::assertSame(['custom_label'], $field->getOptions());

        $fieldWithLabels = (new ContactFormField())
            ->setType(ContactFieldType::Select)
            ->addTranslation(
                (new ContactFormFieldTranslation())
                    ->setLocale('en')
                    ->setSelectOptions(['General']),
            )
            ->addTranslation(
                (new ContactFormFieldTranslation())
                    ->setLocale('es')
                    ->setSelectOptions(['General ES']),
            );

        $synchronizer->synchronize($fieldWithLabels);
        self::assertSame(['general'], $fieldWithLabels->getOptions());
        self::assertSame(['General ES'], $fieldWithLabels->findTranslation('es')?->getSelectOptions());

        $fieldMissingLabels = (new ContactFormField())
            ->setType(ContactFieldType::Select)
            ->addTranslation(
                (new ContactFormFieldTranslation())
                    ->setLocale('en')
                    ->setSelectOptions(['General']),
            )
            ->addTranslation(
                (new ContactFormFieldTranslation())
                    ->setLocale('es')
                    ->setLabel('Tema'),
            );

        $synchronizer->synchronize($fieldMissingLabels);
        self::assertSame(['General'], $fieldMissingLabels->findTranslation('es')?->getSelectOptions());
    }

    public function testSecurityClientResolverReturnsNullForInvalidAccessorResult(): void
    {
        $clientEntityClass = new class {
        };

        $user = new class implements UserInterface {
            public function getClient(): stdClass
            {
                return new stdClass();
            }

            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'user';
            }
        };

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $storage = $this->createMock(TokenStorageInterface::class);
        $storage->method('getToken')->willReturn($token);

        $resolver = new SecurityClientResolver($clientEntityClass::class, 'getClient', $storage);

        self::assertNull($resolver->resolve());
    }

    public function testTwigPathsPassUsesNativeFilesystemDefinition(): void
    {
        $container = new ContainerBuilder();
        $loader    = new Definition();
        $container->setDefinition('twig.loader.native_filesystem', $loader);

        (new TwigPathsPass())->process($container);

        self::assertSame('addPath', $loader->getMethodCalls()[0][0]);
    }

    public function testExtensionWiresTokenStorageFileUploadAndMailerFallbacks(): void
    {
        $container = new ContainerBuilder();
        $container->register('security.token_storage', stdClass::class);
        $container->register('app.upload_handler', stdClass::class);
        $container->register('mailer', stdClass::class);

        (new NowoContactFormExtension())->load([array_merge($this->extensionBaseConfig(), [
            'client_user_accessor' => 'getClient',
            'file_upload'          => ['service' => 'app.upload_handler'],
            'notifications'        => [
                'enabled'           => true,
                'service'           => null,
                'default_recipient' => 'admin@example.com',
                'mailer'            => [
                    'enabled' => true,
                    'from'    => 'noreply@example.com',
                    'subject' => 'New: {form}',
                ],
            ],
        ])], $container);

        $resolverDef = $container->getDefinition(SecurityClientResolver::class);
        self::assertInstanceOf(Reference::class, $resolverDef->getArgument('$tokenStorage'));
        self::assertSame('app.upload_handler', (string) $container->getAlias(ContactFormFileUploadHandlerInterface::class));
        self::assertSame(
            MailerContactSubmissionNotifier::class,
            (string) $container->getAlias(ContactSubmissionNotifierInterface::class),
        );

        $emptyMailerContainer = new ContainerBuilder();
        (new NowoContactFormExtension())->load([array_merge($this->extensionBaseConfig(), [
            'notifications' => [
                'enabled'           => true,
                'service'           => null,
                'default_recipient' => null,
                'mailer'            => [
                    'enabled' => true,
                    'from'    => 'noreply@example.com',
                    'subject' => 'Subject',
                ],
            ],
        ])], $emptyMailerContainer);

        self::assertSame(
            NullContactSubmissionNotifier::class,
            (string) $emptyMailerContainer->getAlias(ContactSubmissionNotifierInterface::class),
        );
    }

    public function testMailerNotifierIncludesClientIdWhenLabelMissing(): void
    {
        $form = (new ContactForm())
            ->setName('Contact')
            ->setSlug('contact')
            ->setNotificationEmail('admin@example.com');

        $submission = (new ContactSubmission())
            ->setForm($form)
            ->setClientId(42)
            ->setClientLabel(null)
            ->setLocale('en')
            ->addValue((new ContactSubmissionValue())->setFieldName('msg')->setValue('Hi'));

        $notification = ContactSubmissionNotification::fromSubmission($submission);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(static fn (Email $email): bool => str_contains((string) ($email->getTextBody() ?? ''), 'Client: 42')));

        (new MailerContactSubmissionNotifier(
            $mailer,
            'noreply@example.com',
            'Subject {form}',
        ))->notify($notification);
    }

    public function testDynamicContactFormBuilderCoversRemainingFieldTypes(): void
    {
        $this->ensurePhoneInputTypeExists();

        $contactForm = (new ContactForm())
            ->setRequireConsent(true)
            ->addTranslation(
                (new ContactFormTranslation())->setLocale('en')->setTitle('Contact'),
            );

        $fields = [
            (new ContactFormField())
                ->setName('bio')
                ->setType(ContactFieldType::Textarea)
                ->setRequired(true)
                ->addTranslation((new ContactFormFieldTranslation())->setLocale('en')->setLabel('Bio')->setHelp('Help')),
            (new ContactFormField())
                ->setName('age')
                ->setType(ContactFieldType::Number)
                ->setRequired(false)
                ->addTranslation((new ContactFormFieldTranslation())->setLocale('en')->setLabel('Age')),
            (new ContactFormField())
                ->setName('birthday')
                ->setType(ContactFieldType::Date)
                ->setRequired(false)
                ->addTranslation((new ContactFormFieldTranslation())->setLocale('en')->setLabel('Birthday')),
            (new ContactFormField())
                ->setName('website')
                ->setType(ContactFieldType::Url)
                ->setRequired(false)
                ->addTranslation((new ContactFormFieldTranslation())->setLocale('en')->setLabel('Website')),
            (new ContactFormField())
                ->setName('name')
                ->setType(ContactFieldType::Text)
                ->setRequired(false)
                ->addTranslation((new ContactFormFieldTranslation())->setLocale('en')->setLabel('Name')),
            (new ContactFormField())
                ->setName('topic')
                ->setType(ContactFieldType::Select)
                ->setOptions(['general'])
                ->setRequired(false)
                ->addTranslation((new ContactFormFieldTranslation())->setLocale('en')->setLabel('Topic')),
            (new ContactFormField())
                ->setName('mobile')
                ->setType(ContactFieldType::Phone)
                ->setRequired(false)
                ->addTranslation((new ContactFormFieldTranslation())->setLocale('en')->setLabel('Mobile')),
            (new ContactFormField())
                ->setName('intl_phone')
                ->setType(ContactFieldType::Phone)
                ->setOptions(['widget' => 'phone_input', 'default_country' => 'ES'])
                ->setRequired(false)
                ->addTranslation((new ContactFormFieldTranslation())->setLocale('en')->setLabel('Intl')),
        ];

        $fieldRepository = $this->createMock(ContactFormFieldRepository::class);
        $fieldRepository->method('findByFormOrdered')->willReturn($fields);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Required');

        $builder = new DynamicContactFormBuilder(
            Forms::createFormFactoryBuilder()
                ->addExtension(new ValidatorExtension(Validation::createValidator()))
                ->addType(FormKitTestSupport::withMerger(new ContactPhoneType()))
                ->getFormFactory(),
            $fieldRepository,
            $translator,
            new ContactFormRichTextSanitizer(),
            new ContactPhonePrefixResolver([]),
            new ContactPhoneInputOptionsResolver(['value_format' => 'CONCATENATED']),
            new ContactPhoneInputAvailability(),
            FormKitTestSupport::merger(),
        );

        $form = $builder->createForm($contactForm, 'en');

        self::assertTrue($form->has('bio'));
        self::assertTrue($form->has('age'));
        self::assertTrue($form->has('birthday'));
        self::assertTrue($form->has('website'));
        self::assertTrue($form->has('name'));
        self::assertTrue($form->has('topic'));
        self::assertTrue($form->has('mobile'));
        self::assertTrue($form->has('intl_phone'));
        self::assertSame('Help', $form->get('bio')->getConfig()->getOption('help'));
        self::assertSame(['general' => 'general'], $form->get('topic')->getConfig()->getOption('choices'));
    }

    private function setEntityId(object $entity, int $id): void
    {
        $property = new ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }

    private function ensurePhoneInputTypeExists(): void
    {
        if (class_exists('Nowo\PhoneInputBundle\Form\Type\PhoneType', false)) {
            return;
        }

        eval(<<<'PHP'
namespace Nowo\PhoneInputBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PhoneType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['compound' => false]);
        $resolver->setDefined(['default_country', 'value_format', 'allowed_countries']);
    }
}
PHP);
    }

    /**
     * @return array<string, mixed>
     */
    private function extensionBaseConfig(): array
    {
        return [
            'client_entity_class'    => null,
            'client_label_property'  => 'email',
            'client_user_accessor'   => null,
            'ip_anonymization_salt'  => 'salt',
            'default_retention_days' => 365,
            'phone_prefixes'         => ['+34' => 'ES (+34)'],
            'phone_input'            => [
                'value_format'            => 'CONCATENATED',
                'default_country'         => 'ES',
                'country_prefix_selector' => true,
                'show_flag'               => true,
            ],
            'admin_route_prefix' => '/admin/contact-forms',
            'file_upload'        => ['service' => null],
            'notifications'      => [
                'enabled'           => false,
                'service'           => null,
                'default_recipient' => null,
                'mailer'            => [
                    'enabled' => false,
                    'from'    => 'noreply@example.com',
                    'subject' => 'Subject',
                ],
            ],
        ];
    }
}
