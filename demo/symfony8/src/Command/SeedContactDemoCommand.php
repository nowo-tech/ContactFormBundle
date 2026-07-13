<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactFormFieldTranslation;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_key_exists;
use function in_array;
use function sprintf;

/**
 * Seeds demo contact forms showcasing every field type.
 */
#[AsCommand(name: 'app:seed-contact-demo', description: 'Seed demo contact form data')]
final class SeedContactDemoCommand extends Command
{
    public function __construct(
        private readonly ContactFormRepository $formRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach ($this->formDefinitions() as $slug => $definition) {
            $form = $this->formRepository->findOneBy(['slug' => $slug]);

            if (!$form instanceof ContactForm) {
                $form = new ContactForm();
                $this->entityManager->persist($form);
            }

            $this->applyFormDefinition($form, $slug, $definition);
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            'Demo contact forms seeded: %s.',
            implode(', ', array_keys($this->formDefinitions())),
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function formDefinitions(): array
    {
        return [
            'contact' => [
                'name'              => 'General contact',
                'enabled'           => true,
                'requireConsent'    => true,
                'privacyPolicyUrl'  => 'https://example.com/privacy',
                'notificationEmail' => 'contact@demo.example',
                'translations'      => [
                    'en' => [
                        'title'             => 'Contact us',
                        'description'       => 'Classic support form with email, Symfony phone prefix selector, select and textarea.',
                        'successMessage'    => 'Thank you! We received your message.',
                        'consentLabel'      => 'I agree to the processing of my data according to the <a href="https://example.com/privacy">privacy policy</a>.',
                        'privacyPolicyText' => 'Privacy policy',
                    ],
                    'es' => [
                        'title'             => 'Contáctanos',
                        'description'       => 'Formulario clásico con email, teléfono, selección y área de texto.',
                        'successMessage'    => '¡Gracias! Hemos recibido tu mensaje.',
                        'consentLabel'      => 'Acepto el tratamiento de mis datos según la <a href="https://example.com/privacy">política de privacidad</a>.',
                        'privacyPolicyText' => 'Política de privacidad',
                    ],
                ],
                'fields' => [
                    ['name' => 'email', 'type' => ContactFieldType::Email, 'required' => true, 'sort' => 1, 'translations' => [
                        'en' => ['label' => 'Email', 'placeholder' => 'you@example.com'],
                        'es' => ['label' => 'Email', 'placeholder' => 'tu@email.com'],
                    ]],
                    ['name' => 'phone', 'type' => ContactFieldType::Phone, 'required' => false, 'sort' => 2, 'options' => [
                        'widget'   => 'symfony',
                        'prefixes' => ['+34', '+1', '+44'],
                    ], 'translations' => [
                        'en' => ['label' => 'Phone (Symfony)', 'placeholder' => '600 000 000', 'help' => 'Built-in Symfony prefix selector.'],
                        'es' => ['label' => 'Teléfono (Symfony)', 'placeholder' => '600 000 000', 'help' => 'Selector de prefijos integrado de Symfony.'],
                    ]],
                    ['name' => 'subject', 'type' => ContactFieldType::Text, 'required' => true, 'sort' => 3, 'translations' => [
                        'en' => ['label' => 'Subject', 'placeholder' => 'Brief summary'],
                        'es' => ['label' => 'Asunto', 'placeholder' => 'Resumen breve'],
                    ]],
                    ['name' => 'topic', 'type' => ContactFieldType::Select, 'required' => true, 'sort' => 4, 'options' => ['general_inquiry', 'technical_support', 'billing', 'partnership'], 'translations' => [
                        'en' => ['label' => 'Topic', 'placeholder' => 'Choose a topic', 'select_options' => ['General inquiry', 'Technical support', 'Billing', 'Partnership']],
                        'es' => ['label' => 'Temática', 'placeholder' => 'Elige una temática', 'select_options' => ['Consulta general', 'Soporte técnico', 'Facturación', 'Colaboración']],
                    ]],
                    ['name' => 'message', 'type' => ContactFieldType::Textarea, 'required' => true, 'sort' => 5, 'translations' => [
                        'en' => ['label' => 'Message', 'help' => 'Tell us how we can help.'],
                        'es' => ['label' => 'Mensaje', 'help' => 'Cuéntanos en qué podemos ayudarte.'],
                    ]],
                ],
            ],
            'job-application' => [
                'name'              => 'Job application',
                'enabled'           => true,
                'requireConsent'    => true,
                'privacyPolicyUrl'  => 'https://example.com/privacy',
                'notificationEmail' => 'hr@demo.example',
                'translations'      => [
                    'en' => [
                        'title'             => 'Apply for a position',
                        'description'       => 'Showcases URL, number, date, file upload and Phone Input Bundle phone field.',
                        'successMessage'    => 'Thanks! Our HR team will review your application.',
                        'consentLabel'      => 'I consent to HR processing my application data.',
                        'privacyPolicyText' => 'Recruitment privacy notice',
                    ],
                    'es' => [
                        'title'             => 'Solicitud de empleo',
                        'description'       => 'Incluye URL, número, fecha, subida de archivo y teléfono con Phone Input Bundle.',
                        'successMessage'    => '¡Gracias! RR. HH. revisará tu candidatura.',
                        'consentLabel'      => 'Consiento el tratamiento de mis datos para el proceso de selección.',
                        'privacyPolicyText' => 'Aviso de privacidad de selección',
                    ],
                ],
                'fields' => [
                    ['name' => 'full_name', 'type' => ContactFieldType::Text, 'required' => true, 'sort' => 1, 'translations' => [
                        'en' => ['label' => 'Full name'],
                        'es' => ['label' => 'Nombre completo'],
                    ]],
                    ['name' => 'email', 'type' => ContactFieldType::Email, 'required' => true, 'sort' => 2, 'translations' => [
                        'en' => ['label' => 'Email', 'placeholder' => 'you@example.com'],
                        'es' => ['label' => 'Email', 'placeholder' => 'tu@email.com'],
                    ]],
                    ['name' => 'phone', 'type' => ContactFieldType::Phone, 'required' => false, 'sort' => 3, 'options' => [
                        'widget'            => 'phone_input',
                        'default_country'   => 'ES',
                        'allowed_countries' => ['ES', 'US', 'GB', 'FR', 'DE'],
                    ], 'translations' => [
                        'en' => ['label' => 'Phone (Phone Input Bundle)', 'placeholder' => '600 000 000', 'help' => 'nowo-tech/phone-input-bundle with flags and search.'],
                        'es' => ['label' => 'Teléfono (Phone Input Bundle)', 'placeholder' => '600 000 000', 'help' => 'nowo-tech/phone-input-bundle con banderas y búsqueda.'],
                    ]],
                    ['name' => 'linkedin', 'type' => ContactFieldType::Url, 'required' => false, 'sort' => 4, 'translations' => [
                        'en' => ['label' => 'LinkedIn profile', 'placeholder' => 'https://linkedin.com/in/you'],
                        'es' => ['label' => 'Perfil de LinkedIn', 'placeholder' => 'https://linkedin.com/in/tu-usuario'],
                    ]],
                    ['name' => 'years_experience', 'type' => ContactFieldType::Number, 'required' => false, 'sort' => 5, 'translations' => [
                        'en' => ['label' => 'Years of experience', 'help' => 'Optional. Whole numbers only.'],
                        'es' => ['label' => 'Años de experiencia', 'help' => 'Opcional. Solo números enteros.'],
                    ]],
                    ['name' => 'available_from', 'type' => ContactFieldType::Date, 'required' => false, 'sort' => 6, 'translations' => [
                        'en' => ['label' => 'Available from'],
                        'es' => ['label' => 'Disponible desde'],
                    ]],
                    ['name' => 'role', 'type' => ContactFieldType::Select, 'required' => true, 'sort' => 7, 'options' => ['frontend', 'backend', 'devops', 'other'], 'translations' => [
                        'en' => ['label' => 'Role', 'select_options' => ['Frontend developer', 'Backend developer', 'DevOps engineer', 'Other']],
                        'es' => ['label' => 'Puesto', 'select_options' => ['Desarrollador frontend', 'Desarrollador backend', 'Ingeniero DevOps', 'Otro']],
                    ]],
                    ['name' => 'cover_letter', 'type' => ContactFieldType::Textarea, 'required' => false, 'sort' => 8, 'translations' => [
                        'en' => ['label' => 'Cover letter'],
                        'es' => ['label' => 'Carta de presentación'],
                    ]],
                    ['name' => 'cv', 'type' => ContactFieldType::File, 'required' => false, 'sort' => 9, 'translations' => [
                        'en' => ['label' => 'CV / Resume', 'help' => 'PDF or DOCX. Stored by DemoContactFormFileUploadHandler.'],
                        'es' => ['label' => 'CV', 'help' => 'PDF o DOCX. Guardado por DemoContactFormFileUploadHandler.'],
                    ]],
                    ['name' => 'accept_terms', 'type' => ContactFieldType::Checkbox, 'required' => true, 'sort' => 10, 'translations' => [
                        'en' => ['label' => 'I confirm the information provided is accurate'],
                        'es' => ['label' => 'Confirmo que la información facilitada es veraz'],
                    ]],
                ],
            ],
            'partner-inquiry' => [
                'name'              => 'Partner inquiry',
                'enabled'           => true,
                'requireConsent'    => false,
                'privacyPolicyUrl'  => 'https://example.com/partners',
                'notificationEmail' => 'partners@demo.example',
                'translations'      => [
                    'en' => [
                        'title'             => 'Become a partner',
                        'description'       => 'B2B lead form with budget, website, brochure upload and marketing opt-in.',
                        'successMessage'    => 'Thank you! Our partnerships team will be in touch.',
                        'consentLabel'      => null,
                        'privacyPolicyText' => 'Partner program terms',
                    ],
                    'es' => [
                        'title'             => 'Programa de partners',
                        'description'       => 'Formulario B2B con presupuesto, web, dossier y opt-in comercial.',
                        'successMessage'    => '¡Gracias! Nuestro equipo de partnerships contactará contigo.',
                        'consentLabel'      => null,
                        'privacyPolicyText' => 'Condiciones del programa de partners',
                    ],
                ],
                'fields' => [
                    ['name' => 'company_name', 'type' => ContactFieldType::Text, 'required' => true, 'sort' => 1, 'translations' => [
                        'en' => ['label' => 'Company name'],
                        'es' => ['label' => 'Nombre de la empresa'],
                    ]],
                    ['name' => 'contact_email', 'type' => ContactFieldType::Email, 'required' => true, 'sort' => 2, 'translations' => [
                        'en' => ['label' => 'Business email'],
                        'es' => ['label' => 'Email corporativo'],
                    ]],
                    ['name' => 'website', 'type' => ContactFieldType::Url, 'required' => false, 'sort' => 3, 'translations' => [
                        'en' => ['label' => 'Company website', 'placeholder' => 'https://company.example'],
                        'es' => ['label' => 'Sitio web', 'placeholder' => 'https://empresa.ejemplo'],
                    ]],
                    ['name' => 'annual_budget', 'type' => ContactFieldType::Number, 'required' => false, 'sort' => 4, 'translations' => [
                        'en' => ['label' => 'Estimated annual budget (EUR)', 'help' => 'Optional ballpark figure.'],
                        'es' => ['label' => 'Presupuesto anual estimado (EUR)', 'help' => 'Cifra orientativa opcional.'],
                    ]],
                    ['name' => 'partnership_type', 'type' => ContactFieldType::Select, 'required' => true, 'sort' => 5, 'options' => ['reseller', 'technology', 'referral'], 'translations' => [
                        'en' => ['label' => 'Partnership type', 'select_options' => ['Reseller', 'Technology integration', 'Referral']],
                        'es' => ['label' => 'Tipo de partnership', 'select_options' => ['Distribución', 'Integración tecnológica', 'Referidos']],
                    ]],
                    ['name' => 'proposal', 'type' => ContactFieldType::Textarea, 'required' => true, 'sort' => 6, 'translations' => [
                        'en' => ['label' => 'Tell us about your proposal'],
                        'es' => ['label' => 'Cuéntanos tu propuesta'],
                    ]],
                    ['name' => 'brochure', 'type' => ContactFieldType::File, 'required' => false, 'sort' => 7, 'translations' => [
                        'en' => ['label' => 'Company brochure', 'help' => 'Optional PDF about your company.'],
                        'es' => ['label' => 'Dossier corporativo', 'help' => 'PDF opcional sobre vuestra empresa.'],
                    ]],
                    ['name' => 'subscribe_updates', 'type' => ContactFieldType::Checkbox, 'required' => false, 'sort' => 8, 'translations' => [
                        'en' => ['label' => 'Keep me updated about partner news'],
                        'es' => ['label' => 'Quiero recibir novedades del programa de partners'],
                    ]],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function applyFormDefinition(ContactForm $form, string $slug, array $definition): void
    {
        $form
            ->setName($definition['name'])
            ->setSlug($slug)
            ->setEnabled($definition['enabled'])
            ->setRequireConsent($definition['requireConsent'])
            ->setPrivacyPolicyUrl($definition['privacyPolicyUrl'] ?? null)
            ->setNotificationEmail($definition['notificationEmail'] ?? null);

        foreach ($definition['translations'] as $locale => $data) {
            $translation = $form->findTranslation($locale);

            if (!$translation instanceof ContactFormTranslation) {
                $translation = (new ContactFormTranslation())->setLocale($locale);
                $form->addTranslation($translation);
            }

            $translation
                ->setTitle($data['title'])
                ->setDescription($data['description'])
                ->setSuccessMessage($data['successMessage'])
                ->setConsentLabel($data['consentLabel'] ?? null)
                ->setPrivacyPolicyText($data['privacyPolicyText'] ?? null);
        }

        $expectedNames = [];

        foreach ($definition['fields'] as $fieldDefinition) {
            $expectedNames[] = $fieldDefinition['name'];

            $this->upsertField(
                $form,
                $fieldDefinition['name'],
                $fieldDefinition['type'],
                $fieldDefinition['required'],
                $fieldDefinition['sort'],
                $fieldDefinition['options'] ?? null,
                $fieldDefinition['translations'],
            );
        }

        $this->removeUnexpectedFields($form, $expectedNames);
    }

    /**
     * @param list<string> $expectedNames
     */
    private function removeUnexpectedFields(ContactForm $form, array $expectedNames): void
    {
        foreach ($form->getFields()->toArray() as $field) {
            if (in_array($field->getName(), $expectedNames, true)) {
                continue;
            }

            $form->removeField($field);
        }
    }

    /**
     * @param list<string>|null $options
     * @param array<string, array<string, mixed>> $translations
     */
    private function upsertField(
        ContactForm $form,
        string $name,
        ContactFieldType $type,
        bool $required,
        int $sortOrder,
        ?array $options,
        array $translations,
    ): void {
        $field = $this->findFieldByName($form, $name);

        if (!$field instanceof ContactFormField) {
            $field = new ContactFormField();
            $form->addField($field);
        }

        $field
            ->setName($name)
            ->setType($type)
            ->setRequired($required)
            ->setSortOrder($sortOrder)
            ->setOptions($options);

        foreach ($translations as $locale => $data) {
            $translation = $field->findTranslation($locale);

            if (!$translation instanceof ContactFormFieldTranslation) {
                $translation = (new ContactFormFieldTranslation())->setLocale($locale);
                $field->addTranslation($translation);
            }

            $translation->setLabel($data['label']);

            if (array_key_exists('placeholder', $data)) {
                $translation->setPlaceholder($data['placeholder']);
            }

            if (array_key_exists('help', $data)) {
                $translation->setHelp($data['help']);
            }

            if (array_key_exists('select_options', $data)) {
                $translation->setSelectOptions($data['select_options']);
            }
        }
    }

    private function findFieldByName(ContactForm $form, string $name): ?ContactFormField
    {
        foreach ($form->getFields() as $field) {
            if ($field->getName() === $name) {
                return $field;
            }
        }

        return null;
    }
}
