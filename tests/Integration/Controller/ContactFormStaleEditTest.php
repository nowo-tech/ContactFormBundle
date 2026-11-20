<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Controller;

use Nowo\ContactFormBundle\Concurrency\StaleVersionException;
use Nowo\ContactFormBundle\Controller\ContactFormAdminController;
use Nowo\ContactFormBundle\Controller\ContactFormFieldAdminController;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactFormFieldTranslation;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\Form\ContactFormFieldDefinitionStepType;
use Nowo\ContactFormBundle\Form\ContactFormType;
use Nowo\ContactFormBundle\Form\RowVersionField;
use Nowo\ContactFormBundle\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

use function sprintf;

/**
 * REQ-RUNTIME-003 rule 4 (1.12) through the real admin pages: a page that another admin's save has outdated is answered
 * 409 + `X-Error-Code: CONTACT_FORM_STALE_VERSION` with the message, the text typed is kept, nothing is stored, and
 * after a reload the save goes through. Same for the field wizard, which keeps the version of its first page to the
 * last step.
 */
#[CoversClass(ContactFormAdminController::class)]
#[CoversClass(ContactFormFieldAdminController::class)]
#[CoversClass(ContactFormType::class)]
#[CoversClass(ContactFormFieldDefinitionStepType::class)]
#[CoversClass(RowVersionField::class)]
#[CoversClass(StaleVersionException::class)]
final class ContactFormStaleEditTest extends IntegrationTestCase
{
    public function testAStaleFormEditAnswers409WithTheMessageAndStoresNothing(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();
        $form = $this->persistForm();
        $id   = (int) $form->getId();

        $crawler = $client->request('GET', sprintf('/admin/contact-forms/%d/edit', $id));
        self::assertCount(1, $crawler->filter('input[type="hidden"][name="contact_form[rowVersion]"][value="1"]'), 'the page carries its row version');

        // Another admin saves the form while this page is open.
        $this->sql('UPDATE nowo_contact_form SET name = ?, row_version = 2 WHERE id = ?', ['Other admin', $id]);

        $values  = $crawler->filter('[data-testid="contact-form-form"] form[name="contact_form"]')->form();
        $crawler = $client->submit($values, ['contact_form[name]' => 'Stale page']);
        $answer  = $client->getResponse();

        self::assertSame(409, $answer->getStatusCode());
        self::assertSame('CONTACT_FORM_STALE_VERSION', $answer->headers->get('X-Error-Code'));
        self::assertFalse($answer->headers->has('X-Conflict-Code'), 'a domain answer, not CONFLICT_001 / CONFLICT_002');
        self::assertFalse($answer->headers->has('Retry-After'));
        self::assertStringContainsString('changed by someone else', (string) $answer->getContent());
        self::assertSame('Stale page', $crawler->filter('input[name="contact_form[name]"]')->attr('value'), 'the admin keeps the text typed');
        self::assertSame('Other admin', $this->scalar('SELECT name FROM nowo_contact_form WHERE id = ?', [$id]));
        self::assertSame(2, (int) $this->scalar('SELECT row_version FROM nowo_contact_form WHERE id = ?', [$id]));

        // The page opened again carries the new version and saves.
        $crawler = $client->request('GET', sprintf('/admin/contact-forms/%d/edit', $id));
        self::assertCount(1, $crawler->filter('input[type="hidden"][name="contact_form[rowVersion]"][value="2"]'));
        $client->submit($crawler->filter('[data-testid="contact-form-form"] form[name="contact_form"]')->form(), ['contact_form[name]' => 'Fresh page']);
        self::assertResponseRedirects('/admin/contact-forms/');
        self::assertSame('Fresh page', $this->scalar('SELECT name FROM nowo_contact_form WHERE id = ?', [$id]));
        self::assertSame(3, (int) $this->scalar('SELECT row_version FROM nowo_contact_form WHERE id = ?', [$id]));
    }

    public function testASubmissionWithoutTheVersionTokenIsRefused(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();
        $id = (int) $this->persistForm()->getId();

        $crawler = $client->request('GET', sprintf('/admin/contact-forms/%d/edit', $id));
        $form    = $crawler->filter('[data-testid="contact-form-form"] form[name="contact_form"]')->form();
        $form->remove('contact_form[rowVersion]');
        $client->submit($form, ['contact_form[name]' => 'forged']);

        self::assertSame(409, $client->getResponse()->getStatusCode(), 'a template that drops the token never overwrites silently');
        self::assertSame('Support', $this->scalar('SELECT name FROM nowo_contact_form WHERE id = ?', [$id]));
    }

    public function testAStaleFieldWizardAnswers409AtTheLastStepAndStartsAgainOnTheStoredField(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();
        $form  = $this->persistForm();
        $field = $this->persistField($form);
        $base  = sprintf('/admin/contact-forms/%d/fields/%d/edit', $form->getId(), $field->getId());

        $crawler = $client->request('GET', $base . '/definition');
        self::assertCount(1, $crawler->filter('input[type="hidden"][name="contact_form_field_flow[definition][flowBaseVersion]"][value="1"]'), 'the first step carries the version of its page');

        // Another admin saves the field while the wizard is open.
        $this->sql('UPDATE nowo_contact_form_field SET name = ?, row_version = 2 WHERE id = ?', ['other_admin', $field->getId()]);

        $this->submitStep($client, $crawler, 'Continue', ['contact_form_field_flow[definition][name]' => 'stale_name']);
        self::assertResponseRedirects($base . '/content');
        $crawler = $client->request('GET', $base . '/content');
        $this->submitStep($client, $crawler, 'Save field', ['contact_form_field_flow[content][translations][0][label]' => 'Stale label']);
        $answer = $client->getResponse();

        self::assertSame(409, $answer->getStatusCode());
        self::assertSame('CONTACT_FORM_STALE_VERSION', $answer->headers->get('X-Error-Code'));
        self::assertFalse($answer->headers->has('X-Conflict-Code'));
        self::assertFalse($answer->headers->has('Retry-After'));
        self::assertStringContainsString('changed by someone else', (string) $answer->getContent());
        self::assertSame('other_admin', $this->scalar('SELECT name FROM nowo_contact_form_field WHERE id = ?', [$field->getId()]));
        self::assertSame('Email', $this->scalar("SELECT label FROM nowo_contact_form_field_translation WHERE field_id = ? AND locale = 'en'", [$field->getId()]), 'nothing of the stale wizard');
        self::assertSelectorTextContains('[aria-current="step"]', 'Field settings');
        self::assertCount(1, $client->getCrawler()->filter('input[type="hidden"][name="contact_form_field_flow[definition][flowBaseVersion]"][value="2"]'), 'the wizard starts again on the stored version');
        self::assertSame('other_admin', $client->getCrawler()->filter('input[name="contact_form_field_flow[definition][name]"]')->attr('value'), 'with the stored field, not the stale draft');

        // A wizard started again saves.
        $crawler = $client->request('GET', $base . '/definition');
        $this->submitStep($client, $crawler, 'Continue', ['contact_form_field_flow[definition][name]' => 'fresh_name']);
        $crawler = $client->request('GET', $base . '/content');
        $this->submitStep($client, $crawler, 'Save field', ['contact_form_field_flow[content][translations][0][label]' => 'Fresh label']);
        self::assertResponseRedirects(sprintf('/admin/contact-forms/%d/fields', $form->getId()));
        self::assertSame('fresh_name', $this->scalar('SELECT name FROM nowo_contact_form_field WHERE id = ?', [$field->getId()]));
        self::assertSame(3, (int) $this->scalar('SELECT row_version FROM nowo_contact_form_field WHERE id = ?', [$field->getId()]));
    }

    public function testAFieldWizardWithAForgedOrMissingTokenOnItsFirstStepIsStale(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();
        $form  = $this->persistForm();
        $field = $this->persistField($form);
        $base  = sprintf('/admin/contact-forms/%d/fields/%d/edit', $form->getId(), $field->getId());

        $crawler                                                      = $client->request('GET', $base . '/definition');
        $step                                                         = $crawler->selectButton('Continue')->form();
        $step['contact_form_field_flow[definition][flowBaseVersion]'] = 'abc';
        $client->submit($step);
        self::assertResponseRedirects($base . '/content');

        $crawler = $client->request('GET', $base . '/content');
        $this->submitStep($client, $crawler, 'Save field', ['contact_form_field_flow[content][translations][0][label]' => 'Forged']);

        self::assertSame(409, $client->getResponse()->getStatusCode());
        self::assertSame('Email', $this->scalar("SELECT label FROM nowo_contact_form_field_translation WHERE field_id = ? AND locale = 'en'", [$field->getId()]));
    }

    public function testANewFieldDoesNotNeedAToken(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();
        $form = $this->persistForm();
        $base = sprintf('/admin/contact-forms/%d/fields/new', $form->getId());

        $crawler = $client->request('GET', $base . '/definition');
        $this->submitStep($client, $crawler, 'Continue', ['contact_form_field_flow[definition][name]' => 'phone_note']);
        $crawler = $client->request('GET', $base . '/content');
        $this->submitStep($client, $crawler, 'Save field', ['contact_form_field_flow[content][translations][0][label]' => 'Note']);

        self::assertResponseRedirects(sprintf('/admin/contact-forms/%d/fields', $form->getId()));
        self::assertSame(1, (int) $this->scalar("SELECT row_version FROM nowo_contact_form_field WHERE name = 'phone_note'", []));
    }

    /**
     * @param array<string, string> $values
     */
    private function submitStep(KernelBrowser $client, Crawler $crawler, string $button, array $values): void
    {
        $form = $crawler->selectButton($button)->form();
        $form->setValues($values);
        $client->submit($form);
    }

    private function persistForm(): ContactForm
    {
        $form = (new ContactForm())
            ->setName('Support')
            ->setSlug('support')
            ->addTranslation((new ContactFormTranslation())->setLocale('en')->setTitle('Contact us'))
            ->addTranslation((new ContactFormTranslation())->setLocale('es')->setTitle('Contacto'));
        $em = self::getEntityManager();
        $em->persist($form);
        $em->flush();

        return $form;
    }

    private function persistField(ContactForm $form): ContactFormField
    {
        $field = (new ContactFormField())->setName('email')->setType(ContactFieldType::Email)->setForm($form);
        foreach (['en' => 'Email', 'es' => 'Correo'] as $locale => $label) {
            $field->addTranslation((new ContactFormFieldTranslation())->setLocale($locale)->setLabel($label));
        }
        $em = self::getEntityManager();
        $em->persist($field);
        $em->flush();

        return $field;
    }

    /**
     * @param list<mixed> $params
     */
    private function sql(string $sql, array $params): void
    {
        self::getEntityManager()->getConnection()->executeStatement($sql, $params);
    }

    /**
     * @param list<mixed> $params
     */
    private function scalar(string $sql, array $params): mixed
    {
        return self::getEntityManager()->getConnection()->fetchOne($sql, $params);
    }
}
