<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\EntityManager;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ContactFormBundle\Client\ClientReference;
use Nowo\ContactFormBundle\Client\ClientReferenceResolverInterface;
use Nowo\ContactFormBundle\Doctrine\ClientReferenceCollationListener;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactFormFieldTranslation;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\Event\ContactSubmissionCreatedEvent;
use Nowo\ContactFormBundle\Event\ContactSubmissionsDeletedEvent;
use Nowo\ContactFormBundle\Exception\ContactFormNotFoundException;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Nowo\ContactFormBundle\Service\ContactSubmissionRecorderInterface;
use Nowo\ContactFormBundle\Service\SubmissionRetentionCleanupService;
use Nowo\ContactFormBundle\Tests\Integration\EntityManager\Entity\HostNote;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_keys;
use function gc_collect_cycles;
use function getmypid;
use function is_file;
use function memory_get_usage;
use function sprintf;
use function sys_get_temp_dir;
use function unlink;

/**
 * `doctrine.entity_manager` (1.7, architecture bar rule 6) through a real kernel: a standard host keeps the default
 * manager; a DDD host maps the entities on a dedicated manager only (also with `auto_mapping: true` on the default and
 * without `default_entity_manager`); the bundle never flushes, clears or resets the host's manager; the request-free
 * recorder stores any client id type; in a kept-alive kernel only the bundle's manager is recovered.
 */
final class DedicatedEntityManagerTest extends TestCase
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

    public function testStandardHostKeepsTheDefaultManager(): void
    {
        $this->boot([
            'dbal' => ['url' => 'sqlite:///' . $this->database('standard')],
            'orm'  => ['auto_mapping' => true],
        ]);
        $default = $this->registry()->getManager();

        self::assertSame(['default'], array_keys((array) $this->container()->getParameter('doctrine.entity_managers')));
        self::assertSame($default, $this->registry()->getManagerForClass(ContactSubmission::class));
        self::assertSame($default, $this->container()->get('nowo_contact_form.entity_manager'));

        // LI-CF-L2: the collation listener is wired on the schema tool events of the manager.
        self::assertInstanceOf(EntityManagerInterface::class, $default);
        $listeners = $default->getEventManager()->getListeners(ToolEvents::postGenerateSchemaTable);
        self::assertContains(ClientReferenceCollationListener::class, array_map(static fn (object $l): string => $l::class, $listeners));
    }

    public function testDedicatedManagerStoresSubmissionsWithoutTouchingTheHostManager(): void
    {
        $this->boot($this->dedicatedDoctrine(), ['doctrine' => ['entity_manager' => 'contact_form']]);
        [$host, $bundle] = $this->managersWithSchema();

        self::assertSame('default', $this->container()->getParameter('doctrine.default_entity_manager'));
        self::assertTrue($host->getMetadataFactory()->isTransient(ContactSubmission::class), 'not mapped on the auto-mapped host manager');
        self::assertSame($bundle, $this->registry()->getManagerForClass(ContactSubmission::class));
        self::assertSame($bundle, $this->container()->get('nowo_contact_form.entity_manager'));

        $created    = [];
        $dispatcher = $this->container()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(ContactSubmissionCreatedEvent::class, static function (ContactSubmissionCreatedEvent $event) use (&$created): void {
            $created[] = $event;
        });

        $note = new HostNote('pending host change');
        $host->persist($note);

        $recorder = $this->container()->get(ContactSubmissionRecorderInterface::class);
        self::assertInstanceOf(ContactSubmissionRecorderInterface::class, $recorder);
        $id = $recorder->record(
            'support',
            ['email' => 'visitor@example.com', 'not_a_field' => 'ignored'],
            'en',
            client: new ClientReference('01890a5d-ac96-774b-bcce-b302099a8057', 'ACME'),
        );

        self::assertSame(0, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM host_note'), 'the host manager is never flushed');
        self::assertTrue($host->contains($note), 'nor cleared');
        $row = $bundle->getConnection()->fetchAssociative('SELECT client_id, client_reference, client_label FROM nowo_contact_submission WHERE id = ?', [$id]);
        self::assertSame(['client_id' => null, 'client_reference' => '01890a5d-ac96-774b-bcce-b302099a8057', 'client_label' => 'ACME'], $row);
        self::assertSame(['email'], $bundle->getConnection()->fetchFirstColumn('SELECT field_name FROM nowo_contact_submission_value'));

        self::assertCount(1, $created);
        self::assertSame($id, $created[0]->getSubmissionId());
        self::assertSame('support', $created[0]->getFormSlug());
        self::assertSame('01890a5d-ac96-774b-bcce-b302099a8057', $created[0]->getClientReference());
        self::assertSame(['email' => 'visitor@example.com'], $created[0]->getFieldValues());

        // A field without a value is skipped (no empty value row).
        $empty = $recorder->record('support', [], 'en');
        self::assertSame(0, (int) $bundle->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_contact_submission_value WHERE submission_id = ?', [$empty]));

        $this->expectException(ContactFormNotFoundException::class);
        $recorder->record('unknown', [], 'en');
    }

    /**
     * DoctrineBundle 3.4 regression: `auto_discover_entities` (on by default for a manager without `mappings` and
     * `auto_mapping`) gives the host manager's chain a default attribute driver, which claims every `#[ORM\Entity]`
     * class, so `getManagerForClass()` answered the host manager and the repositories queried its database. The
     * explicit catch-all default driver reproduces it on every DoctrineBundle version.
     */
    #[DataProvider('hostManagersWithADefaultDriver')]
    public function testDedicatedManagerWinsOverADefaultDriverOfTheHostManager(bool $explicitDefaultDriver): void
    {
        $doctrine = $this->dedicatedDoctrine();
        unset($doctrine['orm']['entity_managers']['default']['auto_mapping'], $doctrine['orm']['entity_managers']['default']['mappings']);
        $this->boot($doctrine, ['doctrine' => ['entity_manager' => 'contact_form']], $explicitDefaultDriver);
        $bundle = $this->registry()->getManager('contact_form');

        // Neither manager is initialized yet: DoctrineBundle 3.4 asks the metadata drivers.
        self::assertSame($bundle, $this->registry()->getManagerForClass(ContactSubmission::class));
        self::assertSame($bundle, $this->registry()->getManagerForClass(ContactForm::class));

        [$host, $bundle] = $this->managersWithSchema();
        self::assertTrue($host->getMetadataFactory()->isTransient(ContactSubmission::class), 'not mapped on the host manager');
        self::assertSame($bundle, $this->registry()->getManagerForClass(ContactSubmission::class));

        $repository = $this->container()->get(ContactFormRepository::class);
        self::assertInstanceOf(ContactFormRepository::class, $repository);
        self::assertInstanceOf(ContactForm::class, $repository->findOneBy(['slug' => 'support']), 'the repository reads the bundle database');
        $recorder = $this->container()->get(ContactSubmissionRecorderInterface::class);
        self::assertInstanceOf(ContactSubmissionRecorderInterface::class, $recorder);
        $id = $recorder->record('support', ['email' => 'visitor@example.com'], 'en');
        self::assertSame(1, (int) $bundle->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_contact_submission WHERE id = ?', [$id]), 'written to the bundle database');
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function hostManagersWithADefaultDriver(): iterable
    {
        yield 'host manager without mappings (DoctrineBundle 3.4 auto-discovery)' => [false];
        yield 'host manager with a catch-all default driver' => [true];
    }

    public function testRetentionCleanupNeverClearsTheHostManager(): void
    {
        $this->boot($this->dedicatedDoctrine(), ['doctrine' => ['entity_manager' => 'contact_form']]);
        [$host, $bundle] = $this->managersWithSchema();
        $form            = $bundle->getRepository(ContactForm::class)->findOneBy(['slug' => 'support']);
        self::assertInstanceOf(ContactForm::class, $form);
        $form->setRetentionDays(1);
        $bundle->flush();

        $recorder = $this->container()->get(ContactSubmissionRecorderInterface::class);
        self::assertInstanceOf(ContactSubmissionRecorderInterface::class, $recorder);
        $id = $recorder->record('support', ['email' => 'old@example.com'], 'en');
        $bundle->getConnection()->executeStatement("UPDATE nowo_contact_submission SET created_at = '2000-01-01 00:00:00'");

        $note = new HostNote('managed by the host');
        $host->persist($note);
        $host->flush();

        $deleted    = [];
        $dispatcher = $this->container()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(ContactSubmissionsDeletedEvent::class, static function (ContactSubmissionsDeletedEvent $event) use (&$deleted): void {
            $deleted[] = $event;
        });

        $cleanup = $this->container()->get(SubmissionRetentionCleanupService::class);
        self::assertInstanceOf(SubmissionRetentionCleanupService::class, $cleanup);
        self::assertSame(1, $cleanup->cleanup());

        self::assertTrue($host->contains($note), 'the host manager is not cleared');
        self::assertSame(0, (int) $bundle->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_contact_submission'));
        self::assertCount(1, $deleted);
        self::assertSame([$id], $deleted[0]->getSubmissionIds());
        self::assertSame(ContactSubmissionsDeletedEvent::REASON_RETENTION, $deleted[0]->getReason());
    }

    /**
     * `client_reference_resolver` (1.7): the public form asks the id-reference port instead of the entity resolver.
     */
    public function testPublicFormUsesTheConfiguredClientReferenceResolver(): void
    {
        $this->boot($this->dedicatedDoctrine(), [
            'doctrine'                  => ['entity_manager' => 'contact_form'],
            'client_reference_resolver' => 'test.client_reference_resolver',
        ]);
        [, $bundle] = $this->managersWithSchema();
        $this->kernel()->getContainer()->set('test.client_reference_resolver', new class implements ClientReferenceResolverInterface {
            public function resolve(): ClientReference
            {
                return new ClientReference('org-7', 'Org seven');
            }
        });

        $client = new KernelBrowser($this->kernel());
        $client->disableReboot();
        $crawler = $client->request('GET', '/en/contact/support');
        $token   = $crawler->filterXPath('//input[@name="form[_token]"]')->attr('value');
        $client->request('POST', '/en/contact/support', ['form' => ['email' => 'visitor@example.com', '_token' => $token]]);
        self::assertSame(302, $client->getResponse()->getStatusCode());

        self::assertSame(
            ['client_reference' => 'org-7', 'client_label' => 'Org seven'],
            $bundle->getConnection()->fetchAssociative('SELECT client_reference, client_label FROM nowo_contact_submission'),
        );
    }

    public function testHostWithoutADefaultManagerNameKeepsItsOwnDefault(): void
    {
        $this->boot($this->dedicatedDoctrine('main', false), ['doctrine' => ['entity_manager' => 'contact_form']]);

        self::assertSame(['main', 'contact_form'], array_keys((array) $this->container()->getParameter('doctrine.entity_managers')));
        self::assertSame('main', $this->container()->getParameter('doctrine.default_entity_manager'));
        $main = $this->registry()->getManager('main');
        self::assertInstanceOf(EntityManagerInterface::class, $main);
        self::assertTrue($main->getMetadataFactory()->isTransient(ContactSubmission::class));
        self::assertSame($this->registry()->getManager('contact_form'), $this->registry()->getManagerForClass(ContactSubmission::class));
    }

    public function testUnknownManagerFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_contact_form.doctrine.entity_manager is "reports"');

        $this->boot($this->dedicatedDoctrine(), ['doctrine' => ['entity_manager' => 'reports']]);
    }

    /**
     * Kept-alive kernel, no services resetter: a closed bundle manager is recovered by the next write; the host manager
     * keeps its entities; memory stays flat over many submissions.
     */
    public function testWorkerRecoversOnlyTheBundleManager(): void
    {
        $this->boot($this->dedicatedDoctrine(), ['doctrine' => ['entity_manager' => 'contact_form']]);
        [$host, $bundle] = $this->managersWithSchema();
        $note            = new HostNote('managed by the host');
        $host->persist($note);
        $host->flush();
        $recorder = $this->container()->get(ContactSubmissionRecorderInterface::class);
        self::assertInstanceOf(ContactSubmissionRecorderInterface::class, $recorder);

        $bundle->close();
        $recorder->record('support', ['email' => 'a@example.com'], 'en');
        self::assertTrue($bundle->isOpen(), 'the bundle manager is recovered in place');
        self::assertTrue($host->contains($note), 'the host manager is neither reset nor cleared');

        $recorder->record('support', ['email' => 'b@example.com'], 'en', client: new ClientReference('42'));
        gc_collect_cycles();
        $baseline = memory_get_usage();
        for ($i = 0; $i < 500; ++$i) {
            $recorder->record('support', ['email' => 'c@example.com'], 'en', client: new ClientReference('client-' . $i));
        }
        gc_collect_cycles();
        self::assertLessThan(2 * 1024 * 1024, memory_get_usage() - $baseline, 'memory stays flat');
        self::assertSame([], $bundle->getUnitOfWork()->getIdentityMap()[ContactSubmission::class] ?? [], 'no submission kept in the identity map');
        self::assertSame(502, (int) $bundle->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_contact_submission'));
    }

    /**
     * @return array{EntityManagerInterface, EntityManagerInterface}
     */
    private function managersWithSchema(): array
    {
        $host   = $this->registry()->getManager('default');
        $bundle = $this->registry()->getManager('contact_form');
        self::assertInstanceOf(EntityManagerInterface::class, $host);
        self::assertInstanceOf(EntityManagerInterface::class, $bundle);
        (new SchemaTool($host))->createSchema($host->getMetadataFactory()->getAllMetadata());
        (new SchemaTool($bundle))->createSchema($bundle->getMetadataFactory()->getAllMetadata());

        $form = (new ContactForm())
            ->setName('Support')
            ->setSlug('support')
            ->setEnabled(true)
            ->addTranslation((new ContactFormTranslation())->setLocale('en')->setTitle('Contact us'));
        $form->addField((new ContactFormField())
            ->setName('email')
            ->setType(ContactFieldType::Email)
            ->setForm($form)
            ->addTranslation((new ContactFormFieldTranslation())->setLocale('en')->setLabel('Email')));
        $bundle->persist($form);
        $bundle->flush();

        return [$host, $bundle];
    }

    /**
     * @param array<string, mixed> $doctrine
     * @param array<string, mixed> $contactForm
     */
    private function boot(array $doctrine, array $contactForm = [], bool $hostDefaultDriver = false): void
    {
        $this->kernel = new EntityManagerTestKernel($doctrine, $contactForm, $hostDefaultDriver);
        $this->kernel->boot();
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

    private function registry(): ManagerRegistry
    {
        $registry = $this->container()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return $registry;
    }

    /**
     * @return array<string, mixed>
     */
    private function dedicatedDoctrine(string $hostManager = 'default', bool $withDefaultName = true): array
    {
        $orm = [
            'entity_managers' => [
                $hostManager => [
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
        ];
        if ($withDefaultName) {
            $orm = ['default_entity_manager' => $hostManager] + $orm;
        }

        return [
            'dbal' => [
                'default_connection' => 'default',
                'connections'        => [
                    'default'      => ['url' => 'sqlite:///' . $this->database('host')],
                    'contact_form' => ['url' => 'sqlite:///' . $this->database('bundle')],
                ],
            ],
            'orm' => $orm,
        ];
    }

    private function database(string $name): string
    {
        $file = sprintf('%s/nowo_contact_form_em_%s_%d.sqlite', sys_get_temp_dir(), $name, (int) getmypid());
        if (is_file($file)) {
            unlink($file);
        }
        $this->files[] = $file;

        return $file;
    }
}
