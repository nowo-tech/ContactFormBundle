<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\DependencyInjection;

use Nowo\ContactFormBundle\Notification\ContactSubmissionNotifierInterface;
use Nowo\ContactFormBundle\Notification\MailerContactSubmissionNotifier;
use Nowo\ContactFormBundle\Notification\NullContactSubmissionNotifier;
use Nowo\ContactFormBundle\Security\AllowAllContactFormAccessChecker;
use Nowo\ContactFormBundle\Security\ConfigurableContactFormAccessChecker;
use Nowo\ContactFormBundle\Security\ContactFormAccessCheckerInterface;
use Nowo\ContactFormBundle\Service\ClientLabelResolver;
use Nowo\ContactFormBundle\Service\ClientResolverInterface;
use Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface;
use Nowo\ContactFormBundle\Service\ContactFormSubmissionRateLimiter;
use Nowo\ContactFormBundle\Service\ContactSubmissionProcessor;
use Nowo\ContactFormBundle\Service\IpAnonymizer;
use Nowo\ContactFormBundle\Service\NullContactFormFileUploadHandler;
use Nowo\ContactFormBundle\Service\SecurityClientResolver;
use Nowo\ContactFormBundle\Twig\ContactFormAdminTwigExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Mailer\MailerInterface;

use function is_string;

/**
 * Loads bundle configuration and service definitions.
 */
final class NowoContactFormExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $container->setParameter('nowo_contact_form.client_entity_class', $config['client_entity_class']);
        $container->setParameter('nowo_contact_form.client_label_property', $config['client_label_property']);
        $container->setParameter('nowo_contact_form.client_user_accessor', $config['client_user_accessor']);
        $container->setParameter('nowo_contact_form.default_retention_days', $config['default_retention_days']);
        $container->setParameter('nowo_contact_form.phone_prefixes', $config['phone_prefixes']);
        $container->setParameter('nowo_contact_form.phone_input', $config['phone_input']);
        $container->setParameter('nowo_contact_form.admin_route_prefix', $config['admin_route_prefix']);
        $container->setParameter('nowo_contact_form.notifications.default_recipient', $config['notifications']['default_recipient']);

        $webUi = $config['web_ui'];
        $container->setParameter('nowo_contact_form.web_ui.enabled', $webUi['enabled']);
        $container->setParameter('nowo_contact_form.web_ui.layout_template', $webUi['layout_template']);
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
                '$tokenStorage'       => null,
            ]);

        if ($container->hasDefinition('security.token_storage')) {
            $container->getDefinition(SecurityClientResolver::class)
                ->setArgument('$tokenStorage', new Reference('security.token_storage'));
        }

        $container->setAlias(ClientResolverInterface::class, SecurityClientResolver::class);

        $container->getDefinition(ContactSubmissionProcessor::class)
            ->setArgument('$defaultNotificationRecipient', $config['notifications']['default_recipient']);

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

        $hasAuthorizationChecker = $container->hasDefinition('security.authorization_checker')
            || $container->hasAlias('security.authorization_checker');

        if ($security['allow_unauthenticated'] && !$hasAuthorizationChecker) {
            $accessCheckerId = 'nowo_contact_form.access_checker.allow_all';
            $container->setDefinition($accessCheckerId, new Definition(AllowAllContactFormAccessChecker::class));
            $container->setAlias(ContactFormAccessCheckerInterface::class, $accessCheckerId);

            return;
        }

        $accessCheckerId = 'nowo_contact_form.access_checker.default';
        $definition      = new Definition(ConfigurableContactFormAccessChecker::class);
        $definition->setArgument('$accessRoles', $security['access_roles']);
        if ($hasAuthorizationChecker) {
            $definition->setArgument('$authorizationChecker', new Reference('security.authorization_checker'));
        } else {
            // Placeholder until SecurityBundle registers the checker; SecurityPass fails compile if still missing.
            $definition->setAutowired(true);
        }
        $container->setDefinition($accessCheckerId, $definition);
        $container->setAlias(ContactFormAccessCheckerInterface::class, $accessCheckerId);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerSubmissionRateLimiter(ContainerBuilder $container, array $config): void
    {
        $rateLimit = $config['public_submission_rate_limit'];
        $limit     = $rateLimit['enabled'] ? (int) $rateLimit['limit'] : 0;
        $interval  = $rateLimit['enabled'] ? (int) $rateLimit['interval_seconds'] : 0;

        $container->register(ContactFormSubmissionRateLimiter::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setArguments([
                $container->has('cache.app') ? new Reference('cache.app') : null,
                $limit,
                $interval,
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

        $notifierIds = [];

        if ($notifications['mailer']['enabled'] && interface_exists(MailerInterface::class)) {
            $mailerId = $container->hasDefinition('mailer.mailer')
                ? 'mailer.mailer'
                : ($container->hasDefinition('mailer') ? 'mailer' : null);

            if ($mailerId !== null) {
                $container->register(MailerContactSubmissionNotifier::class)
                    ->setAutowired(false)
                    ->setAutoconfigured(false)
                    ->setArguments([
                        new Reference($mailerId),
                        '$from'            => $notifications['mailer']['from'],
                        '$subjectTemplate' => $notifications['mailer']['subject'],
                    ])
                    ->addTag('nowo_contact_form.notifier');

                $notifierIds[] = MailerContactSubmissionNotifier::class;
            }
        }

        if ($notifierIds === []) {
            $container->setAlias(ContactSubmissionNotifierInterface::class, NullContactSubmissionNotifier::class);

            return;
        }

        $container->setAlias(ContactSubmissionNotifierInterface::class, $notifierIds[0]);
    }

    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }
}
