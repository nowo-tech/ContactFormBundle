<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\DependencyInjection;

use Doctrine\ORM\Events;
use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormOptionalServicesPass;
use Nowo\ContactFormBundle\Notification\ContactSubmissionNotifierInterface;
use Nowo\ContactFormBundle\Notification\MailerContactSubmissionNotifier;
use Nowo\ContactFormBundle\Notification\NullContactSubmissionNotifier;
use Nowo\ContactFormBundle\Security\ConfigurableContactFormAccessChecker;
use Nowo\ContactFormBundle\Security\ContactFormAccessCheckerInterface;
use Nowo\ContactFormBundle\Service\ClientLabelResolver;
use Nowo\ContactFormBundle\Service\ClientResolverInterface;
use Nowo\ContactFormBundle\Service\ContactFormEntityManagerResolver;
use Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface;
use Nowo\ContactFormBundle\Service\ContactFormSubmissionRateLimiter;
use Nowo\ContactFormBundle\Service\ContactSubmissionProcessor;
use Nowo\ContactFormBundle\Service\IpAnonymizer;
use Nowo\ContactFormBundle\Service\NullContactFormFileUploadHandler;
use Nowo\ContactFormBundle\Service\SecurityClientResolver;
use Nowo\ContactFormBundle\Twig\ContactFormAdminTwigExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Mailer\MailerInterface;

use function array_key_exists;
use function is_array;
use function is_string;

/**
 * Loads bundle configuration and service definitions.
 */
final class NowoContactFormExtension extends Extension implements PrependExtensionInterface
{
    public const DEFAULT_ACCESS_CHECKER_ID = 'nowo_contact_form.access_checker.default';

    public const ALLOW_ALL_ACCESS_CHECKER_ID = 'nowo_contact_form.access_checker.allow_all';

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

        $container->getDefinition(ContactSubmissionProcessor::class)
            ->setArgument('$clock', new Reference('clock'))
            ->setArgument('$defaultNotificationRecipient', $config['notifications']['default_recipient'])
            ->setArgument('$entityManagerResolver', new Reference(ContactFormEntityManagerResolver::class));

        if ($container->hasDefinition(ContactFormAdminTwigExtension::class)) {
            $container->getDefinition(ContactFormAdminTwigExtension::class)
                ->setArgument('$layoutTemplate', $webUi['layout_template'])
                ->setArgument('$cssFramework', $webUi['css_framework'])
                ->setArgument('$iconSet', $webUi['icon_set']);
        }

        $this->registerAccessChecker($container, $security);
        $this->registerNotifier($container, $config);
        $this->registerFileUploadHandler($container, $config);
        $this->registerSubmissionRateLimiter($container, $config);
        $this->registerTablePrefixListener($container, $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerTablePrefixListener(ContainerBuilder $container, array $config): void
    {
        $tablePrefix = trim((string) ($config['doctrine']['table_prefix'] ?? ''));
        $container->setParameter('nowo_contact_form.doctrine.table_prefix', $tablePrefix);

        if ($tablePrefix === '') {
            return;
        }

        $definition = new Definition(TablePrefixListener::class, [$tablePrefix]);
        $definition->addTag('doctrine.event_listener', ['event' => Events::loadClassMetadata]);
        $container->setDefinition(TablePrefixListener::class, $definition);
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
     * @param array<string, mixed> $config
     */
    private function registerFileUploadHandler(ContainerBuilder $container, array $config): void
    {
        $service = $config['file_upload']['service'] ?? null;

        if ($service !== null) {
            $container->setAlias(ContactFormFileUploadHandlerInterface::class, (string) $service);

            return;
        }

        $container->setAlias(ContactFormFileUploadHandlerInterface::class, NullContactFormFileUploadHandler::class);
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
