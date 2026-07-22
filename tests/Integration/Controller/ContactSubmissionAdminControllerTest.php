<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Controller;

use Nowo\ContactFormBundle\Controller\ContactSubmissionAdminController;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Entity\ContactSubmissionValue;
use Nowo\ContactFormBundle\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

use function sprintf;

#[CoversClass(ContactSubmissionAdminController::class)]
final class ContactSubmissionAdminControllerTest extends IntegrationTestCase
{
    public function testIndexListsSubmissions(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();

        $form = (new ContactForm())
            ->setName('Demo')
            ->setSlug('demo')
            ->addTranslation((new ContactFormTranslation())->setLocale('en')->setTitle('Demo'));

        $submission = (new ContactSubmission())->setForm($form)->setLocale('en');

        $em = self::getEntityManager();
        $em->persist($form);
        $em->persist($submission);
        $em->flush();

        $client->request('GET', sprintf('/admin/contact-forms/%d/submissions', $form->getId()));

        self::assertResponseIsSuccessful();
    }

    public function testShowReturns404WhenSubmissionBelongsToAnotherForm(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();

        [$form, $submission] = $this->seedSubmission();

        $other = (new ContactForm())
            ->setName('Other')
            ->setSlug('other')
            ->addTranslation((new ContactFormTranslation())->setLocale('en')->setTitle('Other'));

        $em = self::getEntityManager();
        $em->persist($other);
        $em->flush();

        $client->request('GET', sprintf(
            '/admin/contact-forms/%d/submissions/%d',
            $other->getId(),
            $submission->getId(),
        ));

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return array{0: ContactForm, 1: ContactSubmission}
     */
    private function seedSubmission(): array
    {
        $form = (new ContactForm())
            ->setName('Demo')
            ->setSlug('demo')
            ->addTranslation((new ContactFormTranslation())->setLocale('en')->setTitle('Demo'));

        $submission = (new ContactSubmission())->setForm($form)->setLocale('en');
        $submission->addValue(
            (new ContactSubmissionValue())->setFieldName('email')->setValue('user@example.com'),
        );

        $em = self::getEntityManager();
        $em->persist($form);
        $em->persist($submission);
        $em->flush();

        return [$form, $submission];
    }
}
