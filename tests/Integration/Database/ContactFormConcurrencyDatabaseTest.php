<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Database;

use DateTimeImmutable;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ContactFormBundle\Command\CleanupExpiredSubmissionsCommand;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Entity\ContactSubmissionValue;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\EventSubscriber\ContactFormConflictListener;
use Nowo\ContactFormBundle\Notification\NullContactSubmissionNotifier;
use Nowo\ContactFormBundle\Repository\ContactFormFieldRepository;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Nowo\ContactFormBundle\Repository\ContactSubmissionRepository;
use Nowo\ContactFormBundle\Service\ClientLabelResolver;
use Nowo\ContactFormBundle\Service\ContactFormEntityManagerResolver;
use Nowo\ContactFormBundle\Service\ContactFormSubmissionValueNormalizer;
use Nowo\ContactFormBundle\Service\ContactSubmissionProcessor;
use Nowo\ContactFormBundle\Service\IpAnonymizer;
use Nowo\ContactFormBundle\Service\NullContactFormFileUploadHandler;
use Nowo\ContactFormBundle\Service\SubmissionRetentionCleanupService;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

use function array_intersect;
use function array_map;
use function dirname;
use function getenv;
use function is_string;

use const PHP_VERSION_ID;

/**
 * 1.11 (REQ-RUNTIME-003): two connections on real MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 servers, on the bundle's
 * hot write paths: the public submission, the retention purge and two admin edits of one form.
 *
 * Every test session sets a 2 s `innodb_lock_wait_timeout` / `lock_timeout`, so a lock wait fails fast instead of
 * hanging. Set CF_MYSQL_URL, CF_MARIADB_URL and CF_POSTGRES_URL; CI runs the `database` group with `--fail-on-skipped`.
 */
#[Group('database')]
final class ContactFormConcurrencyDatabaseTest extends TestCase
{
    private const SLUG = 'support';

    private const PUBLIC_CONTROLLER = 'Nowo\\ContactFormBundle\\Controller\\ContactFormPublicController::show';

    private const ADMIN_CONTROLLER = 'Nowo\\ContactFormBundle\\Controller\\ContactFormAdminController::edit';

    /** @var list<EntityManagerInterface> */
    private array $managers = [];

    /** @var array<string, mixed> */
    private array $params = [];

    protected function tearDown(): void
    {
        foreach ($this->managers as $manager) {
            $manager->getConnection()->close();
        }

        $this->managers = [];
    }

    /**
     * @return iterable<string, array{0: string|null}>
     */
    public static function databases(): iterable
    {
        foreach (['mysql' => 'CF_MYSQL_URL', 'mariadb' => 'CF_MARIADB_URL', 'postgres' => 'CF_POSTGRES_URL'] as $name => $variable) {
            $url = getenv($variable);
            yield $name => [is_string($url) && $url !== '' ? $url : null];
        }
    }

    /**
     * Public submission against the retention purge: while a purge chunk is open (expired submissions and their values
     * deleted, not committed), a visitor submits the same form. The submission is stored at once (the purge locks only
     * the rows it deletes, never the form), the purge commits whole, and nothing of either write is lost or orphaned.
     */
    #[DataProvider('databases')]
    public function testAPublicSubmissionDuringTheRetentionPurgeIsStoredAndThePurgeStaysWhole(?string $url): void
    {
        $purger  = $this->freshDatabase($url);
        $visitor = $this->manager();
        $formId  = $this->seedForm($purger);
        $expired = $this->seedSubmissions($purger, $formId, 3, '-40 days');
        $kept    = $this->seedSubmissions($purger, $formId, 1, '-1 day');

        $purger->getConnection()->beginTransaction();
        self::assertSame(3, $this->cleaner($purger)->cleanup());

        $newId = $this->processor($visitor)->record(self::SLUG, ['email' => 'visitor@example.com'], 'en');

        $purger->getConnection()->commit();

        self::assertSame([...$kept, $newId], $this->submissionIds($visitor), 'the fresh and the new submission stay');
        self::assertSame(2, $this->rowCount($visitor, 'nowo_contact_submission_value'), 'no orphan value of a purged submission');
        self::assertSame('visitor@example.com', $visitor->getConnection()->fetchOne('SELECT value FROM nowo_contact_submission_value WHERE submission_id = ?', [$newId]));
        self::assertSame([...$kept, $newId], $this->submissionIds($purger), 'purger connection usable');
        self::assertSame([], array_intersect($expired, $this->submissionIds($purger)), 'the expired ones are gone');
    }

    /**
     * The purge against a write holding an expired submission: the chunk meets the lock (2 s), rolls back whole and the
     * command says to run it again; the next run finishes the job.
     */
    #[DataProvider('databases')]
    public function testAPurgeChunkThatMeetsALockedRowRollsBackWholeAndTheNextRunFinishesIt(?string $url): void
    {
        $holder  = $this->freshDatabase($url);
        $purger  = $this->manager();
        $formId  = $this->seedForm($holder);
        $expired = $this->seedSubmissions($holder, $formId, 3, '-40 days');

        $holder->getConnection()->beginTransaction();
        $holder->getConnection()->fetchOne('SELECT id FROM nowo_contact_submission WHERE id = ? FOR UPDATE', [$expired[1]]);

        $tester = new CommandTester(new CleanupExpiredSubmissionsCommand($this->cleaner($purger)));
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('A lock conflict', $tester->getDisplay());

        $holder->getConnection()->rollBack();
        self::assertSame($expired, $this->submissionIds($holder), 'the failed chunk is unchanged');
        self::assertSame(3, $this->rowCount($holder, 'nowo_contact_submission_value'));

        $purger = $this->manager(); // the failed flush closed the purger's manager (the bundle's resolver resets it)
        self::assertSame(Command::SUCCESS, (new CommandTester(new CleanupExpiredSubmissionsCommand($this->cleaner($purger))))->execute([]));
        self::assertSame([], $this->submissionIds($holder));
        self::assertSame(0, $this->rowCount($holder, 'nowo_contact_submission_value'));
    }

    /**
     * A public submission that meets a lock on its form (an admin save or delete of the form in flight) answers
     * 409 + Retry-After + CONFLICT_001 (503 with the host option); nothing is stored, the bundle's manager is recovered
     * and the retry stores exactly one submission.
     */
    #[DataProvider('databases')]
    public function testAPublicSubmissionThatMeetsALockedFormAnswers409Conflict001AndTheRetryStoresOne(?string $url): void
    {
        $admin   = $this->freshDatabase($url);
        $formId  = $this->seedForm($admin);
        $holder  = new ManagerHolder($this);
        $visitor = $this->processor($holder->manager, new ContactFormEntityManagerResolver($holder->registry()));

        $admin->getConnection()->beginTransaction();
        $admin->getConnection()->fetchOne('SELECT id FROM nowo_contact_form WHERE id = ? FOR UPDATE', [$formId]);

        $failure = $this->failureOf(static fn (): int => $visitor->record(self::SLUG, ['email' => 'a@example.com'], 'en'));
        $answer  = $this->answer($failure, new ContactFormConflictListener(), $this->request(self::PUBLIC_CONTROLLER));
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(['X-Conflict-Code' => ConflictCode::RETRY_SAFE, 'Retry-After' => '1'], $answer->getHeaders());
        self::assertSame(503, $this->answer($failure, new ContactFormConflictListener(new ConflictResponsePolicy(503)), $this->request(self::PUBLIC_CONTROLLER))->getStatusCode(), 'host option');

        $admin->getConnection()->rollBack();
        self::assertSame([], $this->submissionIds($admin), 'nothing of the failed submission is stored');
        self::assertSame(1, $holder->resets, 'the closed manager of the bundle was replaced');

        $visitor->record(self::SLUG, ['email' => 'a@example.com'], 'en');
        self::assertCount(1, $this->submissionIds($admin), 'the retry stores exactly one');
        self::assertSame(1, $this->rowCount($admin, 'nowo_contact_submission_value'));
    }

    /**
     * A lock conflict after a commit earlier in the same request answers CONFLICT_002 without Retry-After: the commit
     * is counted by the real commit-tracking middleware.
     */
    #[DataProvider('databases')]
    public function testALockConflictAfterACommitEarlierInTheRequestAnswersConflict002(?string $url): void
    {
        $admin    = $this->freshDatabase($url);
        $formId   = $this->seedForm($admin);
        $ledger   = new CommitLedger();
        $visitor  = $this->processor($this->manager($ledger));
        $listener = new ContactFormConflictListener(null, $ledger);
        $request  = $this->request(self::PUBLIC_CONTROLLER);
        $listener->onRequest(new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));

        $visitor->record(self::SLUG, ['email' => 'first@example.com'], 'en');

        $admin->getConnection()->beginTransaction();
        $admin->getConnection()->fetchOne('SELECT id FROM nowo_contact_form WHERE id = ? FOR UPDATE', [$formId]);
        $failure = $this->failureOf(static fn (): int => $visitor->record(self::SLUG, ['email' => 'second@example.com'], 'en'));
        $admin->getConnection()->rollBack();

        $answer = $this->answer($failure, $listener, $request);
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(['X-Conflict-Code' => ConflictCode::COMMITTED], $answer->getHeaders());
        self::assertCount(1, $this->submissionIds($admin), 'the first submission is committed, the second is not');
    }

    /**
     * Two admins edit one form; B read it before A saved. While A's save holds the row, B's save meets the lock and
     * answers 409 + CONFLICT_001; the row is A's whole (B's change is not half-saved). B's retry, reading the form
     * again, writes only the column it changed, so A's change stays.
     */
    #[DataProvider('databases')]
    public function testTwoAdminEditsOfOneFormNeverHalfSaveAndTheRetryKeepsBothChanges(?string $url): void
    {
        $a      = $this->freshDatabase($url);
        $b      = $this->manager();
        $formId = $this->seedForm($a);

        $formB = $b->find(ContactForm::class, $formId); // B's stale copy
        self::assertInstanceOf(ContactForm::class, $formB);

        $a->getConnection()->beginTransaction();
        $formA = $a->find(ContactForm::class, $formId);
        self::assertInstanceOf(ContactForm::class, $formA);
        $formA->setName('Renamed by A');
        $a->flush();

        $formB->setRetentionDays(7);
        $failure = $this->failureOf(static function () use ($b): int {
            $b->flush();

            return 0;
        });
        $answer = $this->answer($failure, new ContactFormConflictListener(), $this->request(self::ADMIN_CONTROLLER));
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(['X-Conflict-Code' => ConflictCode::RETRY_SAFE, 'Retry-After' => '1'], $answer->getHeaders());

        $a->getConnection()->commit();
        self::assertSame(['Renamed by A', 30], $this->formRow($a, $formId), 'A whole, nothing of B');

        $b     = $this->manager();
        $retry = $b->find(ContactForm::class, $formId);
        self::assertInstanceOf(ContactForm::class, $retry);
        $retry->setRetentionDays(7);
        $b->flush();
        self::assertSame(['Renamed by A', 7], $this->formRow($a, $formId), 'both changes kept');
    }

    /**
     * Two admins open the edit page of a form that lacks a translation of an enabled locale; each page adds it
     * (ensureTranslationsForEnabledLocales) and both save. The unique key keeps one translation; the second save
     * answers the domain 409 (`X-Error-Code`), never a CONFLICT_* code. On the public route it is not mapped.
     */
    #[DataProvider('databases')]
    public function testTwoAdminEditsAddingTheSameTranslationKeepOneAndAnswerTheDomain409(?string $url): void
    {
        $a      = $this->freshDatabase($url);
        $b      = $this->manager();
        $formId = $this->seedForm($a);

        $formA = $a->find(ContactForm::class, $formId);
        $formB = $b->find(ContactForm::class, $formId);
        self::assertInstanceOf(ContactForm::class, $formA);
        self::assertInstanceOf(ContactForm::class, $formB);
        $formA->addTranslation((new ContactFormTranslation())->setLocale('es')->setTitle('A'));
        $formB->addTranslation((new ContactFormTranslation())->setLocale('es')->setTitle('B'));
        $a->flush();

        $failure = $this->failureOf(static function () use ($b): int {
            $b->flush();

            return 0;
        });

        $listener = new ContactFormConflictListener();
        $answer   = $this->answer($failure, $listener, $this->request(self::ADMIN_CONTROLLER));
        self::assertInstanceOf(ConflictHttpException::class, $answer);
        self::assertSame([ContactFormConflictListener::ERROR_CODE_HEADER => ContactFormConflictListener::DUPLICATE_KEY], $answer->getHeaders());

        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $this->request(self::PUBLIC_CONTROLLER), HttpKernelInterface::MAIN_REQUEST, $failure);
        $listener->onException($event);
        self::assertSame($failure, $event->getThrowable(), 'not a conflict on the public route');

        self::assertSame(['A'], $a->getConnection()->fetchFirstColumn("SELECT title FROM nowo_contact_form_translation WHERE form_id = ? AND locale = 'es'", [$formId]));
    }

    private function freshDatabase(?string $url): EntityManagerInterface
    {
        if ($url === null) {
            self::markTestSkipped('Set CF_MYSQL_URL, CF_MARIADB_URL and CF_POSTGRES_URL to run the real-database tests.');
        }

        $this->params = (new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse($url);
        $manager      = $this->manager();
        $schemaTool   = new SchemaTool($manager);
        $metadata     = $manager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        return $manager;
    }

    public function manager(?CommitLedger $ledger = null): EntityManagerInterface
    {
        $manager          = self::createManager($this->params, $ledger);
        $this->managers[] = $manager;

        return $manager;
    }

    public function registryStub(): ManagerRegistry&Stub
    {
        return $this->createStub(ManagerRegistry::class);
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function createManager(array $params, ?CommitLedger $ledger = null): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        $dbal = new DbalConfiguration();
        if ($ledger instanceof CommitLedger) {
            $dbal->setMiddlewares([new CommitTrackingMiddleware($ledger)]);
        }

        /** @phpstan-ignore argument.type (DsnParser output) */
        $connection = DriverManager::getConnection($params, $dbal);
        $connection->executeStatement($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? 'SET SESSION innodb_lock_wait_timeout = 2' : "SET lock_timeout = '2s'");

        return new EntityManager($connection, $config);
    }

    private function seedForm(EntityManagerInterface $manager): int
    {
        $form = (new ContactForm())->setName('Support')->setSlug(self::SLUG)->setRetentionDays(30);
        $form->addTranslation((new ContactFormTranslation())->setLocale('en')->setTitle('Support'));
        $form->addField((new ContactFormField())->setName('email')->setType(ContactFieldType::Email)->setSortOrder(1));
        $manager->persist($form);
        $manager->flush();
        $manager->clear();

        return (int) $form->getId();
    }

    /**
     * @return list<int>
     */
    private function seedSubmissions(EntityManagerInterface $manager, int $formId, int $count, string $age): array
    {
        $form = $manager->find(ContactForm::class, $formId);
        self::assertInstanceOf(ContactForm::class, $form);
        $submissions = [];
        for ($i = 0; $i < $count; ++$i) {
            $submission = (new ContactSubmission())->setForm($form)->setLocale('en')->setCreatedAt(new DateTimeImmutable($age));
            $submission->addValue((new ContactSubmissionValue())->setFieldName('email')->setValue('old' . $i . '@example.com'));
            $manager->persist($submission);
            $submissions[] = $submission;
        }

        $manager->flush();
        $manager->clear();

        return array_map(static fn (ContactSubmission $submission): int => (int) $submission->getId(), $submissions);
    }

    private function processor(EntityManagerInterface $manager, ?ContactFormEntityManagerResolver $resolver = null): ContactSubmissionProcessor
    {
        $registry = $resolver instanceof ContactFormEntityManagerResolver ? null : $this->registry($manager);

        return new ContactSubmissionProcessor(
            $manager,
            new ContactFormFieldRepository($registry ?? $this->resolverRegistry($resolver)),
            new IpAnonymizer('salt'),
            new ClientLabelResolver(),
            new EventDispatcher(),
            new NullContactSubmissionNotifier(),
            new ContactFormSubmissionValueNormalizer(new NullContactFormFileUploadHandler()),
            new MockClock(),
            null,
            $resolver,
            null,
            new ContactFormRepository($registry ?? $this->resolverRegistry($resolver)),
        );
    }

    private function cleaner(EntityManagerInterface $manager): SubmissionRetentionCleanupService
    {
        $registry = $this->registry($manager);

        return new SubmissionRetentionCleanupService(new ContactFormRepository($registry), new ContactSubmissionRepository($registry), $manager, new MockClock());
    }

    private function registry(EntityManagerInterface $manager): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($manager);

        return $registry;
    }

    /**
     * Repositories that follow the resolver's current manager (as the container's registry does after a reset).
     */
    private function resolverRegistry(?ContactFormEntityManagerResolver $resolver): ManagerRegistry
    {
        self::assertInstanceOf(ContactFormEntityManagerResolver::class, $resolver);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturnCallback(static fn (): EntityManagerInterface => $resolver->get());

        return $registry;
    }

    /**
     * @return list<int>
     */
    private function submissionIds(EntityManagerInterface $manager): array
    {
        return array_map(intval(...), $manager->getConnection()->fetchFirstColumn('SELECT id FROM nowo_contact_submission ORDER BY id'));
    }

    private function rowCount(EntityManagerInterface $manager, string $table): int
    {
        return (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function formRow(EntityManagerInterface $manager, int $formId): array
    {
        $row = $manager->getConnection()->fetchAssociative('SELECT name, retention_days FROM nowo_contact_form WHERE id = ?', [$formId]);
        self::assertIsArray($row);

        return [(string) $row['name'], (int) $row['retention_days']];
    }

    /**
     * @param callable(): int $write
     */
    private function failureOf(callable $write): Throwable
    {
        try {
            $write();
        } catch (Throwable $failure) {
            return $failure;
        }

        self::fail('The write must meet the lock.');
    }

    private function request(string $controller): Request
    {
        $request = Request::create('/en/contact/' . self::SLUG, 'POST');
        $request->attributes->set('_controller', $controller);

        return $request;
    }

    private function answer(Throwable $failure, ContactFormConflictListener $listener, Request $request): HttpException
    {
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
        $listener->onException($event);
        $answer = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer, 'mapped: ' . $failure::class . ': ' . $failure->getMessage());

        return $answer;
    }
}

/**
 * The bundle's own manager behind a registry: resetManager() replaces it by a new manager on a new connection, as
 * DoctrineBundle does for a closed manager.
 */
final class ManagerHolder
{
    public EntityManagerInterface $manager;

    public int $resets = 0;

    public function __construct(private readonly ContactFormConcurrencyDatabaseTest $test)
    {
        $this->manager = $test->manager();
    }

    public function registry(): ManagerRegistry
    {
        $registry = $this->test->registryStub();
        $registry->method('getManagerForClass')->willReturnCallback(fn (): EntityManagerInterface => $this->manager);
        $registry->method('getManagerNames')->willReturn(['default' => 'doctrine.orm.default_entity_manager']);
        $registry->method('getManager')->willReturnCallback(fn (): EntityManagerInterface => $this->manager);
        $registry->method('resetManager')->willReturnCallback(function (): EntityManagerInterface {
            ++$this->resets;
            $this->manager->getConnection()->close();
            $this->manager = $this->test->manager();

            return $this->manager;
        });

        return $registry;
    }
}
