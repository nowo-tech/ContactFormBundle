<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Controller;

use Nowo\ContactFormBundle\Controller\ContactFormAdminController;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

use function sprintf;

#[CoversClass(ContactFormAdminController::class)]
final class ContactFormAdminControllerTest extends IntegrationTestCase
{
    public function testIndexListsForms(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();

        $form = (new ContactForm())
            ->setName('Demo')
            ->setSlug('demo')
            ->addTranslation((new ContactFormTranslation())->setLocale('en')->setTitle('Demo'));

        $em = self::getEntityManager();
        $em->persist($form);
        $em->flush();

        $client->request('GET', '/admin/contact-forms/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Demo', (string) $client->getResponse()->getContent());
    }

    public function testNewAndEditFormRender(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();

        $form = (new ContactForm())
            ->setName('Support')
            ->setSlug('support')
            ->addTranslation((new ContactFormTranslation())->setLocale('en')->setTitle('Contact us'));

        $em = self::getEntityManager();
        $em->persist($form);
        $em->flush();

        $client->request('GET', '/admin/contact-forms/new');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Save', (string) $client->getResponse()->getContent());

        $client->request('GET', sprintf('/admin/contact-forms/%d/edit', $form->getId()));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Support', (string) $client->getResponse()->getContent());
    }

    public function testEditReturns404ForMissingForm(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();

        $client->request('GET', '/admin/contact-forms/999/edit');

        self::assertResponseStatusCodeSame(404);
    }
}
