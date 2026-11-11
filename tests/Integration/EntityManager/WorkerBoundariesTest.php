<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\EntityManager;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ContactFormBundle\Client\ClientReference;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactFormFieldTranslation;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\Repository\ContactSubmissionRepository;
use Nowo\ContactFormBundle\Service\ContactSubmissionRecorderInterface;
use Nowo\ContactFormBundle\Tests\Integration\EntityManager\Entity\HostNote;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Bridge\Doctrine\Messenger\DoctrineClearEntityManagerWorkerSubscriber;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\ResetServicesListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;

use function gc_collect_cycles;
use function getmypid;
use function implode;
use function intdiv;
use function is_array;
use function is_callable;
use function is_file;
use function memory_get_usage;
use function sprintf;
use function str_contains;
use function sys_get_temp_dir;
use function unlink;

/**
 * Kept-alive kernel, services resetter never called (REQ-RUNTIME-002 rule 4): a host that removes or bypasses
 * services_resetter (http_kernel driven directly), `messenger:consume --no-reset`, long-running console processes.
 * The bundle entities live on their own manager (`doctrine.entity_manager: contact_form`); the host's default manager
 * holds a pending host entity the whole time.
 *
 * Two tenants (forms "alpha" and "beta", 10 submissions each). Before every unit, "another worker" rewrites the
 * e-mail value of the submission the unit is about to read (plain SQL on the bundle connection). Each unit must read
 * that latest value and nothing of the other tenant; after every unit the bundle manager's identity map is empty and
 * the host manager still holds its pending entity.
 *
 * N = 500 per path: HTTP (admin submission page) through http_kernel (no Kernel::handle(), so no resetter), Messenger
 * through a real Worker without ResetServicesListener and without DoctrineBundle's DoctrineClearEntityManagerWorkerSubscriber
 * (every 7th handler fails), and console Application runs. Memory
 * margin: 2 MiB after a 50-unit warm-up.
 */
final class WorkerBoundariesTest extends TestCase
{
    private const ITERATIONS    = 500;
    private const WARMUP        = 50;
    private const MEMORY_MARGIN = 2 * 1024 * 1024;
    private const PER_TENANT    = 10;

    private ?EntityManagerTestKernel $kernel = null;

    /** @var list<string> */
    private array $files = [];

    /** @var array<string, int> tenant => form id */
    private array $forms = [];

    /** @var array<string, list<int>> tenant => submission ids */
    private array $submissions = [];

    /** @var list<string> */
    private array $leaks = [];

    private int $memoryAtWarmup = 0;

    private int $memoryAtEnd = 0;

    private EntityManagerInterface $host;

    private EntityManagerInterface $bundle;

    private HostNote $note;

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

    public function testManyAdminRequestsKeepTenantsIsolatedAndTheBundleManagerEmpty(): void
    {
        $this->boot();
        $http = $this->container()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        self::assertInstanceOf(TerminableInterface::class, $http);

        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $tenant = $this->tenantFor($i);
            $id     = $this->submissionFor($tenant, $i);
            $this->editValue($id, $tenant, $i);
            $this->memoryMark($i);
            $request  = Request::create(sprintf('/admin/contact-forms/%d/submissions/%d', $this->forms[$tenant], $id));
            $response = $http->handle($request);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            $content = (string) $response->getContent();
            if (!str_contains($content, $this->value($tenant, $i)) || str_contains($content, '@' . $this->other($tenant) . '.example')) {
                $this->leaks[] = sprintf('request %d (tenant %s) did not render %s alone', $i, $tenant, $this->value($tenant, $i));
            }
            $http->terminate($request, $response);
            $this->checkManagers($i);
        }

        $this->assertNoLeakAndFlatMemory('HTTP requests');
    }

    public function testManyMessengerMessagesKeepTenantsIsolatedAndTheBundleManagerEmpty(): void
    {
        $this->boot();
        $dispatcher = $this->dispatcher();
        foreach ($dispatcher->getListeners(WorkerRunningEvent::class) as $listener) {
            self::assertFalse(
                is_array($listener) && $listener[0] instanceof ResetServicesListener,
                'The worker must run without ResetServicesListener (messenger:consume --no-reset).',
            );
        }

        $handled  = 0;
        $receiver = $this->receiver(self::ITERATIONS);
        $bus      = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            BoundaryUnitMessage::class => [function (BoundaryUnitMessage $message) use (&$handled): void {
                $index  = $message->index;
                $tenant = $this->tenantFor($index);
                $this->memoryMark($index);
                $this->checkRead($tenant, $index);
                ++$handled;
                if ($index % 7 === 3) {
                    throw new RuntimeException('Notifier down for tenant ' . $tenant);
                }
            }],
        ]))]);

        // After the bundle's own Handled / Failed listeners (-4096): the manager must be empty after every message.
        $finished  = 0;
        $afterUnit = function () use (&$finished): void {
            $this->checkManagers($finished++);
        };
        $dispatcher->addListener(WorkerMessageHandledEvent::class, $afterUnit, -8192);
        $dispatcher->addListener(WorkerMessageFailedEvent::class, $afterUnit, -8192);

        try {
            $this->runWorker(new Worker(['async' => $receiver], $bus, $dispatcher), $dispatcher);
        } finally {
            $dispatcher->removeListener(WorkerMessageHandledEvent::class, $afterUnit);
            $dispatcher->removeListener(WorkerMessageFailedEvent::class, $afterUnit);
        }

        self::assertSame(self::ITERATIONS, $handled);
        self::assertSame(self::ITERATIONS, $finished);
        $this->assertNoLeakAndFlatMemory('Messenger messages');
    }

    public function testManyConsoleRunsKeepTenantsIsolatedAndTheBundleManagerEmpty(): void
    {
        $this->boot();
        $command = (new Command('app:read-submission'))
            ->addArgument('index', InputArgument::REQUIRED)
            ->setCode(function (InputInterface $input, OutputInterface $output): int {
                $index = (int) $input->getArgument('index');
                $this->checkRead($this->tenantFor($index), $index);

                return Command::SUCCESS;
            });
        $application = $this->application();
        $application->setCommandLoader(new FactoryCommandLoader(['app:read-submission' => static fn (): Command => $command]));

        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $this->memoryMark($i);
            self::assertSame(Command::SUCCESS, $application->run(new ArrayInput(['command' => 'app:read-submission', 'index' => (string) $i]), new NullOutput()));
            $this->checkManagers($i);
        }

        $this->assertNoLeakAndFlatMemory('console command runs');
    }

    /**
     * A command run in-process from another command (Application::doRun()) does not clear the outer command's
     * manager; the outermost console.terminate does.
     */
    public function testNestedCommandKeepsTheOuterCommandsEntities(): void
    {
        $this->boot();
        $application = $this->application();
        $kept        = [];
        $inner       = (new Command('app:inner'))->setCode(static fn (): int => Command::SUCCESS);
        $outer       = (new Command('app:outer'))->setCode(function (InputInterface $input, OutputInterface $output) use ($application, &$kept): int {
            $submission = $this->repository()->find($this->submissions['A'][0]);
            self::assertInstanceOf(ContactSubmission::class, $submission);
            $application->doRun(new ArrayInput(['command' => 'app:inner']), $output);
            $application->doRun(new ArrayInput(['command' => 'app:inner']), $output);
            $kept[] = $this->bundle->contains($submission);

            return Command::SUCCESS;
        });
        $application->setCommandLoader(new FactoryCommandLoader([
            'app:outer' => static fn (): Command => $outer,
            'app:inner' => static fn (): Command => $inner,
        ]));

        self::assertSame(Command::SUCCESS, $application->run(new ArrayInput(['command' => 'app:outer']), new NullOutput()));
        self::assertSame([true], $kept, 'A nested command cleared the outer command\'s manager.');
        self::assertSame([], $this->bundle->getUnitOfWork()->getIdentityMap(), 'The outermost console.terminate clears the manager.');
    }

    /**
     * A command run in-process from a Messenger handler does not clear the handler's manager; the message boundaries
     * still clear it, and a standalone command clears again once the worker stopped.
     */
    public function testCommandRunFromAMessageHandlerKeepsTheHandlersEntities(): void
    {
        $this->boot();
        $dispatcher  = $this->dispatcher();
        $application = $this->application();
        $inner       = (new Command('app:inner'))->setCode(static fn (): int => Command::SUCCESS);
        $application->setCommandLoader(new FactoryCommandLoader(['app:inner' => static fn (): Command => $inner]));

        $kept = [];
        $bus  = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            BoundaryUnitMessage::class => [function (BoundaryUnitMessage $message) use ($application, &$kept): void {
                $index      = $message->index;
                $submission = $this->repository()->find($this->submissionFor($this->tenantFor($index), $index));
                self::assertInstanceOf(ContactSubmission::class, $submission);
                $application->doRun(new ArrayInput(['command' => 'app:inner']), new NullOutput());
                $kept[] = $this->bundle->contains($submission);
            }],
        ]))]);

        $this->runWorker(new Worker(['async' => $this->receiver(4)], $bus, $dispatcher), $dispatcher);

        self::assertSame([true, true, true, true], $kept, 'A command run from the handler cleared its manager.');
        self::assertSame([], $this->bundle->getUnitOfWork()->getIdentityMap(), 'The last WorkerMessageHandled clears the manager.');

        $this->repository()->find($this->submissions['B'][0]);
        $application->run(new ArrayInput(['command' => 'app:inner']), new NullOutput());
        self::assertSame([], $this->bundle->getUnitOfWork()->getIdentityMap(), 'A standalone command clears once the worker stopped.');
    }

    /**
     * Lesson of LegalKit 1.12: a manager with changes waiting to be written is never cleared (the changes would be
     * lost); a closed manager is recreated at the boundary.
     */
    public function testPendingChangesAreKeptAndAClosedManagerIsRecreated(): void
    {
        $this->boot();
        $application = $this->application();
        $noop        = (new Command('app:noop'))->setCode(static fn (): int => Command::SUCCESS);
        $application->setCommandLoader(new FactoryCommandLoader(['app:noop' => static fn (): Command => $noop]));

        $form = $this->bundle->find(ContactForm::class, $this->forms['A']);
        self::assertInstanceOf(ContactForm::class, $form);
        $form->setName('Renamed, not flushed yet');
        $application->run(new ArrayInput(['command' => 'app:noop']), new NullOutput());
        self::assertTrue($this->bundle->contains($form), 'A pending update must survive the boundary.');
        $this->bundle->flush();
        $application->run(new ArrayInput(['command' => 'app:noop']), new NullOutput());
        self::assertSame([], $this->bundle->getUnitOfWork()->getIdentityMap());
        self::assertSame('Renamed, not flushed yet', $this->bundle->getConnection()->fetchOne('SELECT name FROM nowo_contact_form WHERE id = ?', [$this->forms['A']]));

        $this->bundle->close();
        $application->run(new ArrayInput(['command' => 'app:noop']), new NullOutput());
        $reopened = $this->registry()->getManager('contact_form');
        self::assertInstanceOf(EntityManagerInterface::class, $reopened);
        self::assertTrue($reopened->isOpen(), 'The boundary recreates a closed bundle manager.');
        self::assertTrue($this->host->contains($this->note), 'The host manager is never cleared or reset.');
    }

    private function boot(): void
    {
        $this->kernel = new EntityManagerTestKernel([
            'dbal' => [
                'default_connection' => 'default',
                'connections'        => [
                    'default'      => ['url' => 'sqlite:///' . $this->database('host')],
                    'contact_form' => ['url' => 'sqlite:///' . $this->database('bundle')],
                ],
            ],
            'orm' => [
                'default_entity_manager' => 'default',
                'entity_managers'        => [
                    'default' => [
                        'connection'   => 'default',
                        'auto_mapping' => true,
                        'mappings'     => ['HostApp' => [
                            'type'      => 'attribute',
                            'is_bundle' => false,
                            'dir'       => __DIR__ . '/Entity',
                            'prefix'    => 'Nowo\\ContactFormBundle\\Tests\\Integration\\EntityManager\\Entity',
                        ]],
                    ],
                    'contact_form' => ['connection' => 'contact_form'],
                ],
            ],
        ], ['doctrine' => ['entity_manager' => 'contact_form']]);
        $this->kernel->boot();

        $host   = $this->registry()->getManager('default');
        $bundle = $this->registry()->getManager('contact_form');
        self::assertInstanceOf(EntityManagerInterface::class, $host);
        self::assertInstanceOf(EntityManagerInterface::class, $bundle);
        $this->host   = $host;
        $this->bundle = $bundle;
        (new SchemaTool($host))->createSchema($host->getMetadataFactory()->getAllMetadata());
        (new SchemaTool($bundle))->createSchema($bundle->getMetadataFactory()->getAllMetadata());

        $recorder = $this->container()->get(ContactSubmissionRecorderInterface::class);
        self::assertInstanceOf(ContactSubmissionRecorderInterface::class, $recorder);
        foreach (['A' => 'alpha', 'B' => 'beta'] as $tenant => $slug) {
            $form = (new ContactForm())
                ->setName('Form ' . $slug)
                ->setSlug($slug)
                ->setEnabled(true)
                ->addTranslation((new ContactFormTranslation())->setLocale('en')->setTitle('Contact ' . $slug));
            $form->addField((new ContactFormField())
                ->setName('email')
                ->setType(ContactFieldType::Email)
                ->setForm($form)
                ->addTranslation((new ContactFormFieldTranslation())->setLocale('en')->setLabel('Email')));
            $bundle->persist($form);
            $bundle->flush();
            $this->forms[$tenant] = (int) $form->getId();
            for ($n = 0; $n < self::PER_TENANT; ++$n) {
                $this->submissions[$tenant][] = $recorder->record($slug, ['email' => 'seed-' . $n . '@' . $tenant . '.example'], 'en', client: new ClientReference('tenant-' . $tenant));
            }
        }
        $bundle->clear();

        // DoctrineBundle clears every manager after each message (DoctrineClearEntityManagerWorkerSubscriber); a host
        // without it (or without symfony/doctrine-bridge's Messenger integration) relies on the bundle alone.
        $dispatcher = $this->dispatcher();
        foreach ([WorkerMessageHandledEvent::class, WorkerMessageFailedEvent::class] as $event) {
            foreach ($dispatcher->getListeners($event) as $listener) {
                if (is_array($listener) && $listener[0] instanceof DoctrineClearEntityManagerWorkerSubscriber && is_callable($listener)) {
                    $dispatcher->removeListener($event, $listener);
                }
            }
        }

        // A host change the bundle must never flush, clear or reset.
        $this->note = new HostNote('pending host change');
        $host->persist($this->note);

        $this->leaks          = [];
        $this->memoryAtWarmup = 0;
        $this->memoryAtEnd    = 0;
    }

    /**
     * Another worker rewrites the e-mail of one submission (plain SQL, so this worker's identity map is not told).
     */
    private function editValue(int $submissionId, string $tenant, int $index): void
    {
        $this->bundle->getConnection()->executeStatement(
            'UPDATE nowo_contact_submission_value SET value = ? WHERE submission_id = ?',
            [$this->value($tenant, $index), $submissionId],
        );
    }

    /**
     * One non-HTTP unit of work (message handler or command): reads the tenant's submission after another worker
     * edited it, then prepares the edit the next unit must see.
     */
    private function checkRead(string $tenant, int $index): void
    {
        if ($index === 0) {
            $this->editValue($this->submissionFor($tenant, 0), $tenant, 0);
        }
        $submission = $this->repository()->find($this->submissionFor($tenant, $index));
        self::assertInstanceOf(ContactSubmission::class, $submission);
        $values = [];
        foreach ($submission->getValues() as $value) {
            $values[] = $value->getValue();
        }
        if ($values !== [$this->value($tenant, $index)] || $submission->getForm()?->getId() !== $this->forms[$tenant]) {
            $this->leaks[] = sprintf('unit %d (tenant %s) read %s', $index, $tenant, implode(', ', $values));
        }

        $next = $index + 1;
        $this->editValue($this->submissionFor($this->tenantFor($next), $next), $this->tenantFor($next), $next);
    }

    private function checkManagers(int $index): void
    {
        if ($this->bundle->getUnitOfWork()->getIdentityMap() !== []) {
            $this->leaks[] = sprintf('after unit %d the bundle manager still holds entities', $index);
        }
        if (!$this->host->contains($this->note) || !$this->host->getUnitOfWork()->isScheduledForInsert($this->note)) {
            $this->leaks[] = sprintf('after unit %d the host manager lost its pending entity', $index);
        }
    }

    private function value(string $tenant, int $index): string
    {
        return sprintf('revision-%d@%s.example', $index, $tenant);
    }

    private function other(string $tenant): string
    {
        return $tenant === 'A' ? 'B' : 'A';
    }

    private function tenantFor(int $index): string
    {
        return $index % 2 === 0 ? 'A' : 'B';
    }

    private function submissionFor(string $tenant, int $index): int
    {
        return $this->submissions[$tenant][intdiv($index, 2) % self::PER_TENANT];
    }

    private function receiver(int $count): ReceiverInterface
    {
        return new class($count) implements ReceiverInterface {
            private int $next = 0;

            public function __construct(
                private readonly int $count,
            ) {
            }

            public function get(): iterable
            {
                if ($this->next >= $this->count) {
                    return [];
                }

                return [new Envelope(new BoundaryUnitMessage($this->next++))];
            }

            public function ack(Envelope $envelope): void
            {
            }

            public function reject(Envelope $envelope): void
            {
            }
        };
    }

    private function runWorker(Worker $worker, EventDispatcherInterface $dispatcher): void
    {
        $stopOnIdle = static function (WorkerRunningEvent $event): void {
            if ($event->isWorkerIdle()) {
                $event->getWorker()->stop();
            }
        };
        $dispatcher->addListener(WorkerRunningEvent::class, $stopOnIdle);

        try {
            $worker->run(['sleep' => 0]);
        } finally {
            $dispatcher->removeListener(WorkerRunningEvent::class, $stopOnIdle);
        }
    }

    private function memoryMark(int $index): void
    {
        if ($index === self::WARMUP) {
            gc_collect_cycles();
            $this->memoryAtWarmup = memory_get_usage();
        }
        if ($index === self::ITERATIONS - 1) {
            gc_collect_cycles();
            $this->memoryAtEnd = memory_get_usage();
        }
    }

    private function assertNoLeakAndFlatMemory(string $path): void
    {
        self::assertSame([], $this->leaks, sprintf('Stale, foreign or kept entities between %s.', $path));
        self::assertGreaterThan(0, $this->memoryAtWarmup);
        self::assertLessThan(self::MEMORY_MARGIN, $this->memoryAtEnd - $this->memoryAtWarmup, sprintf('Memory grew across %s.', $path));
    }

    private function application(): Application
    {
        $application = new Application('worker-test');
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->setDispatcher($this->dispatcher());

        return $application;
    }

    private function repository(): ContactSubmissionRepository
    {
        $repository = $this->container()->get(ContactSubmissionRepository::class);
        self::assertInstanceOf(ContactSubmissionRepository::class, $repository);

        return $repository;
    }

    private function container(): ContainerInterface
    {
        self::assertInstanceOf(EntityManagerTestKernel::class, $this->kernel);
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    private function registry(): ManagerRegistry
    {
        $registry = $this->container()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return $registry;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = $this->container()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    private function database(string $name): string
    {
        $file = sprintf('%s/nowo_contact_form_boundaries_%s_%d.sqlite', sys_get_temp_dir(), $name, (int) getmypid());
        if (is_file($file)) {
            unlink($file);
        }
        $this->files[] = $file;

        return $file;
    }
}
