<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Controller;

use Nowo\ContactFormBundle\Controller\ContactFormFieldAdminController;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Form\Flow\AbstractFlowType;

use function sprintf;

#[CoversClass(ContactFormFieldAdminController::class)]
final class ContactFormFieldAdminControllerTest extends IntegrationTestCase
{
    public function testIndexListsFields(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();

        $form = (new ContactForm())
            ->setName('Demo')
            ->setSlug('demo')
            ->addTranslation((new ContactFormTranslation())->setLocale('en')->setTitle('Demo'));

        $field = (new ContactFormField())
            ->setName('email')
            ->setType(ContactFieldType::Email)
            ->setForm($form);

        $em = self::getEntityManager();
        $em->persist($form);
        $em->persist($field);
        $em->flush();

        $client->request('GET', sprintf('/admin/contact-forms/%d/fields', $form->getId()));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('email', (string) $client->getResponse()->getContent());
    }

    public function testNewRedirectRequiresFormFlow(): void
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

        $client->catchExceptions(true);
        $client->request('GET', sprintf('/admin/contact-forms/%d/fields/new', $form->getId()));

        if (class_exists(AbstractFlowType::class)) {
            self::assertResponseRedirects();
        } else {
            self::assertResponseStatusCodeSame(500);
        }
    }

    public function testIndexReturns404ForMissingForm(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();

        $client->request('GET', '/admin/contact-forms/999/fields');

        self::assertResponseStatusCodeSame(404);
    }
}
