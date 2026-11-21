<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\DependencyInjection;

use Doctrine\ORM\Events;
use Nowo\ContactFormBundle\Client\ClientReferenceResolverInterface;
use Nowo\ContactFormBundle\Client\ObjectClientReferenceResolver;
use Nowo\ContactFormBundle\Collation\ContactFormExactKeyColumns;
use Nowo\ContactFormBundle\Controller\ContactFormPublicController;
use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormCommitTrackingPass;
use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormFileUploaderPass;
use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormOptionalServicesPass;
use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormPrivateEntityManagerPass;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\EventSubscriber\ContactFormConflictListener;
use Nowo\ContactFormBundle\EventSubscriber\ContactFormEntityManagerClearSubscriber;
use Nowo\ContactFormBundle\Notification\ContactSubmissionNotifierInterface;
use Nowo\ContactFormBundle\Notification\MailerContactSubmissionNotifier;
use Nowo\ContactFormBundle\Notification\NullContactSubmissionNotifier;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Nowo\ContactFormBundle\Security\ConfigurableContactFormAccessChecker;
use Nowo\ContactFormBundle\Security\ContactFormAccessCheckerInterface;
use Nowo\ContactFormBundle\Service\ClientLabelResolver;
use Nowo\ContactFormBundle\Service\ClientResolverInterface;
use Nowo\ContactFormBundle\Service\ContactFormEntityManagerResolver;
use Nowo\ContactFormBundle\Service\ContactFormSubmissionRateLimiter;
use Nowo\ContactFormBundle\Service\ContactSubmissionProcessor;
use Nowo\ContactFormBundle\Service\IpAnonymizer;
use Nowo\ContactFormBundle\Service\SecurityClientResolver;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManagerMiddleware;
use Nowo\TransactionDoctrine\PrivateManager\PrivateManagerRegistry;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingMiddleware;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\MailerInterface;

use function array_key_exists;
use function is_array;
use function is_string;
use function str_starts_with;

/**
 * Loads bundle configuration and service definitions.
 */
final class NowoContactFormExtension extends Extension implements PrependExtensionInterface
{
    /** Container parameter holding `doctrine.entity_manager` (null: the default entity manager), 1.7. */
    public const ENTITY_MANAGER_PARAMETER = 'nowo_contact_form.doctrine.entity_manager';

    /**
     * Container parameter: true when the bundle writes through its private entity manager (2.0: always, unless
     * `doctrine.entity_manager` names a dedicated manager without `private_entity_manager: true`). Set by
     * {@see ContactFormPrivateEntityManagerPass}.
     */
    public const PRIVATE_ENTITY_MANAGER_PARAMETER = 'nowo_contact_form.doctrine.private_entity_manager';

    /**
     * Container parameter holding the deprecated `doctrine.private_entity_manager` as set: null (unset) or true (false
     * is refused by the configuration since 2.0). Read by {@see ContactFormPrivateEntityManagerPass}.
     */
    public const PRIVATE_ENTITY_MANAGER_SETTING_PARAMETER = 'nowo_contact_form.doctrine.private_entity_manager_setting';

    /**
     * Container parameter holding the name of the application's manager the private one is created on (1.13):
     * `doctrine.entity_manager`, else the default entity manager (resolved by {@see ContactFormPrivateEntityManagerPass}).
     */
    public const PRIVATE_SOURCE_MANAGER_PARAMETER = 'nowo_contact_form.private_entity_manager.source_manager';

    /** The bundle's private entity manager (1.13 opt-in; 2.0: the default). */
    public const PRIVATE_ENTITY_MANAGER_SERVICE = 'nowo_contact_form.private_entity_manager';

    /** The registry the bundle's repositories get in private mode (bundle entities → the private manager). */
    public const PRIVATE_REGISTRY_SERVICE = 'nowo_contact_form.private_manager_registry';

    /** Tells the private manager how the connection's transactions end (tagged by {@see ContactFormPrivateEntityManagerPass}). */
    public const PRIVATE_MIDDLEWARE_SERVICE = 'nowo_contact_form.private_entity_manager_middleware';

    /** The bundle named in the private manager's log lines. */
    public const BUNDLE_NAME = 'NowoContactFormBundle';

    /** The bundle's entity namespace: the only classes the private manager holds. */
    public const OWN_NAMESPACES = ['Nowo\\ContactFormBundle\\Entity'];

    /** Container parameter holding `doctrine.mapping_type` (`attribute` or `xml`), 1.9. */
    public const MAPPING_TYPE_PARAMETER = 'nowo_contact_form.doctrine.mapping_type';

    /** Directory of the XML mapping of the six bundle entities (1.9, same tables and columns as the attributes). */
    public const XML_MAPPING_DIR = __DIR__ . '/../Resources/config/doctrine-xml';

    public const DEFAULT_ACCESS_CHECKER_ID = 'nowo_contact_form.access_checker.default';

    public const ALLOW_ALL_ACCESS_CHECKER_ID = 'nowo_contact_form.access_checker.allow_all';

    /** 1.11: real commits counted by {@see self::COMMIT_TRACKING_MIDDLEWARE}, read by the conflict answer. */
    public const COMMIT_LEDGER_SERVICE = 'nowo_contact_form.commit_ledger';

    /** 1.11: transaction-doctrine's commit-tracking DBAL middleware (scoped by {@see ContactFormCommitTrackingPass}). */
    public const COMMIT_TRACKING_MIDDLEWARE = 'nowo_contact_form.commit_tracking_middleware';

    /** 1.11: container parameter holding `conflict_response.commit_tracking` (bundle, all or off). */
    public const COMMIT_TRACKING_PARAMETER = 'nowo_contact_form.conflict_response.commit_tracking';

    /**
     * Seeds the FormKit `contact_form` profile (REQ-UI-001-kit).
     */
    public function prepend(ContainerBuilder $container): void
    {
        $this->prependFormKitDefaults($container);
    }

    /**
     * When FormKit is installed, register the {@code contact_form} profile. Forms select it via {@code #[FormKitConfig]}.
     */
    private function prependFormKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_form_kit')) {
            return;
        }

        $hostHasCssFramework = false;
        $hostHasProfile      = false;
        foreach ($container->getExtensionConfig('nowo_form_kit') as $cfg) {
            /** @var array<string, mixed> $cfg */
            if (array_key_exists('css_framework', $cfg)) {
                $hostHasCssFramework = true;
            }
            $profiles = $cfg['profiles'] ?? null;
            if (is_array($profiles) && array_key_exists('contact_form', $profiles)) {
                $hostHasProfile = true;
            }
        }

        $seed = [];

        if (!$hostHasCssFramework) {
            $seed['css_framework'] = 'bootstrap';
        }

        if (!$hostHasProfile) {
            // REQ-UI-003: no framework utility classes in bundle markup; the host form theme (or this profile, which
            // the host may redefine) styles the fields. `nowo-ui-input` is the UiKit semantic hook.
            $seed['profiles'] = [
                'contact_form' => [
                    'alias'              => 'contact_form',
                    'translation_domain' => 'NowoContactFormBundle',
                    // Placeholder / help convention keys only where the catalogue has them (no raw keys).
                    'convention_requires_translation' => true,
                    'defaults'                        => [
                        'attr'     => ['class' => 'nowo-ui-input'],
                        'row_attr' => [],
                    ],
                ],
            ];
        }

        if ($seed !== []) {
            $container->prependExtensionConfig('nowo_form_kit', $seed);
        }
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        // The `clock` fallback and ClockInterface alias are registered by ContactFormOptionalServicesPass:
        // load() runs on an isolated container, so FrameworkBundle's `clock` is not visible here.

        $container->setParameter('nowo_contact_form.client_entity_class', $config['client_entity_class']);
        $container->setParameter('nowo_contact_form.client_label_property', $config['client_label_property']);
        $container->setParameter('nowo_contact_form.client_user_accessor', $config['client_user_accessor']);
        $container->setParameter('nowo_contact_form.default_retention_days', $config['default_retention_days']);
        $container->setParameter('nowo_contact_form.phone_prefixes', $config['phone_prefixes']);
        $container->setParameter('nowo_contact_form.phone_input', $config['phone_input']);
        $container->setParameter('nowo_contact_form.file_upload.max_size', $config['file_upload']['max_size'] === null ? null : (string) $config['file_upload']['max_size']);
        $container->setParameter('nowo_contact_form.file_upload.mime_types', array_values($config['file_upload']['mime_types']));
        $container->setParameter('nowo_contact_form.public_field_max_length.text', $config['public_field_max_length']['text']);
        $container->setParameter('nowo_contact_form.public_field_max_length.textarea', $config['public_field_max_length']['textarea']);
        $webUi      = $config['web_ui'];
        $pathPrefix = $webUi['path_prefix'] ?? Configuration::DEFAULT_PATH_PREFIX;
        $container->setParameter('nowo_contact_form.web_ui.path_prefix', $pathPrefix);
        // Same value under its 1.x name (host access_control / route imports may read it).
        $container->setParameter('nowo_contact_form.admin_route_prefix', $pathPrefix);
        $container->setParameter('nowo_contact_form.notifications.default_recipient', $config['notifications']['default_recipient']);

        $container->setParameter('nowo_contact_form.web_ui.enabled', $webUi['enabled']);
        $container->setParameter('nowo_contact_form.web_ui.layout_template', $webUi['layout_template']);
        $container->setParameter('nowo_contact_form.web_ui.public_layout_template', $webUi['public_layout_template']);
        $container->setParameter('nowo_contact_form.web_ui.templates', $webUi['templates']);
        $container->setParameter('nowo_contact_form.web_ui.list_page_size', $webUi['list_page_size']);

        $security = $config['security'];
        $container->setParameter('nowo_contact_form.security.access_roles', $security['access_roles']);
        $container->setParameter('nowo_contact_form.security.allow_unauthenticated', $security['allow_unauthenticated']);

        $container->register(IpAnonymizer::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setArgument('$salt', $config['ip_anonymization_salt']);

        $container->register(ClientLabelResolver::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setArguments([
                '$clientEntityClass'   => $config['client_entity_class'],
                '$clientLabelProperty' => $config['client_label_property'],
            ]);

        $container->register(SecurityClientResolver::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setArguments([
                '$clientEntityClass'  => $config['client_entity_class'],
                '$clientUserAccessor' => $config['client_user_accessor'],
                '$tokenStorage'       => new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]);

        $container->setAlias(ClientResolverInterface::class, SecurityClientResolver::class);

        // 1.7: id-reference client port; the public form uses a configured resolver, else the client entity (1.x path).
        $referenceResolver = $config['client_reference_resolver'] ?? null;
        $referenceResolver = is_string($referenceResolver) && $referenceResolver !== '' ? $referenceResolver : null;
        $container->setAlias(ClientReferenceResolverInterface::class, $referenceResolver ?? ObjectClientReferenceResolver::class);
        if ($container->hasDefinition(ContactFormPublicController::class)) {
            $container->getDefinition(ContactFormPublicController::class)
                ->setArgument('$clientReferenceResolver', $referenceResolver !== null ? new Reference($referenceResolver) : null);
        }

        $entityManagerName = $config['doctrine']['entity_manager'] ?? null;
        $entityManagerName = is_string($entityManagerName) && $entityManagerName !== '' ? $entityManagerName : null;
        $container->setParameter(self::ENTITY_MANAGER_PARAMETER, $entityManagerName);
        $container->setParameter(self::MAPPING_TYPE_PARAMETER, ($config['doctrine']['mapping_type'] ?? 'attribute') === 'xml' ? 'xml' : 'attribute');
        $this->registerPrivateEntityManager($container, ($config['doctrine']['private_entity_manager'] ?? null) === true, $entityManagerName);

        $container->getDefinition(ContactSubmissionProcessor::class)
            ->setArgument('$clock', new Reference('clock'))
            ->setArgument('$defaultNotificationRecipient', $config['notifications']['default_recipient'])
            ->setArgument('$entityManagerResolver', new Reference(ContactFormEntityManagerResolver::class))
            ->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$formRepository', new Reference(ContactFormRepository::class));

        $this->registerConflictAnswer($container, $config['conflict_response']);
        $this->registerAccessChecker($container, $security);
        $this->registerNotifier($container, $config);
        $this->registerFileUploadHandler($container, $config);
        $this->registerSubmissionRateLimiter($container, $config);
        $this->registerTablePrefixListener($container, $config);
    }

    /**
     * 1.11 (REQ-RUNTIME-003 rule 6): 409 (or 503) + CONFLICT_001 / CONFLICT_002 instead of a 500 for lock conflicts on
     * the bundle's routes. Unset options follow transaction-doctrine's policy service when its bundle is registered.
     *
     * Commit tracking (`conflict_response.commit_tracking`): `bundle` (default) and `all` register transaction-doctrine's
     * commit-tracking middleware; {@see ContactFormCommitTrackingPass} scopes it to the bundle's connection (`bundle`), or
     * drops it and reuses transaction-doctrine's own ledger when that bundle's `commit_detection` is on. `off` registers
     * neither the ledger nor the middleware.
     *
     * @param array{status: int|null, retry_after: int|null, commit_tracking: string} $conflictResponse
     */
    private function registerConflictAnswer(ContainerBuilder $container, array $conflictResponse): void
    {
        $container->setParameter(self::COMMIT_TRACKING_PARAMETER, $conflictResponse['commit_tracking']);
        $tracking = $conflictResponse['commit_tracking'] !== 'off';
        if ($tracking) {
            $container->setDefinition(self::COMMIT_LEDGER_SERVICE, new Definition(CommitLedger::class));
            $container->setDefinition(self::COMMIT_TRACKING_MIDDLEWARE, (new Definition(CommitTrackingMiddleware::class, [new Reference(self::COMMIT_LEDGER_SERVICE)]))
                ->addTag('doctrine.middleware'));
        }

        $policy = $conflictResponse['status'] === null && $conflictResponse['retry_after'] === null
            ? new Reference(ConflictResponsePolicy::class, ContainerInterface::NULL_ON_INVALID_REFERENCE)
            : new Definition(ConflictResponsePolicy::class, [
                $conflictResponse['status'] ?? ConflictResponsePolicy::STATUS_CONFLICT,
                $conflictResponse['retry_after'] ?? ConflictResponsePolicy::DEFAULT_RETRY_AFTER,
            ]);

        $container->setDefinition(ContactFormConflictListener::class, (new Definition(ContactFormConflictListener::class, [
            $policy,
            $tracking ? new Reference(self::COMMIT_LEDGER_SERVICE) : null,
            new Reference('request_stack', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))
            ->addTag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'method' => 'onRequest', 'priority' => ContactFormConflictListener::REQUEST_PRIORITY])
            ->addTag('kernel.event_listener', ['event' => KernelEvents::EXCEPTION, 'method' => 'onException', 'priority' => ContactFormConflictListener::PRIORITY]));
    }

    /**
     * REQ-ARCH-001 rule 6 (2.0): the bundle's repositories, its entity manager service (`nowo_contact_form.entity_manager`,
     * which the controllers and services get) and its resolver work through transaction-doctrine's
     * {@see PrivateEntityManager}, created on the connection, configuration and event manager of
     * `doctrine.entity_manager`, else the default manager. Same tables, no host configuration. It is dropped at every
     * outermost unit-of-work boundary ({@see ContactFormEntityManagerClearSubscriber}; also by transaction-doctrine's
     * bundle through the {@see PrivateEntityManager::TAG} tag, and at `kernel.reset`) and replaced when closed.
     *
     * Registered here for every setup; {@see ContactFormPrivateEntityManagerPass} removes it again when
     * `doctrine.entity_manager` names a dedicated manager (the DDD option, which the bundle uses directly) and
     * `private_entity_manager` is not true: only a compiler pass knows the name of the default manager.
     */
    private function registerPrivateEntityManager(ContainerBuilder $container, bool $privateSetting, ?string $entityManagerName): void
    {
        $container->setParameter(self::PRIVATE_ENTITY_MANAGER_PARAMETER, true);
        $container->setParameter(self::PRIVATE_ENTITY_MANAGER_SETTING_PARAMETER, $privateSetting ? true : null);
        $container->setParameter(self::PRIVATE_SOURCE_MANAGER_PARAMETER, $entityManagerName ?? '');

        $source = '%' . self::PRIVATE_SOURCE_MANAGER_PARAMETER . '%';
        $container->register(self::PRIVATE_ENTITY_MANAGER_SERVICE, PrivateEntityManager::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setArguments([
                new Reference('doctrine'),
                $source,
                ContactForm::class,
                self::BUNDLE_NAME,
                new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ])
            ->addTag(PrivateEntityManager::TAG)
            ->addTag('kernel.reset', ['method' => 'reset']);
        $container->register(self::PRIVATE_REGISTRY_SERVICE, PrivateManagerRegistry::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setArguments([
                new Reference('doctrine'),
                new Reference(self::PRIVATE_ENTITY_MANAGER_SERVICE),
                self::OWN_NAMESPACES,
                $source,
            ]);
        // Tagged doctrine.middleware for the source manager's connection by ContactFormPrivateEntityManagerPass.
        $container->register(self::PRIVATE_MIDDLEWARE_SERVICE, PrivateEntityManagerMiddleware::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setArguments([new Reference(self::PRIVATE_ENTITY_MANAGER_SERVICE), 'nowo_contact_form_']);

        // The repositories (service repositories, also served by the application's manager's getRepository()) and
        // the resolver behind nowo_contact_form.entity_manager resolve the bundle entities to the private manager.
        $registry = new Reference(self::PRIVATE_REGISTRY_SERVICE);
        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass() ?? $id;
            if (str_starts_with($class, 'Nowo\\ContactFormBundle\\Repository\\') && !$definition->isAbstract()) {
                $definition->setArgument('$registry', $registry);
            }
        }
        $container->getDefinition(ContactFormEntityManagerResolver::class)->setArgument('$registry', $registry);

        $container->getDefinition(ContactFormEntityManagerClearSubscriber::class)->setArgument('$resettables', new IteratorArgument([
            new Reference(self::PRIVATE_ENTITY_MANAGER_SERVICE, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE),
        ]));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerTablePrefixListener(ContainerBuilder $container, array $config): void
    {
        $tablePrefix = trim((string) ($config['doctrine']['table_prefix'] ?? ''));
        $container->setParameter('nowo_contact_form.doctrine.table_prefix', $tablePrefix);
        $this->registerExactKeyColumns($container, $tablePrefix);

        if ($tablePrefix === '') {
            return;
        }

        $definition = new Definition(TablePrefixListener::class, [$tablePrefix]);
        $definition->addTag('doctrine.event_listener', ['event' => Events::loadClassMetadata]);
        $container->setDefinition(TablePrefixListener::class, $definition);
    }

    /**
     * REQ-SEC-006: declare client_reference as an identity key, so a host's nowo:migrations:check-key-collations
     * asserts its binary NO PAD collation against the real schema. The contract comes from
     * nowo-tech/migrations-kit-contracts (interfaces and value objects, no dependencies beyond PHP); the check itself
     * lives in MigrationsKitBundle, which only the host needs. A host without it simply never reads the tag. The
     * table prefix is the configured one, so the declaration names the table the host really has.
     */
    private function registerExactKeyColumns(ContainerBuilder $container, string $tablePrefix): void
    {
        $definition = new Definition(ContactFormExactKeyColumns::class, [$tablePrefix]);
        $definition->addTag('nowo_migrations_kit.exact_key_columns');
        $container->setDefinition(ContactFormExactKeyColumns::class, $definition);
    }

    /**
     * @param array{access_checker: ?string, access_roles: list<string>, allow_unauthenticated: bool} $security
     */
    private function registerAccessChecker(ContainerBuilder $container, array $security): void
    {
        $accessCheckerId = $security['access_checker'] ?? null;
        if (is_string($accessCheckerId) && $accessCheckerId !== '') {
            $container->setAlias(ContactFormAccessCheckerInterface::class, $accessCheckerId);

            return;
        }

        // load() runs on an isolated container: SecurityBundle services are never visible here, so the
        // authorization checker is an optional reference (null = deny, fail-closed). ContactFormSecurityPass
        // swaps in the allow-all checker when allow_unauthenticated is true and SecurityBundle is absent.
        $definition = new Definition(ConfigurableContactFormAccessChecker::class);
        $definition->setArgument('$authorizationChecker', new Reference('security.authorization_checker', ContainerInterface::NULL_ON_INVALID_REFERENCE));
        $definition->setArgument('$accessRoles', $security['access_roles']);
        $container->setDefinition(self::DEFAULT_ACCESS_CHECKER_ID, $definition);
        $container->setAlias(ContactFormAccessCheckerInterface::class, self::DEFAULT_ACCESS_CHECKER_ID);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerSubmissionRateLimiter(ContainerBuilder $container, array $config): void
    {
        $rateLimit = $config['public_submission_rate_limit'];
        $limit     = $rateLimit['enabled'] ? (int) $rateLimit['limit'] : 0;
        $interval  = $rateLimit['enabled'] ? (int) $rateLimit['interval_seconds'] : 0;
        $limiter   = $rateLimit['enabled'] && is_string($rateLimit['limiter']) && $rateLimit['limiter'] !== ''
            ? new Reference($rateLimit['limiter'])
            : null;

        $container->register(ContactFormSubmissionRateLimiter::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setArguments([
                new Reference('cache.app', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                $limit,
                $interval,
                new Reference('clock'),
                $limiter,
            ]);
    }

    /**
     * `file_upload.service` is wired by {@see ContactFormFileUploaderPass} (1.8): only there are the host's service
     * definitions, and so its class, known. The default aliases of both file ports are in services.yaml.
     *
     * @param array<string, mixed> $config
     */
    private function registerFileUploadHandler(ContainerBuilder $container, array $config): void
    {
        $service = $config['file_upload']['service'] ?? null;

        $container->setParameter(ContactFormFileUploaderPass::SERVICE_PARAMETER, $service === null ? null : (string) $service);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerNotifier(ContainerBuilder $container, array $config): void
    {
        $notifications = $config['notifications'];

        if (!$notifications['enabled']) {
            $container->setAlias(ContactSubmissionNotifierInterface::class, NullContactSubmissionNotifier::class);

            return;
        }

        if ($notifications['service'] !== null) {
            $container->setAlias(ContactSubmissionNotifierInterface::class, (string) $notifications['service']);

            return;
        }

        if ($notifications['mailer']['enabled'] && interface_exists(MailerInterface::class)) {
            // The mailer service belongs to FrameworkBundle and is invisible in load(); the compiler pass
            // injects it (or drops this candidate and falls back to the null notifier).
            $container->register(MailerContactSubmissionNotifier::class)
                ->setAutowired(false)
                ->setAutoconfigured(false)
                ->setArguments([
                    null,
                    '$from'            => $notifications['mailer']['from'],
                    '$subjectTemplate' => $notifications['mailer']['subject'],
                ])
                ->addTag('nowo_contact_form.notifier')
                ->addTag(ContactFormOptionalServicesPass::MAILER_NOTIFIER_TAG);
        }

        $container->setAlias(ContactSubmissionNotifierInterface::class, NullContactSubmissionNotifier::class);
    }

    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }
}
