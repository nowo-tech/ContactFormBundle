<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Controller;

use Nowo\ContactFormBundle\Controller\ContactFormAdminController;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Form\ContactFormTranslationType;
use Nowo\ContactFormBundle\Form\ContactFormType;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Nowo\ContactFormBundle\Repository\WorkerSafeServiceEntityRepository;
use Nowo\ContactFormBundle\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function sprintf;

/**
 * Contact form CRUD through the UiKit pages: create / edit (valid and invalid), delete through the confirm dialog form
 * (CSRF), pagination clamp and the interface-language switch redirects.
 */
#[CoversClass(ContactFormAdminController::class)]
#[CoversClass(ContactFormType::class)]
#[CoversClass(ContactFormTranslationType::class)]
#[CoversClass(ContactFormRepository::class)]
#[CoversClass(WorkerSafeServiceEntityRepository::class)]
final class ContactFormAdminCrudTest extends IntegrationTestCase
{
    public function testCreateEditAndDeleteAContactForm(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();

        $crawler = $client->request('GET', '/admin/contact-forms/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="contact-form-form"] form');
        self::assertSelectorExists('[data-testid="form-translation-tabs"]');

        $form                                         = $crawler->filter('[data-testid="contact-form-form"] form')->form();
        $form['contact_form[name]']                   = 'Support';
        $form['contact_form[slug]']                   = 'support';
        $form['contact_form[retentionDays]']          = '30';
        $form['contact_form[translations][0][title]'] = 'Contact us';
        $form['contact_form[translations][1][title]'] = 'Contacto';
        $client->submit($form);

        self::assertResponseRedirects('/admin/contact-forms/');
        $client->followRedirect();
        self::assertSelectorTextContains('[data-testid="contact-form-forms"] tbody', 'Support');
        self::assertStringContainsString('Contact form created.', (string) $client->getResponse()->getContent());

        $contactForm = self::getEntityManager()->getRepository(ContactForm::class)->findOneBy(['slug' => 'support']);
        self::assertInstanceOf(ContactForm::class, $contactForm);
        $id = (int) $contactForm->getId();

        $crawler = $client->request('GET', sprintf('/admin/contact-forms/%d/edit', $id));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists(sprintf('a[href="/admin/contact-forms/%d/fields"]', $id));
        self::assertSelectorExists(sprintf('a[href="/admin/contact-forms/%d/submissions"]', $id));
        $form                       = $crawler->filter('[data-testid="contact-form-form"] form[name="contact_form"]')->form();
        $form['contact_form[name]'] = 'Support desk';
        $client->submit($form);
        self::assertResponseRedirects('/admin/contact-forms/');
        $client->followRedirect();
        self::assertStringContainsString('Contact form updated.', (string) $client->getResponse()->getContent());

        $crawler = $client->request('GET', '/admin/contact-forms/');
        $delete  = $crawler->filter(sprintf('form[action="/admin/contact-forms/%d/delete"]', $id));
        self::assertCount(1, $delete, 'one delete form (confirm dialog) per row');
        self::assertCount(1, $crawler->filter('dialog[data-nowo-ui-confirm]'));
        $client->submit($delete->form());

        self::assertResponseRedirects('/admin/contact-forms/');
        $client->followRedirect();
        self::assertStringContainsString('Contact form deleted.', (string) $client->getResponse()->getContent());
        self::getEntityManager()->clear();
        self::assertNull(self::getEntityManager()->getRepository(ContactForm::class)->find($id));
    }

    public function testInvalidSubmissionRendersTheFormWith422(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();
        $crawler = $client->request('GET', '/admin/contact-forms/new');

        $form                       = $crawler->filter('[data-testid="contact-form-form"] form')->form();
        $form['contact_form[name]'] = '';
        $form['contact_form[slug]'] = 'Not A Slug!';
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[data-testid="contact-form-form"]');
    }

    public function testDeleteRejectsAnInvalidCsrfToken(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();
        $form = $this->persistForm('support');

        $client->request('POST', sprintf('/admin/contact-forms/%d/delete', $form->getId()), ['_token' => 'invalid']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testIndexClampsThePageAndPaginates(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();
        for ($i = 1; $i <= 21; ++$i) {
            $this->persistForm(sprintf('form-%02d', $i));
        }

        $client->request('GET', '/admin/contact-forms/?page=99');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $client->getCrawler()->filter('[data-testid="contact-form-forms"] tbody tr'));
        self::assertSelectorExists('[data-nowo-ui-region="pagination"]');
    }

    public function testSwitchLocaleRedirects(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();

        $client->request('GET', '/admin/contact-forms/locale/es?redirect=/admin/contact-forms/new');
        self::assertResponseRedirects('/admin/contact-forms/new');

        $client->request('GET', '/admin/contact-forms/locale/en?redirect=//evil.example', [], [], ['HTTP_REFERER' => '']);
        self::assertResponseRedirects('/admin/contact-forms/');

        $this->switchWithReferer($client, 'http://localhost/admin/contact-forms/3/submissions?page=2');
        self::assertResponseRedirects('/admin/contact-forms/3/submissions?page=2');

        $this->switchWithReferer($client, 'http://localhost/admin/contact-forms/3/fields');
        self::assertResponseRedirects('/admin/contact-forms/3/fields');

        $this->switchWithReferer($client, 'https://evil.example/admin/contact-forms/');
        self::assertResponseRedirects('/admin/contact-forms/');

        $this->switchWithReferer($client, 'http://localhost/elsewhere');
        self::assertResponseRedirects('/admin/contact-forms/');
    }

    public function testAdminPagesFollowTheSessionLocale(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();

        $client->request('GET', '/admin/contact-forms/locale/es');
        $client->request('GET', '/admin/contact-forms/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Formularios de contacto', (string) $client->getResponse()->getContent());
    }

    private function switchWithReferer(KernelBrowser $client, string $referer): void
    {
        $client->request('GET', '/admin/contact-forms/locale/es', [], [], ['HTTP_REFERER' => $referer]);
    }

    private function persistForm(string $slug): ContactForm
    {
        $form = (new ContactForm())
            ->setName('Form ' . $slug)
            ->setSlug($slug)
            ->addTranslation((new ContactFormTranslation())->setLocale('en')->setTitle('Contact ' . $slug));

        $em = self::getEntityManager();
        $em->persist($form);
        $em->flush();

        return $form;
    }
}
