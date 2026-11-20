<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Database;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\ContactFormBundle\Doctrine\ContactFormHostFlushReporter;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\EventSubscriber\ContactFormConflictListener;
use Nowo\ContactFormBundle\Notification\NullContactSubmissionNotifier;
use Nowo\ContactFormBundle\Repository\ContactFormFieldRepository;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Nowo\ContactFormBundle\Service\ClientLabelResolver;
use Nowo\ContactFormBundle\Service\ContactFormEntityManagerResolver;
use Nowo\ContactFormBundle\Service\ContactFormSubmissionValueNormalizer;
use Nowo\ContactFormBundle\Service\ContactSubmissionProcessor;
use Nowo\ContactFormBundle\Service\IpAnonymizer;
use Nowo\ContactFormBundle\Service\NullContactFormFileUploadHandler;
use Nowo\ContactFormBundle\Tests\Integration\EntityManager\Entity\HostNote;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\HostFlushProbe;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManagerMiddleware;
use Nowo\TransactionDoctrine\PrivateManager\PrivateManagerRegistry;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

use function dirname;
use function getenv;
use function is_string;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;

use const E_USER_DEPRECATED;
use const PHP_VERSION_ID;

/**
 * 1.13 (REQ-ARCH-001 rule 6, REQ-RUNTIME-003 rule 6) on real MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 servers.
 *
 * Private mode (`doctrine.private_entity_manager: true`, transaction-doctrine's kit wired as the bundle wires it: the
 * private manager on the application manager's connection, its DBAL middleware, the private registry for the
 * repositories and the resolver): a host entity left pending stays pending (not written, still managed, its manager
 * open) after a submission, after a lock conflict and after a rollback of the host's own transaction; the conflict
 * answer keeps CONFLICT_001 (+ Retry-After, nothing committed) and CONFLICT_002 (no Retry-After, a commit earlier in
 * the request). Host mode (unset): the bundle flush writes the host's change and the probe reports its class.
 */
#[Group('database')]
final class PrivateEntityManagerDatabaseTest extends TestCase
{
    private const SLUG = 'support';

    private const PUBLIC_CONTROLLER = 'Nowo\\ContactFormBundle\\Controller\\ContactFormPublicController::show';

    /** @var array<string, mixed> */
    private array $params = [];

    /** @var list<EntityManagerInterface> */
    private array $managers = [];

    private ?EntityManagerInterface $host = null;

    protected function tearDown(): void
    {
        foreach ($this->managers as $manager) {
            $manager->getConnection()->close();
        }

        $this->managers = [];
        $this->host     = null;
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

    #[DataProvider('databases')]
    public function testPrivateModeKeepsTheHostsPendingChangeAndAnswersConflict001(?string $url): void
    {
        $this->freshDatabase($url);
        [$host, $private, $visitor] = $this->privateMode();
        $admin                      = $this->plainManager();
        $formId                     = $this->seedForm($admin);
        $note                       = new HostNote('pending host change');
        $host->persist($note);

        self::assertGreaterThan(0, $visitor->record(self::SLUG, ['email' => 'first@example.com'], 'en'));
        $this->assertHostPending($host, $note, $admin, 'after a submission');

        $admin->getConnection()->beginTransaction();
        $admin->getConnection()->fetchOne('SELECT id FROM nowo_contact_form WHERE id = ? FOR UPDATE', [$formId]);
        $live    = $private->live();
        $failure = $this->failureOf(static fn (): int => $visitor->record(self::SLUG, ['email' => 'locked@example.com'], 'en'));
        $admin->getConnection()->rollBack();

        $answer = $this->answer($failure, new ContactFormConflictListener(), $this->request());
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(['X-Conflict-Code' => ConflictCode::RETRY_SAFE, 'Retry-After' => '1'], $answer->getHeaders());
        self::assertSame(1, $this->rows($admin, 'nowo_contact_submission'), 'nothing of the refused submission is stored');
        $this->assertHostPending($host, $note, $admin, 'after the lock conflict');

        self::assertGreaterThan(0, $visitor->record(self::SLUG, ['email' => 'retry@example.com'], 'en'));
        self::assertNotSame($live, $private->live(), 'the private manager closed by the failure was replaced');
        self::assertSame(2, $this->rows($admin, 'nowo_contact_submission'), 'the retry stores exactly one');
        $this->assertHostPending($host, $note, $admin, 'after the retry');

        $host->flush();
        self::assertSame(1, $this->rows($admin, 'host_note'), 'the host writes its change when it flushes');
    }

    #[DataProvider('databases')]
    public function testPrivateModeAnswersConflict002AfterACommitEarlierInTheRequest(?string $url): void
    {
        $this->freshDatabase($url);
        $ledger             = new CommitLedger();
        [$host, , $visitor] = $this->privateMode($ledger);
        $admin              = $this->plainManager();
        $formId             = $this->seedForm($admin);
        $note               = new HostNote('pending host change');
        $host->persist($note);
        $listener = new ContactFormConflictListener(null, $ledger);
        $request  = $this->request();
        $listener->onRequest(new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));

        $visitor->record(self::SLUG, ['email' => 'first@example.com'], 'en');
        $admin->getConnection()->beginTransaction();
        $admin->getConnection()->fetchOne('SELECT id FROM nowo_contact_form WHERE id = ? FOR UPDATE', [$formId]);
        $failure = $this->failureOf(static fn (): int => $visitor->record(self::SLUG, ['email' => 'second@example.com'], 'en'));
        $admin->getConnection()->rollBack();

        $answer = $this->answer($failure, $listener, $request);
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(['X-Conflict-Code' => ConflictCode::COMMITTED], $answer->getHeaders());
        self::assertSame(1, $this->rows($admin, 'nowo_contact_submission'));
        $this->assertHostPending($host, $note, $admin, 'after CONFLICT_002');
    }

    /**
     * The connection is shared: inside the host's own transaction the private flush uses a savepoint, and the host's
     * rollback undoes the submission. The private manager is usable afterwards and the host's change is still pending.
     */
    #[DataProvider('databases')]
    public function testPrivateModeInsideTheHostsTransactionRollsBackWithIt(?string $url): void
    {
        $this->freshDatabase($url);
        [$host, , $visitor] = $this->privateMode();
        $admin              = $this->plainManager();
        $this->seedForm($admin);
        $note = new HostNote('pending host change');
        $host->persist($note);

        $host->getConnection()->beginTransaction();
        $visitor->record(self::SLUG, ['email' => 'undone@example.com'], 'en');
        $host->getConnection()->rollBack();
        self::assertSame(0, $this->rows($admin, 'nowo_contact_submission'));
        $this->assertHostPending($host, $note, $admin, 'after the host rollback');

        self::assertGreaterThan(0, $visitor->record(self::SLUG, ['email' => 'kept@example.com'], 'en'));
        self::assertSame(['kept@example.com'], $admin->getConnection()->fetchFirstColumn('SELECT value FROM nowo_contact_submission_value'));
        $this->assertHostPending($host, $note, $admin, 'after the next submission');
    }

    /**
     * Host mode (both options unset): the bundle flushes the host's manager, so the host's change is written, and the
     * probe reports its class once per unit of work; the lock conflict answer is unchanged (CONFLICT_001).
     */
    #[DataProvider('databases')]
    public function testHostModeWritesTheHostsChangeAndTheProbeReportsIt(?string $url): void
    {
        $this->freshDatabase($url);
        $host     = $this->hostManager();
        $admin    = $this->plainManager();
        $formId   = $this->seedForm($admin);
        $registry = $this->registryFor(static fn (): EntityManagerInterface => $host);
        $visitor  = $this->processor($host, new ContactFormEntityManagerResolver($registry), $registry, new ContactFormHostFlushReporter(true, new HostFlushProbe()));

        $messages = [];
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler (test only, restored in the finally below)
        set_error_handler(static function (int $type, string $message) use (&$messages): bool {
            $messages[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        try {
            $host->persist(new HostNote('first'));
            $visitor->record(self::SLUG, ['email' => 'a@example.com'], 'en');
            $host->persist(new HostNote('second'));
            $visitor->record(self::SLUG, ['email' => 'b@example.com'], 'en');
        } finally {
            restore_error_handler();
        }

        self::assertSame([sprintf('Your pending changes to %s were flushed by NowoContactFormBundle; 2.0 no longer does.', HostNote::class)], $messages);
        self::assertSame(2, $this->rows($admin, 'host_note'), '1.x: the bundle flush wrote the host changes');

        $admin->getConnection()->beginTransaction();
        $admin->getConnection()->fetchOne('SELECT id FROM nowo_contact_form WHERE id = ? FOR UPDATE', [$formId]);
        $failure = $this->failureOf(static fn (): int => $visitor->record(self::SLUG, ['email' => 'locked@example.com'], 'en'));
        $admin->getConnection()->rollBack();
        $answer = $this->answer($failure, new ContactFormConflictListener(), $this->request());
        self::assertSame(['X-Conflict-Code' => ConflictCode::RETRY_SAFE, 'Retry-After' => '1'], $answer->getHeaders());
        self::assertSame(2, $this->rows($admin, 'nowo_contact_submission'));
    }

    /**
     * @return array{0: EntityManagerInterface, 1: PrivateEntityManager, 2: ContactSubmissionProcessor}
     */
    private function privateMode(?CommitLedger $ledger = null): array
    {
        $applicationRegistry = $this->registryFor(fn (): EntityManagerInterface => $this->host ?? throw new LogicException('No host manager yet.'));
        $private             = new PrivateEntityManager($applicationRegistry, 'default', ContactForm::class, ContactFormHostFlushReporter::BUNDLE_NAME);
        $host                = $this->hostManager($ledger, new PrivateEntityManagerMiddleware($private, 'nowo_contact_form_'));
        $privateRegistry     = new PrivateManagerRegistry($applicationRegistry, $private, ContactFormHostFlushReporter::OWN_NAMESPACES, 'default');

        return [$host, $private, $this->processor($private, new ContactFormEntityManagerResolver($privateRegistry), $privateRegistry, new ContactFormHostFlushReporter())];
    }

    private function processor(EntityManagerInterface $manager, ContactFormEntityManagerResolver $resolver, ManagerRegistry $registry, ContactFormHostFlushReporter $reporter): ContactSubmissionProcessor
    {
        return new ContactSubmissionProcessor(
            $manager,
            new ContactFormFieldRepository($registry),
            new IpAnonymizer('salt'),
            new ClientLabelResolver(),
            new EventDispatcher(),
            new NullContactSubmissionNotifier(),
            new ContactFormSubmissionValueNormalizer(new NullContactFormFileUploadHandler()),
            new MockClock(),
            null,
            $resolver,
            null,
            new ContactFormRepository($registry),
            $reporter,
        );
    }

    /**
     * The application's registry: one manager, "default", resolved per call; a closed one is replaced.
     *
     * @param callable(): EntityManagerInterface $manager
     */
    private function registryFor(callable $manager): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturnCallback($manager);
        $registry->method('getManagerForClass')->willReturnCallback($manager);
        $registry->method('getManagerNames')->willReturn(['default' => 'doctrine.orm.default_entity_manager']);
        $registry->method('resetManager')->willReturnCallback($manager);

        return $registry;
    }

    private function assertHostPending(EntityManagerInterface $host, HostNote $note, EntityManagerInterface $reader, string $when): void
    {
        self::assertTrue($host->isOpen(), $when . ': the host manager is open');
        self::assertTrue($host->contains($note), $when . ': the host entity is still managed');
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($note), $when . ': its insert is still pending');
        self::assertSame(0, $this->rows($reader, 'host_note'), $when . ': nothing of the host was written');
    }

    private function freshDatabase(?string $url): void
    {
        if ($url === null) {
            self::markTestSkipped('Set CF_MYSQL_URL, CF_MARIADB_URL and CF_POSTGRES_URL to run the real-database tests.');
        }

        $this->params = (new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse($url);
        $manager      = $this->plainManager();
        $schemaTool   = new SchemaTool($manager);
        $metadata     = $manager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    /** The application's manager (bundle entities and the host entity), with the given DBAL middlewares. */
    private function hostManager(?CommitLedger $ledger = null, ?PrivateEntityManagerMiddleware $private = null): EntityManagerInterface
    {
        $middlewares = [];
        if ($private instanceof PrivateEntityManagerMiddleware) {
            $middlewares[] = $private;
        }
        if ($ledger instanceof CommitLedger) {
            $middlewares[] = new CommitTrackingMiddleware($ledger);
        }

        return $this->host = $this->createManager($middlewares);
    }

    /** Another session (an admin, a reader). */
    private function plainManager(): EntityManagerInterface
    {
        return $this->createManager([]);
    }

    /**
     * @param list<Middleware> $middlewares
     */
    private function createManager(array $middlewares): EntityManagerInterface
    {
        $root   = dirname(__DIR__, 3);
        $config = ORMSetup::createAttributeMetadataConfiguration([$root . '/src/Entity', $root . '/tests/Integration/EntityManager/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        $dbal = new DbalConfiguration();
        $dbal->setMiddlewares($middlewares);
        /** @phpstan-ignore argument.type (DsnParser output) */
        $connection = DriverManager::getConnection($this->params, $dbal);
        $connection->executeStatement($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? 'SET SESSION innodb_lock_wait_timeout = 2' : "SET lock_timeout = '2s'");

        $manager          = new EntityManager($connection, $config);
        $this->managers[] = $manager;

        return $manager;
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

    private function rows(EntityManagerInterface $manager, string $table): int
    {
        return (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
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

    private function request(): Request
    {
        $request = Request::create('/en/contact/' . self::SLUG, 'POST');
        $request->attributes->set('_controller', self::PUBLIC_CONTROLLER);

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
