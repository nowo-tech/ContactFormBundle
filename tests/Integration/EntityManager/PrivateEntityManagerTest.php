<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\EntityManager;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ContactFormBundle\Concurrency\StaleVersionException;
use Nowo\ContactFormBundle\DependencyInjection\NowoContactFormExtension;
use Nowo\ContactFormBundle\Doctrine\ContactFormHostFlushReporter;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactFormFieldTranslation;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Nowo\ContactFormBundle\Service\ContactSubmissionRecorderInterface;
use Nowo\ContactFormBundle\Service\SubmissionRetentionCleanerInterface;
use Nowo\ContactFormBundle\Tests\Integration\EntityManager\Entity\HostNote;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Messenger\DoctrineClearEntityManagerWorkerSubscriber;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Throwable;

use function count;
use function getmypid;
use function is_array;
use function is_callable;
use function is_file;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function sys_get_temp_dir;
use function unlink;

use const E_USER_DEPRECATED;

/**
 * 1.13 (REQ-ARCH-001 rule 6): `doctrine.private_entity_manager` through a real kernel on SQLite, one default manager
 * mapping both the bundle entities and a host entity (the zero-config standard host).
 *
 * - true: every bundle write goes through the private manager; a host entity left pending in the host's manager stays
 *   pending (not written, still managed) after a submission, an admin save, the retention cleanup and a failed write;
 *   the private manager is dropped at every request / message / command boundary (kernel kept alive, services resetter
 *   never called) and replaced when closed;
 * - unset: the notice at container build and the host-flush probe, once per class and unit of work;
 * - false: its own notice (2.0 removes the option) and the probe.
 */
final class PrivateEntityManagerTest extends TestCase
{
    private const NOTE_CLASS = HostNote::class;

    private ?EntityManagerTestKernel $kernel = null;

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testPrivateManagerKeepsTheHostsPendingChangesPending(): void
    {
        $this->boot(['private_entity_manager' => true]);
        $host    = $this->hostManager();
        $private = $this->container()->get('nowo_contact_form.entity_manager');
        self::assertInstanceOf(PrivateEntityManager::class, $private);
        self::assertSame($private, $this->container()->get(NowoContactFormExtension::PRIVATE_ENTITY_MANAGER_SERVICE));
        self::assertSame($host->getConnection(), $private->getConnection(), 'same connection, same tables');
        $formId = $this->seedForm($private);

        $note = new HostNote('pending host change');
        $host->persist($note);

        // Public submission (the recorder port): an identity id, the host's insert still pending.
        $recorder = $this->container()->get(ContactSubmissionRecorderInterface::class);
        self::assertInstanceOf(ContactSubmissionRecorderInterface::class, $recorder);
        $id = $recorder->record('support', ['email' => 'visitor@example.com'], 'en');
        self::assertGreaterThan(0, $id);
        self::assertSame(1, $this->rows('nowo_contact_submission'));
        $this->assertHostPending($host, $note, 'after a submission');

        // Admin edit through the repository (row version + flush in one transaction).
        $forms = $this->container()->get(ContactFormRepository::class);
        self::assertInstanceOf(ContactFormRepository::class, $forms);
        $form = $forms->find($formId);
        self::assertInstanceOf(ContactForm::class, $form);
        self::assertTrue($private->contains($form), 'the repositories read through the private manager');
        self::assertFalse($host->contains($form));
        $form->setName('Edited');
        $forms->saveEdit($form, $private, $form->getRowVersion());
        self::assertSame('Edited', $host->getConnection()->fetchOne('SELECT name FROM nowo_contact_form WHERE id = ?', [$formId]));
        $this->assertHostPending($host, $note, 'after an admin save');

        // A refused stale edit: domain answer, nothing written, the host untouched.
        $form->setName('Stale');
        try {
            $forms->saveEdit($form, $private, $form->getRowVersion() - 1);
            self::fail('A stale edit must be refused.');
        } catch (StaleVersionException) {
        }
        self::assertSame('Edited', $host->getConnection()->fetchOne('SELECT name FROM nowo_contact_form WHERE id = ?', [$formId]));
        $this->assertHostPending($host, $note, 'after a refused edit');

        // Retention cleanup.
        $host->getConnection()->executeStatement('UPDATE nowo_contact_submission SET created_at = ?', [(new DateTimeImmutable('-60 days'))->format('Y-m-d H:i:s')]);
        $cleaner = $this->container()->get(SubmissionRetentionCleanerInterface::class);
        self::assertInstanceOf(SubmissionRetentionCleanerInterface::class, $cleaner);
        self::assertSame(1, $cleaner->cleanup());
        self::assertSame(0, $this->rows('nowo_contact_submission'));
        $this->assertHostPending($host, $note, 'after the retention cleanup');

        // A failed bundle write (unknown form) closes nothing of the host.
        try {
            $recorder->record('unknown', [], 'en');
            self::fail('Unknown form.');
        } catch (Throwable) {
        }
        $this->assertHostPending($host, $note, 'after a failed write');

        // The host flushes its own changes when it decides to.
        $host->flush();
        self::assertSame(1, $this->rows('host_note'));
    }

    public function testAClosedPrivateManagerIsReplacedAndTheHostManagerStaysOpen(): void
    {
        $this->boot(['private_entity_manager' => true]);
        $host    = $this->hostManager();
        $private = $this->privateManager();
        $this->seedForm($private);
        $note = new HostNote('pending');
        $host->persist($note);

        // A duplicate slug: the private flush fails and the ORM closes the private manager only.
        $live = $private->live();
        try {
            $private->persist((new ContactForm())->setName('Dup')->setSlug('support'));
            $private->flush();
            self::fail('The duplicate slug must fail.');
        } catch (Throwable) {
        }
        self::assertFalse($live->isOpen());
        self::assertTrue($host->isOpen(), 'the host manager is never closed by a bundle failure');
        $this->assertHostPending($host, $note, 'after the failed private flush');

        $recorder = $this->container()->get(ContactSubmissionRecorderInterface::class);
        self::assertInstanceOf(ContactSubmissionRecorderInterface::class, $recorder);
        self::assertGreaterThan(0, $recorder->record('support', ['email' => 'a@example.com'], 'en'));
        self::assertNotSame($live, $private->live(), 'replaced on the next use');
        $this->assertHostPending($host, $note, 'after the retry');
    }

    /**
     * FrankenPHP worker mode, kernel reset off: one kernel, the services resetter never runs. The private manager is
     * dropped at the end of every request, message and command, so a unit reads the latest rows and keeps nothing.
     */
    public function testEveryBoundaryDropsThePrivateManagerWithoutTheServicesResetter(): void
    {
        $this->boot(['private_entity_manager' => true]);
        $host    = $this->hostManager();
        $private = $this->privateManager();
        $formId  = $this->seedForm($private);
        $note    = new HostNote('pending');
        $host->persist($note);
        $recorder = $this->container()->get(ContactSubmissionRecorderInterface::class);
        self::assertInstanceOf(ContactSubmissionRecorderInterface::class, $recorder);
        $id = $recorder->record('support', ['email' => 'first@example.com'], 'en');

        $http = $this->container()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        self::assertInstanceOf(TerminableInterface::class, $http);
        for ($i = 0; $i < 50; ++$i) {
            $value = sprintf('revision-%d@example.com', $i);
            $host->getConnection()->executeStatement('UPDATE nowo_contact_submission_value SET value = ? WHERE submission_id = ?', [$value, $id]);
            $request  = Request::create(sprintf('/admin/contact-forms/%d/submissions/%d', $formId, $id));
            $response = $http->handle($request);
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString($value, (string) $response->getContent(), sprintf('request %d reads the latest row', $i));
            $http->terminate($request, $response);
            self::assertFalse($private->isCreated(), sprintf('request %d: the private manager is dropped', $i));
            $this->assertHostPending($host, $note, sprintf('request %d', $i));
        }

        // Messenger message.
        $dispatcher = $this->container()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $envelope = new Envelope(new BoundaryUnitMessage(0));
        $dispatcher->dispatch(new WorkerMessageReceivedEvent($envelope, 'async'));
        $recorder->record('support', ['email' => 'message@example.com'], 'en');
        self::assertTrue($private->isCreated());
        $dispatcher->dispatch(new WorkerMessageHandledEvent($envelope, 'async'));
        self::assertFalse($private->isCreated(), 'dropped after the message');
        $this->assertHostPending($host, $note, 'after the message');

        // Console command.
        $application = new Application('private-manager-test');
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->setDispatcher($dispatcher);
        $command = (new Command('app:submit'))->setCode(static function () use ($recorder): int {
            $recorder->record('support', ['email' => 'command@example.com'], 'en');

            return Command::SUCCESS;
        });
        $application->setCommandLoader(new FactoryCommandLoader(['app:submit' => static fn (): Command => $command]));
        self::assertSame(Command::SUCCESS, $application->run(new ArrayInput(['command' => 'app:submit']), new NullOutput()));
        self::assertFalse($private->isCreated(), 'dropped after the command');
        $this->assertHostPending($host, $note, 'after the message and the command');
        self::assertSame(3, $this->rows('nowo_contact_submission'));
    }

    #[IgnoreDeprecations]
    public function testUnsetReportsTheHostsPendingChangesOncePerClassAndUnit(): void
    {
        $this->expectUserDeprecationMessage('Since nowo-tech/contact-form-bundle 1.13: ' . NowoContactFormExtension::PRIVATE_ENTITY_MANAGER_UNSET_DEPRECATION);
        $this->boot(['private_entity_manager' => null], fresh: true);
        $host = $this->hostManager();
        self::assertNotInstanceOf(PrivateEntityManager::class, $this->container()->get('nowo_contact_form.entity_manager'));
        $this->seedForm($host);
        $recorder = $this->container()->get(ContactSubmissionRecorderInterface::class);
        self::assertInstanceOf(ContactSubmissionRecorderInterface::class, $recorder);

        $messages = $this->deprecations(static function () use ($host, $recorder): void {
            $host->persist(new HostNote('first'));
            $recorder->record('support', ['email' => 'a@example.com'], 'en');
            $host->persist(new HostNote('second'));
            $recorder->record('support', ['email' => 'b@example.com'], 'en');
        });
        $expected = sprintf('Your pending changes to %s were flushed by %s; 2.0 no longer does.', self::NOTE_CLASS, ContactFormHostFlushReporter::BUNDLE_NAME);
        self::assertSame([$expected], $messages, 'one deprecation per class and unit of work');
        self::assertSame(2, $this->rows('host_note'), '1.x behaviour: the bundle flush wrote the host changes');

        // A new unit of work (an outermost boundary) reports again.
        $dispatcher = $this->container()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $envelope = new Envelope(new BoundaryUnitMessage(1));
        $dispatcher->dispatch(new WorkerMessageReceivedEvent($envelope, 'async'));
        $messages = $this->deprecations(static function () use ($host, $recorder): void {
            $host->persist(new HostNote('third'));
            $recorder->record('support', ['email' => 'c@example.com'], 'en');
        });
        $dispatcher->dispatch(new WorkerMessageHandledEvent($envelope, 'async'));
        self::assertSame([$expected], $messages);

        // Nothing pending on the host side: nothing reported.
        self::assertSame([], $this->deprecations(static function () use ($recorder): void {
            $recorder->record('support', ['email' => 'd@example.com'], 'en');
        }));
    }

    #[IgnoreDeprecations]
    public function testFalseKeepsTheHostModeWithItsNoticeAndTheProbe(): void
    {
        $this->expectUserDeprecationMessage('Since nowo-tech/contact-form-bundle 1.13: ' . NowoContactFormExtension::PRIVATE_ENTITY_MANAGER_FALSE_DEPRECATION);
        $this->boot(['private_entity_manager' => false], fresh: true);
        $host = $this->hostManager();
        self::assertNotInstanceOf(PrivateEntityManager::class, $this->container()->get('nowo_contact_form.entity_manager'));
        $this->seedForm($host);
        $host->persist(new HostNote('pending'));
        $recorder = $this->container()->get(ContactSubmissionRecorderInterface::class);
        self::assertInstanceOf(ContactSubmissionRecorderInterface::class, $recorder);

        self::assertSame([sprintf('Your pending changes to %s were flushed by %s; 2.0 no longer does.', self::NOTE_CLASS, ContactFormHostFlushReporter::BUNDLE_NAME)], $this->deprecations(static function () use ($recorder): void {
            $recorder->record('support', ['email' => 'a@example.com'], 'en');
        }));
        self::assertSame(1, $this->rows('host_note'), 'the 1.x behaviour until 2.0');
    }

    private function assertHostPending(EntityManagerInterface $host, HostNote $note, string $when): void
    {
        self::assertTrue($host->isOpen(), $when . ': the host manager is open');
        self::assertTrue($host->contains($note), $when . ': the host entity is still managed');
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($note), $when . ': its insert is still pending');
        self::assertSame(0, $this->rows('host_note'), $when . ': nothing of the host was written');
    }

    /**
     * @param callable(): void $unit
     *
     * @return list<string> the E_USER_DEPRECATED messages triggered by $unit
     */
    private function deprecations(callable $unit): array
    {
        $messages = [];
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler (test only, restored in the finally below)
        set_error_handler(static function (int $type, string $message) use (&$messages): bool {
            $messages[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        try {
            $unit();
        } finally {
            restore_error_handler();
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $doctrine nowo_contact_form.doctrine
     */
    private function boot(array $doctrine, bool $fresh = false): void
    {
        $database      = sys_get_temp_dir() . '/nowo_cf_private_' . getmypid() . '_' . count($this->files) . '.sqlite';
        $this->files[] = $database;
        if (is_file($database)) {
            unlink($database);
        }

        $this->kernel = new EntityManagerTestKernel([
            'dbal' => ['url' => 'sqlite:///' . $database],
            'orm'  => [
                'auto_mapping' => true,
                'mappings'     => ['HostApp' => [
                    'type'      => 'attribute',
                    'is_bundle' => false,
                    'dir'       => __DIR__ . '/Entity',
                    'prefix'    => 'Nowo\\ContactFormBundle\\Tests\\Integration\\EntityManager\\Entity',
                ]],
            ],
        ], ['doctrine' => $doctrine]);
        if ($fresh) {
            // The notice is triggered when the container is built: never reuse a container built by an earlier test.
            (new Filesystem())->remove($this->kernel->getCacheDir());
        }
        $this->kernel->boot();

        $host = $this->hostManager();
        (new SchemaTool($host))->createSchema($host->getMetadataFactory()->getAllMetadata());

        // DoctrineBundle clears every manager after each message (DoctrineClearEntityManagerWorkerSubscriber); without
        // it the host's pending entity shows what the bundle alone does at the message boundary.
        $dispatcher = $this->container()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        foreach ([WorkerMessageHandledEvent::class, WorkerMessageFailedEvent::class] as $event) {
            foreach ($dispatcher->getListeners($event) as $listener) {
                $clear = $this->doctrineClearListener($listener);
                if ($clear !== null) {
                    $dispatcher->removeListener($event, $clear);
                }
            }
        }
    }

    /**
     * DoctrineBundle's per-message clear listener, else null (getListeners() is typed differently on Symfony 7.4 and 8).
     */
    private function doctrineClearListener(mixed $listener): ?callable
    {
        return is_array($listener) && ($listener[0] ?? null) instanceof DoctrineClearEntityManagerWorkerSubscriber && is_callable($listener) ? $listener : null;
    }

    private function seedForm(EntityManagerInterface $manager): int
    {
        $form = (new ContactForm())
            ->setName('Support')
            ->setSlug('support')
            ->setEnabled(true)
            ->setRetentionDays(30)
            ->addTranslation((new ContactFormTranslation())->setLocale('en')->setTitle('Contact us'));
        $form->addField((new ContactFormField())
            ->setName('email')
            ->setType(ContactFieldType::Email)
            ->setForm($form)
            ->addTranslation((new ContactFormFieldTranslation())->setLocale('en')->setLabel('Email')));
        $manager->persist($form);
        $manager->flush();
        $id = (int) $form->getId();
        // Seeding is not part of the unit under test: start it with nothing managed.
        $manager->clear();

        return $id;
    }

    private function rows(string $table): int
    {
        return (int) $this->hostManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    private function kernel(): EntityManagerTestKernel
    {
        self::assertInstanceOf(EntityManagerTestKernel::class, $this->kernel);

        return $this->kernel;
    }

    private function container(): ContainerInterface
    {
        $container = $this->kernel()->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    private function hostManager(): EntityManagerInterface
    {
        $registry = $this->container()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $host = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $host);
        self::assertFalse($host->getMetadataFactory()->isTransient(ContactSubmission::class));

        return $host;
    }

    private function privateManager(): PrivateEntityManager
    {
        $private = $this->container()->get(NowoContactFormExtension::PRIVATE_ENTITY_MANAGER_SERVICE);
        self::assertInstanceOf(PrivateEntityManager::class, $private);

        return $private;
    }
}
