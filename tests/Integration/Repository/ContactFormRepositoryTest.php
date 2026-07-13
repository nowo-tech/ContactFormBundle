<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Repository;

use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Nowo\ContactFormBundle\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ContactFormRepository::class)]
final class ContactFormRepositoryTest extends IntegrationTestCase
{
    public function testFindOneEnabledBySlugReturnsEnabledFormOnly(): void
    {
        static::createTestClient();
        $this->resetDatabase();

        /** @var ContactFormRepository $repository */
        $repository = static::getContainer()->get(ContactFormRepository::class);

        $enabled = (new ContactForm())
            ->setName('Enabled')
            ->setSlug('enabled')
            ->setEnabled(true)
            ->addTranslation((new ContactFormTranslation())->setLocale('en')->setTitle('Enabled'));
        $disabled = (new ContactForm())
            ->setName('Disabled')
            ->setSlug('disabled')
            ->setEnabled(false);

        $em = static::getContainer()->get('doctrine')->getManager();
        $em->persist($enabled);
        $em->persist($disabled);
        $em->flush();

        self::assertSame('enabled', $repository->findOneEnabledBySlug('enabled')?->getSlug());
        self::assertNull($repository->findOneEnabledBySlug('disabled'));
    }
}
