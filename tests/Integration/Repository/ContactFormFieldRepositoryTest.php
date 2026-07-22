<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Repository;

use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Repository\ContactFormFieldRepository;
use Nowo\ContactFormBundle\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ContactFormFieldRepository::class)]
final class ContactFormFieldRepositoryTest extends IntegrationTestCase
{
    public function testFindByFormOrdered(): void
    {
        self::createTestClient();
        $this->resetDatabase();

        $form   = (new ContactForm())->setName('Form')->setSlug('form');
        $first  = (new ContactFormField())->setForm($form)->setName('a')->setSortOrder(2);
        $second = (new ContactFormField())->setForm($form)->setName('b')->setSortOrder(1);

        $em = self::getEntityManager();
        $em->persist($form);
        $em->persist($first);
        $em->persist($second);
        $em->flush();

        /** @var ContactFormFieldRepository $repository */
        $repository = self::getContainer()->get(ContactFormFieldRepository::class);
        $fields     = $repository->findByFormOrdered($form);

        self::assertSame(['b', 'a'], array_map(static fn (ContactFormField $f): string => $f->getName(), $fields));
    }
}
