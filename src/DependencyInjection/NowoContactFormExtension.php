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
use Nowo\ContactFormBundle\Doctrine\ContactFormHostFlushReporter;
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
use Nowo\ContactFormBundle\Twig\ContactFormAdminTwigExtension;
use Nowo\TransactionDoctrine\HostFlushProbe;
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

    /** Container parameter holding `doctrine.private_entity_manager === true` (1.13). */
    public const PRIVATE_ENTITY_MANAGER_PARAMETER = 'nowo_contact_form.doctrine.private_entity_manager';

    /**
     * Container parameter holding `doctrine.private_entity_manager` as set (1.13): null (unset), true or false. Read by
     * {@see ContactFormPrivateEntityManagerPass}, which decides host mode once the application's managers are known.
     */
    public const PRIVATE_ENTITY_MANAGER_SETTING_PARAMETER = 'nowo_contact_form.doctrine.private_entity_manager_setting';

    /**
     * Container parameter holding the name of the application's manager the private one is created on (1.13):
     * `doctrine.entity_manager`, else the default entity manager (resolved by {@see ContactFormPrivateEntityManagerPass}).
     */
    public const PRIVATE_SOURCE_MANAGER_PARAMETER = 'nowo_contact_form.private_entity_manager.source_manager';

    /** 1.13: the bundle's private entity manager (`doctrine.private_entity_manager: true`). */
    public const PRIVATE_ENTITY_MANAGER_SERVICE = 'nowo_contact_form.private_entity_manager';

    /** 1.13: the registry the bundle's repositories get in private mode (bundle entities → the private manager). */
    public const PRIVATE_REGISTRY_SERVICE = 'nowo_contact_form.private_manager_registry';

    /** 1.13: tells the private manager how the connection's transactions end (tagged by {@see ContactFormPrivateEntityManagerPass}). */
    public const PRIVATE_MIDDLEWARE_SERVICE = 'nowo_contact_form.private_entity_manager_middleware';

    /** 1.13: the notice of a host that sets neither `doctrine.private_entity_manager` nor `doctrine.entity_manager`. */
    public const PRIVATE_ENTITY_MANAGER_UNSET_DEPRECATION = 'Not setting "nowo_contact_form.doctrine.private_entity_manager" is deprecated: contact form writes flush the application\'s entity manager, including its own pending changes. 2.0 defaults to true: a private entity manager on the same connection and tables that never flushes them. Opt in now with "nowo_contact_form: { doctrine: { private_entity_manager: true } }", or set doctrine.entity_manager to a dedicated manager (see UPGRADING.md "To 1.13.0").';

    /** 1.13: the notice of a host that sets `doctrine.private_entity_manager: false` without `doctrine.entity_manager`. */
    public const PRIVATE_ENTITY_MANAGER_FALSE_DEPRECATION = 'Setting "nowo_contact_form.doctrine.private_entity_manager" to false is deprecated: contact form writes flush the application\'s entity manager, including its own pending changes. 2.0 removes the option; the private entity manager (same connection and tables) becomes the only mode unless you set doctrine.entity_manager. Opt in now with "nowo_contact_form: { doctrine: { private_entity_manager: true } }" (see UPGRADING.md "To 1.13.0").';

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
     * Seeds UiKit defaults from web_ui when the host has not set nowo_ui_kit (REQ-UI-001-kit).
     */
    public function prepend(ContainerBuilder $container): void
    {
        $this->prependFormKitDefaults($container);
        $this->prependUiKitDefaults($container);
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

    /**
     * When UiKit is installed and the host set the deprecated web_ui.css_framework / icon_set (REQ-UI-003: the CSS
     * stack is inherited from nowo_ui_kit), copy them to nowo_ui_kit unless the host set those keys there.
     */
    private function prependUiKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_ui_kit')) {
            return;
        }

        $hostHasCssFramework = false;
        $hostHasIconSet      = false;
        foreach ($container->getExtensionConfig('nowo_ui_kit') as $cfg) {
            /** @var array<string, mixed> $cfg */
            if (array_key_exists('css_framework', $cfg)) {
                $hostHasCssFramework = true;
            }
            if (array_key_exists('icon_set', $cfg)) {
                $hostHasIconSet = true;
            }
        }

        if ($hostHasCssFramework && $hostHasIconSet) {
            return;
        }

        $config = $this->processConfiguration(new Configuration(), $container->getExtensionConfig(Configuration::ALIAS));
        /** @var array<string, mixed> $webUi */
        $webUi    = is_array($config['web_ui'] ?? null) ? $config['web_ui'] : [];
        $defaults = [];

        $framework = $webUi['css_framework'] ?? null;
        if (!$hostHasCssFramework && is_string($framework)) {
            $defaults['css_framework'] = $framework === 'bootstrap' ? 'bootstrap5' : $framework;
        }
        $iconSet = $webUi['icon_set'] ?? null;
        if (!$hostHasIconSet && is_string($iconSet)) {
            $defaults['icon_set'] = $iconSet;
        }

        if ($defaults !== []) {
            $container->prependExtensionConfig('nowo_ui_kit', $defaults);
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
        $pathPrefix = $webUi['path_prefix'] ?? $config['admin_route_prefix'] ?? Configuration::DEFAULT_PATH_PREFIX;
        $container->setParameter('nowo_contact_form.web_ui.path_prefix', $pathPrefix);
        // Deprecated alias of web_ui.path_prefix (kept for host access_control / route imports until 2.0).
        $container->setParameter('nowo_contact_form.admin_route_prefix', $pathPrefix);
        $container->setParameter('nowo_contact_form.notifications.default_recipient', $config['notifications']['default_recipient']);

        $container->setParameter('nowo_contact_form.web_ui.enabled', $webUi['enabled']);
        $container->setParameter('nowo_contact_form.web_ui.layout_template', $webUi['layout_template']);
        $container->setParameter('nowo_contact_form.web_ui.public_layout_template', $webUi['public_layout_template']);
        $container->setParameter('nowo_contact_form.web_ui.templates', $webUi['templates']);
        $container->setParameter('nowo_contact_form.web_ui.css_framework', $webUi['css_framework']);
        $container->setParameter('nowo_contact_form.web_ui.icon_set', $webUi['icon_set']);
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
        $privateEntityManager = $config['doctrine']['private_entity_manager'] ?? null;
        $this->registerPrivateEntityManager($container, $privateEntityManager === null ? null : (bool) $privateEntityManager, $entityManagerName);

        $container->getDefinition(ContactSubmissionProcessor::class)
            ->setArgument('$clock', new Reference('clock'))
            ->setArgument('$defaultNotificationRecipient', $config['notifications']['default_recipient'])
            ->setArgument('$entityManagerResolver', new Reference(ContactFormEntityManagerResolver::class))
            ->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$formRepository', new Reference(ContactFormRepository::class));

        if ($container->hasDefinition(ContactFormAdminTwigExtension::class)) {
            $container->getDefinition(ContactFormAdminTwigExtension::class)
                ->setArgument('$layoutTemplate', $webUi['layout_template'])
                ->setArgument('$cssFramework', $webUi['css_framework'])
                ->setArgument('$iconSet', $webUi['icon_set']);
        }

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
     * 1.13 (REQ-ARCH-001 rule 6): `doctrine.private_entity_manager`.
     *
     * - true: the bundle's repositories, its entity manager service (`nowo_contact_form.entity_manager`, which the
     *   controllers and services get) and its resolver work through transaction-doctrine's {@see PrivateEntityManager},
     *   created on the connection, configuration and event manager of `doctrine.entity_manager` (else the default
     *   manager). Same tables, no host configuration. It is dropped at every outermost unit-of-work boundary
     *   ({@see ContactFormEntityManagerClearSubscriber}; also by transaction-doctrine's bundle through the
     *   {@see PrivateEntityManager::TAG} tag, and at `kernel.reset`) and replaced when closed.
     * - false or null: the bundle flushes the manager that maps its entities (1.x). Without `doctrine.entity_manager`, or
     *   with it naming the application's default manager, that is the application's manager (host mode): a deprecation
     *   notice (one text for unset, one for false, which 2.0 removes) and {@see ContactFormHostFlushReporter} enabled,
     *   both by {@see ContactFormPrivateEntityManagerPass}.
     */
    private function registerPrivateEntityManager(ContainerBuilder $container, ?bool $private, ?string $entityManagerName): void
    {
        $container->setParameter(self::PRIVATE_ENTITY_MANAGER_PARAMETER, $private === true);
        $container->setParameter(self::PRIVATE_ENTITY_MANAGER_SETTING_PARAMETER, $private);
        $container->setParameter(self::PRIVATE_SOURCE_MANAGER_PARAMETER, $entityManagerName ?? '');

        // Host mode (the notice and the probe) is decided by ContactFormPrivateEntityManagerPass: doctrine.entity_manager
        // naming the application's default manager is host mode too, and only a compiler pass sees that name.
        $container->register(ContactFormHostFlushReporter::class, ContactFormHostFlushReporter::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setArguments([false, new Reference(HostFlushProbe::SERVICE_ID, ContainerInterface::NULL_ON_INVALID_REFERENCE)])
            ->addTag('kernel.reset', ['method' => 'reset']);

        $resettables = [new Reference(ContactFormHostFlushReporter::class, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE)];

        if ($private === true) {
            $source = '%' . self::PRIVATE_SOURCE_MANAGER_PARAMETER . '%';
            $container->register(self::PRIVATE_ENTITY_MANAGER_SERVICE, PrivateEntityManager::class)
                ->setAutowired(false)
                ->setAutoconfigured(false)
                ->setArguments([
                    new Reference('doctrine'),
                    $source,
                    ContactForm::class,
                    ContactFormHostFlushReporter::BUNDLE_NAME,
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
                    ContactFormHostFlushReporter::OWN_NAMESPACES,
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

            $resettables[] = new Reference(self::PRIVATE_ENTITY_MANAGER_SERVICE, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE);
        }

        $container->getDefinition(ContactFormEntityManagerClearSubscriber::class)->setArgument('$resettables', new IteratorArgument($resettables));
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
