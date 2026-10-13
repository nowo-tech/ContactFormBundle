<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\DependencyInjection\Compiler;

use Nowo\ContactFormBundle\Notification\ContactSubmissionNotifierInterface;
use Nowo\ContactFormBundle\Notification\MailerContactSubmissionNotifier;
use Nowo\ContactFormBundle\Notification\NullContactSubmissionNotifier;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Wires services that depend on other bundles (clock, mailer).
 *
 * Extension::load() runs on an isolated container (MergeExtensionConfigurationPass), so services
 * registered by FrameworkBundle are only visible here, on the merged container.
 */
final class ContactFormOptionalServicesPass implements CompilerPassInterface
{
    /** Tag placed by the extension on the mailer notifier candidate until a mailer service is found. */
    public const MAILER_NOTIFIER_TAG = 'nowo_contact_form.mailer_notifier_candidate';

    private const MAILER_SERVICE_IDS = ['mailer.mailer', 'mailer'];

    public function process(ContainerBuilder $container): void
    {
        $this->registerClockFallback($container);
        $this->wireMailerNotifier($container);
    }

    /**
     * Only define a {@code clock} when the host has none (FrameworkBundle's clock wins, e.g. MockClock in tests).
     */
    private function registerClockFallback(ContainerBuilder $container): void
    {
        if (!$container->has('clock')) {
            $container->register('clock', NativeClock::class);
        }
        if (!$container->has(ClockInterface::class)) {
            $container->setAlias(ClockInterface::class, 'clock');
        }
    }

    private function wireMailerNotifier(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(MailerContactSubmissionNotifier::class)) {
            return;
        }

        $definition = $container->getDefinition(MailerContactSubmissionNotifier::class);
        if (!$definition->hasTag(self::MAILER_NOTIFIER_TAG)) {
            return;
        }
        $definition->clearTag(self::MAILER_NOTIFIER_TAG);

        foreach (self::MAILER_SERVICE_IDS as $mailerId) {
            if ($container->has($mailerId)) {
                $definition->replaceArgument(0, new Reference($mailerId));
                $container->setAlias(ContactSubmissionNotifierInterface::class, MailerContactSubmissionNotifier::class);

                return;
            }
        }

        $container->removeDefinition(MailerContactSubmissionNotifier::class);
        $container->setAlias(ContactSubmissionNotifierInterface::class, NullContactSubmissionNotifier::class);
    }
}
