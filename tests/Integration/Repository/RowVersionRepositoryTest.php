<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Repository;

use Nowo\ContactFormBundle\Concurrency\RowVersionGuard;
use Nowo\ContactFormBundle\Concurrency\StaleVersionException;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactFormFieldTranslation;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Repository\ContactFormFieldRepository;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Nowo\ContactFormBundle\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

use function sprintf;

/**
 * REQ-RUNTIME-003 rule 4 (1.12): the admin edits of a contact form and of a form field are checked against the row
 * version of the page they were made on (SQLite here; MySQL, MariaDB and PostgreSQL in the "Real databases" job).
 */
#[CoversClass(RowVersionGuard::class)]
#[CoversClass(StaleVersionException::class)]
#[CoversClass(ContactFormRepository::class)]
#[CoversClass(ContactFormFieldRepository::class)]
#[CoversClass(ContactForm::class)]
#[CoversClass(ContactFormField::class)]
final class RowVersionRepositoryTest extends IntegrationTestCase
{
    public function testANewRowStartsAtVersionOne(): void
    {
        $form = $this->persistForm();

        self::assertSame(1, $form->getRowVersion());
        self::assertSame(1, $this->storedVersion('nowo_contact_form', (int) $form->getId()));
    }

    public function testTheGuardAdvancesTheRowOnceAndRefusesTheSameTokenTwice(): void
    {
        $form = $this->persistForm();
        $id   = (int) $form->getId();
        $em   = self::getEntityManager();

        RowVersionGuard::advance($em, $form, 1);
        self::assertSame(2, $form->getRowVersion());
        self::assertSame(2, $this->storedVersion('nowo_contact_form', $id));

        try {
            RowVersionGuard::advance($em, $form, 1);
            self::fail('The version 1 page is stale.');
        } catch (StaleVersionException $stale) {
            self::assertSame(ContactForm::class, $stale->entityClass);
            self::assertSame($id, $stale->id);
            self::assertSame(1, $stale->expected);
        }
        self::assertSame(2, $this->storedVersion('nowo_contact_form', $id), 'a refused token advances nothing');
    }

    public function testAMissingTokenIsStaleAndADeletedRowIsStale(): void
    {
        $form = $this->persistForm();
        $id   = (int) $form->getId();
        $em   = self::getEntityManager();

        try {
            RowVersionGuard::advance($em, $form, 0);
            self::fail('Token 0 matches no row.');
        } catch (StaleVersionException) {
            self::assertSame(1, $this->storedVersion('nowo_contact_form', $id));
        }

        $em->getConnection()->executeStatement('DELETE FROM nowo_contact_form WHERE id = ?', [$id]);
        $this->expectException(StaleVersionException::class);
        RowVersionGuard::advance($em, $form, 1);
    }

    public function testAFormEditWithTheCurrentTokenAdvancesTheVersionAndASavedTranslationOnlyChangeToo(): void
    {
        $form       = $this->persistForm();
        $em         = self::getEntityManager();
        $repository = $this->formRepository();

        // Only the translation changes: the form row itself is untouched, and still advances.
        $form->findTranslation('en')?->setTitle('Changed title');
        $repository->saveEdit($form, $em, 1);

        self::assertSame(2, $this->storedVersion('nowo_contact_form', (int) $form->getId()));
        self::assertSame('Changed title', $em->getConnection()->fetchOne('SELECT title FROM nowo_contact_form_translation WHERE form_id = ?', [$form->getId()]));
        self::assertSame(0, $em->getConnection()->getTransactionNestingLevel());
    }

    public function testAStaleFormEditWritesNothingAndDiscardLeavesNothingForALaterFlush(): void
    {
        $form        = $this->persistForm();
        $em          = self::getEntityManager();
        $repository  = $this->formRepository();
        $translation = $form->findTranslation('en');
        self::assertInstanceOf(ContactFormTranslation::class, $translation);
        // Another admin saved the form after this page was opened (version 1).
        $em->getConnection()->executeStatement('UPDATE nowo_contact_form SET name = ?, row_version = 2 WHERE id = ?', ['Other admin', $form->getId()]);

        $form->setName('Stale page');
        $translation->setTitle('Stale title');
        $form->addTranslation((new ContactFormTranslation())->setLocale('es')->setTitle('Stale es'));

        try {
            $repository->saveEdit($form, $em, 1);
            self::fail('A stale edit must be refused.');
        } catch (StaleVersionException) {
            self::assertSame('Other admin', $em->getConnection()->fetchOne('SELECT name FROM nowo_contact_form WHERE id = ?', [$form->getId()]));
            RowVersionGuard::discard($em, $form, $form->getTranslations()->toArray());
        }
        $em->flush();

        self::assertSame('Other admin', $em->getConnection()->fetchOne('SELECT name FROM nowo_contact_form WHERE id = ?', [$form->getId()]));
        self::assertSame(['en' => 'Contact us'], $em->getConnection()->fetchAllKeyValue('SELECT locale, title FROM nowo_contact_form_translation'), 'a later flush writes nothing of the refused page');
        self::assertSame('Other admin', $form->getName(), 'the managed entity shows the stored row again');
        self::assertTrue($em->isOpen());
        self::assertSame(0, $em->getConnection()->getTransactionNestingLevel());
    }

    public function testAFieldEditAdvancesTheVersionAndAStaleOneWritesNothing(): void
    {
        $form  = $this->persistForm();
        $field = $this->persistField($form);
        $em    = self::getEntityManager();
        $repo  = $this->fieldRepository();

        $field->setName('email_address');
        $repo->saveEdit($field, $em, 1);
        self::assertSame(2, $this->storedVersion('nowo_contact_form_field', (int) $field->getId()));

        $field->setName('stale_name');
        try {
            $repo->saveEdit($field, $em, 1);
            self::fail('A stale edit must be refused.');
        } catch (StaleVersionException $stale) {
            self::assertSame(ContactFormField::class, $stale->entityClass);
        }
        self::assertSame('email_address', $em->getConnection()->fetchOne('SELECT name FROM nowo_contact_form_field WHERE id = ?', [$field->getId()]));
        self::assertSame(2, $this->storedVersion('nowo_contact_form_field', (int) $field->getId()));
    }

    public function testTheTransientWizardTokenIsNeverPersisted(): void
    {
        $field = (new ContactFormField())->setName('x');
        self::assertNull($field->getFlowBaseVersion());
        self::assertSame($field, $field->setFlowBaseVersion(5));
        self::assertSame(5, $field->getFlowBaseVersion());
        self::assertSame(1, $field->getRowVersion());
        self::assertSame($field, $field->setRowVersion(3));
        self::assertSame(3, $field->getRowVersion());
    }

    public function testEveryVersionedTableMapsTheColumn(): void
    {
        self::createTestClient();
        $this->resetDatabase();
        $manager = self::getEntityManager()->getConnection()->createSchemaManager();
        foreach (['nowo_contact_form', 'nowo_contact_form_field'] as $table) {
            self::assertTrue($manager->introspectTable($table)->hasColumn('row_version'), $table);
        }
    }

    private function formRepository(): ContactFormRepository
    {
        $repository = self::getContainer()->get(ContactFormRepository::class);
        self::assertInstanceOf(ContactFormRepository::class, $repository);

        return $repository;
    }

    private function fieldRepository(): ContactFormFieldRepository
    {
        $repository = self::getContainer()->get(ContactFormFieldRepository::class);
        self::assertInstanceOf(ContactFormFieldRepository::class, $repository);

        return $repository;
    }

    private function persistForm(): ContactForm
    {
        self::createTestClient();
        $this->resetDatabase();
        $form = (new ContactForm())->setName('Support')->setSlug('support')->addTranslation((new ContactFormTranslation())->setLocale('en')->setTitle('Contact us'));
        $em   = self::getEntityManager();
        $em->persist($form);
        $em->flush();

        return $form;
    }

    private function persistField(ContactForm $form): ContactFormField
    {
        $field = (new ContactFormField())->setName('email')->setForm($form)->addTranslation((new ContactFormFieldTranslation())->setLocale('en')->setLabel('Email'));
        $em    = self::getEntityManager();
        $em->persist($field);
        $em->flush();

        return $field;
    }

    private function storedVersion(string $table, int $id): int
    {
        return (int) self::getEntityManager()->getConnection()->fetchOne(sprintf('SELECT row_version FROM %s WHERE id = ?', $table), [$id]);
    }
}
