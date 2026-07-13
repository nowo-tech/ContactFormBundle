<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Repository;

use DateTimeImmutable;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Repository\ContactSubmissionRepository;
use Nowo\ContactFormBundle\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ContactSubmissionRepository::class)]
final class ContactSubmissionRepositoryTest extends IntegrationTestCase
{
    public function testFindExpiredIdsByFormAndFindByFormOrdered(): void
    {
        static::createTestClient();
        $this->resetDatabase();

        $form = (new ContactForm())->setName('Form')->setSlug('form')->setRetentionDays(30);
        $em   = static::getContainer()->get('doctrine')->getManager();
        $em->persist($form);

        $old = (new ContactSubmission())
            ->setForm($form)
            ->setLocale('en')
            ->setCreatedAt(new DateTimeImmutable('-40 days'));
        $recent = (new ContactSubmission())
            ->setForm($form)
            ->setLocale('en')
            ->setCreatedAt(new DateTimeImmutable('-1 day'));

        $em->persist($old);
        $em->persist($recent);
        $em->flush();

        /** @var ContactSubmissionRepository $repository */
        $repository = static::getContainer()->get(ContactSubmissionRepository::class);
        $threshold  = new DateTimeImmutable('-30 days');
        $expiredIds = $repository->findExpiredIdsByForm($form, $threshold);

        self::assertCount(1, $expiredIds);
        self::assertSame($old->getId(), $expiredIds[0]);
        self::assertCount(2, $repository->findByFormOrdered($form));
    }
}
