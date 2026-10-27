<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Controller;

use Nowo\ContactFormBundle\Controller\ContactFormFieldAdminController;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactFormFieldTranslation;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\Form\ContactFormFieldContentStepType;
use Nowo\ContactFormBundle\Form\ContactFormFieldDefinitionStepType;
use Nowo\ContactFormBundle\Form\ContactFormFieldFlowType;
use Nowo\ContactFormBundle\Form\ContactFormFieldPhonePrefixesStepType;
use Nowo\ContactFormBundle\Form\ContactFormFieldSelectOptionsStepType;
use Nowo\ContactFormBundle\Form\ContactFormFieldTranslationType;
use Nowo\ContactFormBundle\Form\ContactFormFieldWizardStepTrait;
use Nowo\ContactFormBundle\Repository\ContactFormFieldRepository;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Nowo\ContactFormBundle\Repository\WorkerSafeServiceEntityRepository;
use Nowo\ContactFormBundle\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\InputFormField;

use function array_search;
use function is_string;
use function sprintf;
use function str_ends_with;

/**
 * Field wizard (Symfony FormFlow on the UiKit wizard scaffold) end to end: definition → type-specific step → content,
 * validation errors per step, edit, step URLs and redirects, delete through the confirm dialog form.
 */
#[CoversClass(ContactFormFieldAdminController::class)]
#[CoversClass(ContactFormFieldFlowType::class)]
#[CoversClass(ContactFormFieldDefinitionStepType::class)]
#[CoversClass(ContactFormFieldSelectOptionsStepType::class)]
#[CoversClass(ContactFormFieldPhonePrefixesStepType::class)]
#[CoversClass(ContactFormFieldContentStepType::class)]
#[CoversClass(ContactFormFieldTranslationType::class)]
#[CoversClass(ContactFormFieldRepository::class)]
#[CoversClass(ContactFormRepository::class)]
#[CoversClass(WorkerSafeServiceEntityRepository::class)]
#[CoversTrait(ContactFormFieldWizardStepTrait::class)]
final class ContactFormFieldWizardTest extends IntegrationTestCase
{
    public function testCreateASelectFieldThroughEveryStep(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();
        $form = $this->persistForm();
        $base = sprintf('/admin/contact-forms/%d/fields', $form->getId());

        $client->request('GET', $base . '/new');
        self::assertResponseRedirects($base . '/new/definition');

        $crawler = $client->request('GET', $base . '/new/definition');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="contact-form-field-wizard"] [aria-current="step"]', 'Field settings');

        $this->submitStep($client, $crawler, 'Continue', [
            'contact_form_field_flow[definition][name]'      => 'topic',
            'contact_form_field_flow[definition][type]'      => 'select',
            'contact_form_field_flow[definition][required]'  => true,
            'contact_form_field_flow[definition][sortOrder]' => '2',
        ]);
        self::assertResponseRedirects($base . '/new/select_options');

        $crawler = $client->request('GET', $base . '/new/select_options');
        self::assertResponseIsSuccessful();
        $this->submitStep($client, $crawler, 'Continue', ['contact_form_field_flow[select_options][optionsMachineNamesLines]' => "Bad Value\nsales\nsales"]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Each machine name must start with a letter', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Machine names must be unique.', (string) $client->getResponse()->getContent());

        $crawler = $client->getCrawler();
        $this->submitStep($client, $crawler, 'Continue', ['contact_form_field_flow[select_options][optionsMachineNamesLines]' => "general\nsales"]);
        self::assertResponseRedirects($base . '/new/content');

        $crawler = $client->request('GET', $base . '/new/content');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="field-translation-tabs"]');
        $this->submitStep($client, $crawler, 'Save field', [
            'contact_form_field_flow[content][translations][0][label]'              => 'Topic',
            'contact_form_field_flow[content][translations][0][selectOptionsLines]' => 'General',
            'contact_form_field_flow[content][translations][1][label]'              => 'Tema',
            'contact_form_field_flow[content][translations][1][selectOptionsLines]' => "General\nVentas",
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Provide exactly 2 option label(s)', (string) $client->getResponse()->getContent());

        $this->submitStep($client, $client->getCrawler(), 'Save field', [
            'contact_form_field_flow[content][translations][0][label]'              => 'Topic',
            'contact_form_field_flow[content][translations][0][selectOptionsLines]' => "General\nSales",
            'contact_form_field_flow[content][translations][1][label]'              => 'Tema',
            'contact_form_field_flow[content][translations][1][selectOptionsLines]' => "General\nVentas",
        ]);
        self::assertResponseRedirects($base);
        $client->followRedirect();
        self::assertStringContainsString('Field created.', (string) $client->getResponse()->getContent());
        self::assertSelectorTextContains('[data-testid="contact-form-fields"] tbody', 'topic');

        $field = $this->field('topic');
        self::assertSame(['general', 'sales'], $field->getOptions());
        self::assertSame(['General', 'Ventas'], $field->findTranslation('es')?->getSelectOptions());
    }

    /**
     * I3 / I6: field names are safe identifiers (not the reserved public-form children), and without a file upload
     * service a file field is refused instead of silently discarding uploads.
     */
    public function testDefinitionRejectsUnsafeNamesAndFileFieldsWithoutAnUploadHandler(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();
        $form = $this->persistForm();
        $base = sprintf('/admin/contact-forms/%d/fields', $form->getId());

        foreach (['gdpr_consent' => 'is reserved by the public form', '_token' => 'is reserved by the public form', 'Bad Name' => 'Use lowercase letters', '1st' => 'Use lowercase letters'] as $name => $message) {
            $crawler = $client->request('GET', $base . '/new/definition');
            $this->submitStep($client, $crawler, 'Continue', [
                'contact_form_field_flow[definition][name]' => $name,
                'contact_form_field_flow[definition][type]' => 'text',
            ]);
            self::assertResponseStatusCodeSame(422, $name);
            self::assertStringContainsString($message, (string) $client->getResponse()->getContent());
        }

        $crawler = $client->request('GET', $base . '/new/definition');
        $this->submitStep($client, $crawler, 'Continue', [
            'contact_form_field_flow[definition][name]' => 'cv',
            'contact_form_field_flow[definition][type]' => 'file',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('File fields need a file upload handler', (string) $client->getResponse()->getContent());
    }

    /**
     * I2: a tampered hidden field type is a validation error, not a 500.
     */
    public function testATamperedHiddenTypeIsAValidationError(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();
        $form  = $this->persistForm();
        $field = $this->persistField($form, 'email', ContactFieldType::Email);
        $base  = sprintf('/admin/contact-forms/%d/fields/%d', $form->getId(), $field->getId());

        $crawler = $client->request('GET', $base . '/edit/definition');
        $this->submitStep($client, $crawler, 'Continue', []);
        self::assertResponseRedirects($base . '/edit/content');

        $crawler = $client->request('GET', $base . '/edit/content');
        $form    = $crawler->selectButton('Save field')->form();
        $type    = $form->get('contact_form_field_flow[content][type]');
        self::assertInstanceOf(InputFormField::class, $type);
        $type->setValue('shell');
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateAPhoneFieldValidatesThePrefixes(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();
        $form = $this->persistForm();
        $base = sprintf('/admin/contact-forms/%d/fields', $form->getId());

        $crawler = $client->request('GET', $base . '/new/definition');
        $this->submitStep($client, $crawler, 'Continue', [
            'contact_form_field_flow[definition][name]'      => 'phone',
            'contact_form_field_flow[definition][type]'      => 'phone',
            'contact_form_field_flow[definition][sortOrder]' => '1',
        ]);
        self::assertResponseRedirects($base . '/new/phone_prefixes');

        $crawler = $client->request('GET', $base . '/new/phone_prefixes');
        self::assertResponseIsSuccessful();
        $this->submitStep($client, $crawler, 'Continue', [
            'contact_form_field_flow[phone_prefixes][phonePrefixesLines]' => "34\n+1\n+1",
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Each prefix must start with +', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Dialing prefixes must be unique.', (string) $client->getResponse()->getContent());

        $this->submitStep($client, $client->getCrawler(), 'Continue', [
            'contact_form_field_flow[phone_prefixes][phonePrefixesLines]'         => "+34\n+1",
            'contact_form_field_flow[phone_prefixes][phoneAllowedCountriesLines]' => "es\nESP",
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Each country must be a two-letter ISO code', (string) $client->getResponse()->getContent());

        $this->submitStep($client, $client->getCrawler(), 'Continue', [
            'contact_form_field_flow[phone_prefixes][phonePrefixesLines]'         => "+34\n+1",
            'contact_form_field_flow[phone_prefixes][phoneAllowedCountriesLines]' => "es\nES",
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Allowed countries must be unique.', (string) $client->getResponse()->getContent());

        $this->submitStep($client, $client->getCrawler(), 'Continue', [
            'contact_form_field_flow[phone_prefixes][phonePrefixesLines]'         => "+34\n+1",
            'contact_form_field_flow[phone_prefixes][phoneDefaultCountry]'        => 'es',
            'contact_form_field_flow[phone_prefixes][phoneAllowedCountriesLines]' => "es\nus",
        ]);
        self::assertResponseRedirects($base . '/new/content');

        $crawler = $client->request('GET', $base . '/new/content');
        $this->submitStep($client, $crawler, 'Save field', [
            'contact_form_field_flow[content][translations][0][label]' => 'Phone',
            'contact_form_field_flow[content][translations][1][label]' => 'Teléfono',
        ]);
        self::assertResponseRedirects($base);

        self::assertSame(['widget' => 'symfony', 'prefixes' => ['+34', '+1']], $this->field('phone')->getOptions());
    }

    public function testEditAFieldAndNavigateTheSteps(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();
        $form  = $this->persistForm();
        $field = $this->persistField($form, 'email', ContactFieldType::Email);
        $base  = sprintf('/admin/contact-forms/%d/fields/%d', $form->getId(), $field->getId());

        $client->request('GET', $base . '/edit');
        self::assertResponseRedirects($base . '/edit/definition');

        // A step the field type skips still renders (the step indicator lists it after the visible steps).
        $client->request('GET', $base . '/edit/select_options');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[aria-current="step"]', 'Select values');

        $crawler = $client->request('GET', $base . '/edit/content');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists(sprintf('[data-nowo-ui-region="steps"] a[href="%s/edit/definition"]', $base));
        $this->submitStep($client, $crawler, 'Back', []);
        self::assertSelectorTextContains('[aria-current="step"]', 'Field settings');

        $crawler = $client->request('GET', $base . '/edit/definition');
        $this->submitStep($client, $crawler, 'Continue', [
            'contact_form_field_flow[definition][name]'     => 'email_address',
            'contact_form_field_flow[definition][required]' => true,
        ]);
        self::assertResponseRedirects($base . '/edit/content');

        $crawler = $client->request('GET', $base . '/edit/content');
        $this->submitStep($client, $crawler, 'Save field', [
            'contact_form_field_flow[content][translations][0][label]'       => 'Email address',
            'contact_form_field_flow[content][translations][0][placeholder]' => 'you@example.com',
        ]);
        self::assertResponseRedirects(sprintf('/admin/contact-forms/%d/fields', $form->getId()));
        $client->followRedirect();
        self::assertStringContainsString('Field updated.', (string) $client->getResponse()->getContent());
        $edited = $this->field('email_address');
        self::assertSame($field->getId(), $edited->getId());
        self::assertTrue($edited->isRequired());
        $en = $edited->findTranslation('en');
        self::assertNotNull($en);
        self::assertSame('Email address', $en->getLabel());
        self::assertSame('you@example.com', $en->getPlaceholder());
        self::assertCount(2, $edited->getTranslations());
    }

    public function testDeleteAFieldThroughTheConfirmDialogForm(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();
        $form  = $this->persistForm(true);
        $field = $this->persistField($form, 'email', ContactFieldType::Email);

        $crawler = $client->request('GET', sprintf('/admin/contact-forms/%d/fields', $form->getId()));
        self::assertSelectorTextContains('[data-testid="contact-form-fields"] tbody', 'gdpr_consent');
        $client->submit($crawler->filter(sprintf('form[action="/admin/contact-forms/%d/fields/%d/delete"]', $form->getId(), $field->getId()))->form());

        self::assertResponseRedirects(sprintf('/admin/contact-forms/%d/fields', $form->getId()));
        $client->followRedirect();
        self::assertStringContainsString('Field deleted.', (string) $client->getResponse()->getContent());
    }

    public function testDeleteRejectsAnInvalidTokenAndForeignFields(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();
        $form  = $this->persistForm();
        $other = $this->persistForm(false, 'other');
        $field = $this->persistField($form, 'email', ContactFieldType::Email);

        $client->request('POST', sprintf('/admin/contact-forms/%d/fields/%d/delete', $form->getId(), $field->getId()), ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', sprintf('/admin/contact-forms/%d/fields/%d/edit/definition', $other->getId(), $field->getId()));
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', sprintf('/admin/contact-forms/%d/fields/new/definition', 999));
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<string, bool|string> $values
     */
    private function submitStep(KernelBrowser $client, Crawler $crawler, string $button, array $values): void
    {
        $form = $crawler->selectButton($button)->form();
        foreach ($values as $name => $value) {
            if (str_ends_with($name, '[type]') && is_string($value)) {
                // The choice values are the indexes of ContactFieldType::cases().
                $value = (string) array_search(ContactFieldType::from($value), ContactFieldType::cases(), true);
            }
            if ($value === true) {
                $checkbox = $form->get($name);
                self::assertInstanceOf(ChoiceFormField::class, $checkbox);
                $checkbox->tick();
                continue;
            }
            $form->setValues([$name => $value]);
        }
        $client->submit($form);
    }

    private function persistForm(bool $requireConsent = false, string $slug = 'support'): ContactForm
    {
        $form = (new ContactForm())
            ->setName('Support ' . $slug)
            ->setSlug($slug)
            ->setRequireConsent($requireConsent)
            ->addTranslation((new ContactFormTranslation())->setLocale('en')->setTitle('Contact us'));

        $em = self::getEntityManager();
        $em->persist($form);
        $em->flush();

        return $form;
    }

    private function persistField(ContactForm $form, string $name, ContactFieldType $type): ContactFormField
    {
        $field = (new ContactFormField())->setName($name)->setType($type)->setForm($form);
        foreach (['en' => 'Email', 'es' => 'Correo'] as $locale => $label) {
            $field->addTranslation((new ContactFormFieldTranslation())->setLocale($locale)->setLabel($label));
        }

        $em = self::getEntityManager();
        $em->persist($field);
        $em->flush();

        return $field;
    }

    private function field(string $name): ContactFormField
    {
        $em = self::getEntityManager();
        $em->clear();
        $field = $em->getRepository(ContactFormField::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(ContactFormField::class, $field);

        return $field;
    }
}
