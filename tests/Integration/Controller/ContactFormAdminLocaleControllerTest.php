<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Controller;

use Nowo\ContactFormBundle\Controller\ContactFormAdminController;
use Nowo\ContactFormBundle\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ContactFormAdminController::class)]
final class ContactFormAdminLocaleControllerTest extends IntegrationTestCase
{
    public function testSwitchLocalePersistsInSessionAndRedirectsBack(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();

        $client->request('GET', '/admin/contact-forms/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Contact forms', (string) $client->getResponse()->getContent());

        $client->request('GET', '/admin/contact-forms/locale/es');
        self::assertResponseRedirects('/admin/contact-forms/');

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Formularios de contacto', (string) $client->getResponse()->getContent());
    }

    public function testSwitchLocaleReturns404ForUnsupportedLocale(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();

        $client->request('GET', '/admin/contact-forms/locale/fr');

        self::assertResponseStatusCodeSame(404);
    }
}
