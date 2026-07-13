<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\DependencyInjection;

use Nowo\ContactFormBundle\Notification\ContactSubmissionNotifierInterface;
use Nowo\ContactFormBundle\Notification\MailerContactSubmissionNotifier;
use Nowo\ContactFormBundle\Notification\NullContactSubmissionNotifier;
use Nowo\ContactFormBundle\Service\ClientLabelResolver;
use Nowo\ContactFormBundle\Service\ClientResolverInterface;
use Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface;
use Nowo\ContactFormBundle\Service\ContactFormSubmissionRateLimiter;
use Nowo\ContactFormBundle\Service\ContactSubmissionProcessor;
use Nowo\ContactFormBundle\Service\IpAnonymizer;
use Nowo\ContactFormBundle\Service\NullContactFormFileUploadHandler;
use Nowo\ContactFormBundle\Service\SecurityClientResolver;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

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

        $this->registerNotifier($container, $config);
        $this->registerFileUploadHandler($container, $config);
        $this->registerSubmissionRateLimiter($container, $config);
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

        if ($notifications['mailer']['enabled'] && interface_exists(\Symfony\Component\Mailer\MailerInterface::class)) {
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
