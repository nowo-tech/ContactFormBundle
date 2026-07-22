<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Controller;

use Nowo\ContactFormBundle\Controller\ContactFormPublicController;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactFormFieldTranslation;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ContactFormPublicController::class)]
final class ContactFormPublicControllerTest extends IntegrationTestCase
{
    public function testShowRendersEnabledForm(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();
        $this->seedContactForm('support');

        $client->request('GET', '/en/contact/support');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Contact us', (string) $client->getResponse()->getContent());
    }

    public function testShowReturns404ForUnknownSlug(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();

        $client->request('GET', '/en/contact/missing');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSubmitValidForm(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();
        $this->seedContactForm('support');

        $client->request('GET', '/en/contact/support');
        $token = $client->getCrawler()->filterXPath('//input[@name="form[_token]"]')->attr('value');

        $client->request('POST', '/en/contact/support', [
            'form' => [
                'email'  => 'user@example.com',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/en/contact/support');
        $client->followRedirect();
        self::assertStringContainsString('Thanks!', (string) $client->getResponse()->getContent());
    }

    private function seedContactForm(string $slug): ContactForm
    {
        $form = (new ContactForm())
            ->setName('Support')
            ->setSlug($slug)
            ->setEnabled(true)
            ->addTranslation(
                (new ContactFormTranslation())
                    ->setLocale('en')
                    ->setTitle('Contact us')
                    ->setSuccessMessage('Thanks!'),
            );

        $field = (new ContactFormField())
            ->setName('email')
            ->setType(ContactFieldType::Email)
            ->setRequired(true)
            ->setForm($form)
            ->addTranslation(
                (new ContactFormFieldTranslation())
                    ->setLocale('en')
                    ->setLabel('Email'),
            );

        $form->addField($field);

        $em = self::getEntityManager();
        $em->persist($form);
        $em->flush();

        return $form;
    }
}
