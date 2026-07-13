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
        static::createTestClient();
        $this->resetDatabase();

        $form   = (new ContactForm())->setName('Form')->setSlug('form');
        $first  = (new ContactFormField())->setForm($form)->setName('a')->setSortOrder(2);
        $second = (new ContactFormField())->setForm($form)->setName('b')->setSortOrder(1);

        $em = static::getContainer()->get('doctrine')->getManager();
        $em->persist($form);
        $em->persist($first);
        $em->persist($second);
        $em->flush();

        /** @var ContactFormFieldRepository $repository */
        $repository = static::getContainer()->get(ContactFormFieldRepository::class);
        $fields     = $repository->findByFormOrdered($form);

        self::assertSame(['b', 'a'], array_map(static fn (ContactFormField $f): string => $f->getName(), $fields));
    }
}
