<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Controller;

use Nowo\ContactFormBundle\Controller\ContactFormPublicLegacyController;
use Nowo\ContactFormBundle\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ContactFormPublicLegacyController::class)]
final class ContactFormPublicLegacyControllerTest extends IntegrationTestCase
{
    public function testLegacyRouteRedirectsToLocalizedRoute(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();

        $client->request('GET', '/contact/support');

        self::assertResponseRedirects('/en/contact/support');
    }
}
