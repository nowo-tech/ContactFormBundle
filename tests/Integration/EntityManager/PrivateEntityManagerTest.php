<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\EntityManager;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\ContactFormBundle\Concurrency\StaleVersionException;
use Nowo\ContactFormBundle\DependencyInjection\Configuration;
use Nowo\ContactFormBundle\DependencyInjection\NowoContactFormExtension;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactFormFieldTranslation;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\Exception\ContactFormNotFoundException;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Nowo\ContactFormBundle\Service\ContactSubmissionProcessor;
use Nowo\ContactFormBundle\Service\ContactSubmissionRecorderInterface;
use Nowo\ContactFormBundle\Service\SubmissionRetentionCleanerInterface;
use Nowo\ContactFormBundle\Tests\Integration\EntityManager\Entity\HostNote;
use Nowo\ContactFormBundle\Tests\Integration\EntityManager\LinkedEntity\HostFormLink;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Messenger\DoctrineClearEntityManagerWorkerSubscriber;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Throwable;

use function array_filter;
use function array_unique;
use function array_values;
use function count;
use function getmypid;
use function is_array;
use function is_callable;
use function is_file;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function str_contains;
use function sys_get_temp_dir;
use function unlink;

use const E_USER_DEPRECATED;

/**
 * 2.0 (REQ-ARCH-001 rule 6): the private entity manager, the only mode without a dedicated `doctrine.entity_manager`,
 * through a real kernel on SQLite, one default manager mapping both the bundle entities and host entities (the
 * zero-config standard host), one of them with a ManyToOne to a bundle entity (W1-2).
 *
 * - every bundle write goes through the private manager; a host entity left pending in the host's manager stays
 *   pending (not written, still managed) after a submission, an admin save, the retention cleanup and a failed write;
 *   the private manager is dropped at every request / message / command boundary (kernel kept alive, services resetter
 *   never called) and replaced when closed;
 * - the setting table: unset, `entity_manager` naming the default manager and the deprecated `true` all use the private
 *   manager (only `true` triggers a notice, no host-flush probe any more); `false` fails the container build;
 * - W1-2: a form loaded by the host manager is re-resolved, a host entity referencing a bundle entity through
 *   `getReference()` is flushed by the host, an application entity held by the private manager fails the write.
 */
final class PrivateEntityManagerTest extends TestCase
{
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
        $this->boot([]);
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
        $this->boot([]);
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
        $this->boot([]);
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

    /**
     * The 2.0 setting table (plan D2): which manager writes, whether the host manager is flushed, notice or build error.
     *
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function privateSettings(): iterable
    {
        yield 'unset (the 1.x default)' => [[], false];
        yield 'entity_manager naming the default manager (host mode in 1.x, audit XH-2)' => [['entity_manager' => 'default'], false];
        yield 'private_entity_manager: true (deprecated no-op)' => [['private_entity_manager' => true], true];
    }

    /**
     * @param array<string, mixed> $doctrine
     */
    #[DataProvider('privateSettings')]
    #[IgnoreDeprecations]
    public function testEverySettingOnTheApplicationsManagerWritesThroughThePrivateManager(array $doctrine, bool $notice): void
    {
        $messages = array_values(array_unique(array_filter(
            $this->deprecations(fn () => $this->boot($doctrine, fresh: true)),
            static fn (string $message): bool => str_contains($message, 'contact-form-bundle') || str_contains($message, 'ContactForm'),
        )));
        $expected = $notice ? ['Since nowo-tech/contact-form-bundle 2.0: ' . Configuration::PRIVATE_ENTITY_MANAGER_DEPRECATION] : [];
        self::assertSame($expected, $messages, 'only the deprecated key triggers a notice of the bundle');

        $host    = $this->hostManager();
        $private = $this->container()->get('nowo_contact_form.entity_manager');
        self::assertInstanceOf(PrivateEntityManager::class, $private);
        self::assertSame($host->getConnection(), $private->getConnection());
        $this->seedForm($private);
        $note = new HostNote('pending');
        $host->persist($note);

        $recorder = $this->container()->get(ContactSubmissionRecorderInterface::class);
        self::assertInstanceOf(ContactSubmissionRecorderInterface::class, $recorder);
        self::assertSame([], $this->deprecations(static function () use ($recorder): void {
            $recorder->record('support', ['email' => 'a@example.com'], 'en');
        }), 'no host-flush probe in 2.0');
        self::assertSame(1, $this->rows('nowo_contact_submission'));
        $this->assertHostPending($host, $note, 'after a submission');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedSettings(): iterable
    {
        yield 'false' => [['private_entity_manager' => false], 'The bundle then writes through its private entity manager'];
        yield 'false with the default manager named' => [['private_entity_manager' => false, 'entity_manager' => 'default'], 'doctrine.entity_manager "default" stays'];
    }

    /**
     * @param array<string, mixed> $doctrine
     */
    #[DataProvider('refusedSettings')]
    #[IgnoreDeprecations]
    public function testFalseFailsTheBuild(array $doctrine, string $hint): void
    {
        try {
            $this->boot($doctrine, fresh: true);
            self::fail('private_entity_manager: false must fail the container build.');
        } catch (InvalidConfigurationException $exception) {
            self::assertStringContainsString('private_entity_manager: false is no longer supported', $exception->getMessage());
            self::assertStringContainsString($hint, $exception->getMessage());
        }
    }

    /**
     * W1-2: a contact form the application loaded through its own manager (a route value resolver, `find()`) is
     * re-loaded by id in the private manager: no duplicate form row, the host manager keeps its instance untouched.
     */
    public function testAFormLoadedByTheHostManagerIsReResolvedNeverPersistedAsNew(): void
    {
        $this->boot([]);
        $host   = $this->hostManager();
        $formId = $this->seedForm($this->privateManager());
        $note   = new HostNote('pending');
        $host->persist($note);

        $hostForm = $host->find(ContactForm::class, $formId);
        self::assertInstanceOf(ContactForm::class, $hostForm);
        $hostForm->setName('Changed by the host, not flushed');

        $processor = $this->container()->get(ContactSubmissionProcessor::class);
        self::assertInstanceOf(ContactSubmissionProcessor::class, $processor);
        $submission = $processor->process($hostForm, $this->submittedForm('visitor@example.com'), Request::create('/'), 'en');

        self::assertNotNull($submission->getId());
        self::assertNotSame($hostForm, $submission->getForm(), 'the private manager\'s own instance');
        self::assertSame($formId, $submission->getForm()?->getId());
        self::assertSame(1, $this->rows('nowo_contact_form'), 'no duplicate form row');
        self::assertSame(1, $this->rows('nowo_contact_submission'));
        self::assertSame('visitor@example.com', $host->getConnection()->fetchOne('SELECT value FROM nowo_contact_submission_value'));
        self::assertSame('Support', $host->getConnection()->fetchOne('SELECT name FROM nowo_contact_form'), 'the host\'s pending change is not written');
        self::assertTrue($host->contains($hostForm), 'the host keeps its instance');
        self::assertSame('Changed by the host, not flushed', $hostForm->getName());
        $this->assertHostPending($host, $note, 'after a submission with a host-loaded form');

        // A form that was never stored (or whose row is gone) fails clearly before anything is written.
        $host->getConnection()->executeStatement('DELETE FROM nowo_contact_submission_value');
        $host->getConnection()->executeStatement('DELETE FROM nowo_contact_submission');
        try {
            $processor->process((new ContactForm())->setName('New')->setSlug('never-stored'), $this->submittedForm('x@example.com'), Request::create('/'), 'en');
            self::fail('An unknown form must be refused.');
        } catch (ContactFormNotFoundException) {
        }
        self::assertSame(0, $this->rows('nowo_contact_submission'));
        self::assertSame(1, $this->rows('nowo_contact_form'));
    }

    /**
     * W1-2 (W0-9): a host entity pointing at a bundle entity through `$hostEm->getReference()` is flushed by the host
     * with its foreign key; the bundle's own writes in between leave it pending.
     */
    public function testAHostEntityReferencingABundleEntityThroughGetReferenceFlushes(): void
    {
        $this->boot([]);
        $host   = $this->hostManager();
        $formId = $this->seedForm($this->privateManager());

        $reference = $host->getReference(ContactForm::class, $formId);
        self::assertInstanceOf(ContactForm::class, $reference);
        $link = new HostFormLink($reference);
        $host->persist($link);

        $recorder = $this->container()->get(ContactSubmissionRecorderInterface::class);
        self::assertInstanceOf(ContactSubmissionRecorderInterface::class, $recorder);
        $recorder->record('support', ['email' => 'a@example.com'], 'en');
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($link), 'the bundle write left the host insert pending');
        self::assertSame(0, $this->rows('host_form_link'));

        $host->flush();
        self::assertSame($formId, (int) $host->getConnection()->fetchOne('SELECT form_id FROM host_form_link'));
        self::assertSame(1, $this->rows('nowo_contact_form'), 'the referenced form is not inserted again');
    }

    /**
     * W1-2: the private manager writes only the bundle's entities. An application entity that ended up in it (loaded or
     * persisted through `nowo_contact_form.entity_manager`) fails the next bundle write before anything is written.
     */
    public function testAnApplicationEntityHeldByThePrivateManagerFailsTheWrite(): void
    {
        $this->boot([]);
        $host    = $this->hostManager();
        $private = $this->privateManager();
        $this->seedForm($private);
        $private->persist(new HostNote('wrong manager'));

        $recorder = $this->container()->get(ContactSubmissionRecorderInterface::class);
        self::assertInstanceOf(ContactSubmissionRecorderInterface::class, $recorder);
        try {
            $recorder->record('support', ['email' => 'a@example.com'], 'en');
            self::fail('An application entity in the private manager must fail the write.');
        } catch (LogicException $exception) {
            self::assertStringContainsString(HostNote::class . ' entity is held by NowoContactFormBundle\'s private entity manager', $exception->getMessage());
        }
        self::assertSame(0, $this->rows('nowo_contact_submission'));
        self::assertSame(0, $this->rows('host_note'));
        self::assertTrue($host->isOpen());

        // The same entity flushed by the application through its own manager is written normally.
        $host->persist(new HostNote('right manager'));
        $host->flush();
        self::assertSame(1, $this->rows('host_note'));
    }

    /**
     * @return FormInterface<mixed> a submitted public form with an "email" field
     */
    private function submittedForm(string $email): FormInterface
    {
        $factory = $this->container()->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $factory);
        $form = $factory->createNamedBuilder('form', FormType::class, null, ['csrf_protection' => false])
            ->add('email', TextType::class)
            ->getForm();
        $form->submit(['email' => $email]);

        return $form;
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
                'mappings'     => [
                    'HostApp' => [
                        'type'      => 'attribute',
                        'is_bundle' => false,
                        'dir'       => __DIR__ . '/Entity',
                        'prefix'    => 'Nowo\\ContactFormBundle\\Tests\\Integration\\EntityManager\\Entity',
                    ],
                    // W1-2: a host entity with a ManyToOne to a bundle entity (same manager as the bundle entities).
                    'HostLinks' => [
                        'type'      => 'attribute',
                        'is_bundle' => false,
                        'dir'       => __DIR__ . '/LinkedEntity',
                        'prefix'    => 'Nowo\\ContactFormBundle\\Tests\\Integration\\EntityManager\\LinkedEntity',
                    ],
                ],
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
