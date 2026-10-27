<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Controller;

use DateTimeImmutable;
use Nowo\ContactFormBundle\Controller\ContactSubmissionAdminController;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Entity\ContactSubmissionValue;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Nowo\ContactFormBundle\Repository\ContactSubmissionRepository;
use Nowo\ContactFormBundle\Repository\WorkerSafeServiceEntityRepository;
use Nowo\ContactFormBundle\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

use function sprintf;

/**
 * Submissions on the UiKit list / show scaffolds: detail sections, delete from the list and from the detail header
 * through the confirm dialog form (CSRF), invalid token, unknown form, pagination clamp.
 */
#[CoversClass(ContactSubmissionAdminController::class)]
#[CoversClass(ContactSubmissionRepository::class)]
#[CoversClass(ContactFormRepository::class)]
#[CoversClass(WorkerSafeServiceEntityRepository::class)]
final class ContactSubmissionAdminCrudTest extends IntegrationTestCase
{
    public function testShowAndDeleteASubmission(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();
        $form       = $this->persistForm();
        $submission = $this->persistSubmission($form, 'ada@example.com');
        $base       = sprintf('/admin/contact-forms/%d/submissions', $form->getId());

        $crawler = $client->request('GET', sprintf('%s/%d', $base, $submission->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="contact-form-submission"]', 'Submitted values');
        self::assertSelectorTextContains('[data-testid="contact-form-submission"]', 'ada@example.com');
        self::assertSelectorTextContains('[data-testid="contact-form-submission"]', 'Consent given at');

        $client->submit($crawler->filter(sprintf('form[action="%s/%d/delete"]', $base, $submission->getId()))->first()->form());
        self::assertResponseRedirects($base);
        $client->followRedirect();
        self::assertStringContainsString('Submission deleted.', (string) $client->getResponse()->getContent());
        self::assertSelectorExists('[data-testid="contact-form-submissions"]');
    }

    public function testDeleteFromTheListAndRejectAnInvalidToken(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();
        $form       = $this->persistForm();
        $submission = $this->persistSubmission($form, null);
        $base       = sprintf('/admin/contact-forms/%d/submissions', $form->getId());

        $client->request('POST', sprintf('%s/%d/delete', $base, $submission->getId()), ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);

        $crawler = $client->request('GET', $base);
        self::assertSelectorTextContains('[data-testid="contact-form-submissions"] tbody', 'Anonymous');
        $client->submit($crawler->filter(sprintf('form[action="%s/%d/delete"]', $base, $submission->getId()))->form());
        self::assertResponseRedirects($base);
    }

    public function testIndexClampsThePageAndRejectsUnknownForms(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();
        $form = $this->persistForm();
        $this->persistSubmission($form, 'ada@example.com');

        $client->request('GET', sprintf('/admin/contact-forms/%d/submissions?page=9', $form->getId()));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $client->getCrawler()->filter('[data-testid="contact-form-submissions"] tbody tr'));

        $client->request('GET', '/admin/contact-forms/999/submissions');
        self::assertResponseStatusCodeSame(404);
    }

    private function persistForm(): ContactForm
    {
        $form = (new ContactForm())
            ->setName('Support')
            ->setSlug('support')
            ->addTranslation((new ContactFormTranslation())->setLocale('en')->setTitle('Contact us'));

        $em = self::getEntityManager();
        $em->persist($form);
        $em->flush();

        return $form;
    }

    private function persistSubmission(ContactForm $form, ?string $client): ContactSubmission
    {
        $submission = (new ContactSubmission())
            ->setForm($form)
            ->setLocale('en')
            ->setCreatedAt(new DateTimeImmutable('2026-10-27 09:30:00'))
            ->setConsentGivenAt($client !== null ? new DateTimeImmutable('2026-10-27 09:30:00') : null);
        if ($client !== null) {
            $submission->setClientId(7)->setClientLabel($client);
        }
        $submission->addValue((new ContactSubmissionValue())->setFieldName('email')->setValue('ada@example.com'));

        $em = self::getEntityManager();
        $em->persist($submission);
        $em->flush();

        return $submission;
    }
}
