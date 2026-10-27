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
use Psr\Cache\CacheItemPoolInterface;

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

    /**
     * L4: every POST counts against the rate limit (invalid floods included) and oversized values are rejected.
     */
    public function testInvalidAndOversizedSubmissionsAreCounted(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();
        $this->seedContactForm('support');

        $client->request('GET', '/en/contact/support');
        $token = $client->getCrawler()->filterXPath('//input[@name="form[_token]"]')->attr('value');

        $client->request('POST', '/en/contact/support', ['form' => ['email' => str_repeat('a', 300) . '@example.com', '_token' => $token]], [], ['REMOTE_ADDR' => '203.0.113.9']);
        self::assertResponseStatusCodeSame(422, 'too long is a validation error');

        // The invalid attempt was counted (the test kernel resets the array cache between requests: read it now).
        $cache = self::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $item = $cache->getItem('nowo_contact_form_submit_' . hash('sha256', 'support|203.0.113.9'));
        self::assertTrue($item->isHit());
        self::assertSame(1, $item->get()['c'] ?? null);
    }

    /**
     * L1: a javascript: privacy policy URL stored before 1.6 is never rendered as a link.
     */
    public function testLegacyUnsafePrivacyPolicyUrlIsNotLinked(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();
        $form = $this->seedContactForm('support')->setRequireConsent(true)->setPrivacyPolicyUrl('javascript:alert(1)');
        $form->getTranslationForLocale('en')->setPrivacyPolicyText('Privacy policy');
        self::getEntityManager()->flush();

        $client->request('GET', '/en/contact/support');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('javascript:', (string) $client->getResponse()->getContent());

        $form->setPrivacyPolicyUrl('https://example.com/privacy');
        self::getEntityManager()->flush();
        $client->request('GET', '/en/contact/support');
        self::assertSelectorExists('a[href="https://example.com/privacy"]');
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
