<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormOptionalServicesPass;
use Nowo\ContactFormBundle\Notification\ContactSubmissionNotifierInterface;
use Nowo\ContactFormBundle\Notification\MailerContactSubmissionNotifier;
use Nowo\ContactFormBundle\Notification\NullContactSubmissionNotifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use stdClass;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(ContactFormOptionalServicesPass::class)]
final class ContactFormOptionalServicesPassTest extends TestCase
{
    public function testRegistersClockFallbackWhenHostHasNone(): void
    {
        $container = new ContainerBuilder();
        (new ContactFormOptionalServicesPass())->process($container);

        self::assertSame(NativeClock::class, $container->getDefinition('clock')->getClass());
        self::assertSame('clock', (string) $container->getAlias(ClockInterface::class));
    }

    public function testKeepsHostClockAndClockAlias(): void
    {
        $container = new ContainerBuilder();
        $container->register('clock', Clock::class);
        $container->register('app.clock', Clock::class);
        $container->setAlias(ClockInterface::class, 'app.clock');

        (new ContactFormOptionalServicesPass())->process($container);

        self::assertSame(Clock::class, $container->getDefinition('clock')->getClass());
        self::assertSame('app.clock', (string) $container->getAlias(ClockInterface::class));
    }

    public function testNoopWithoutMailerNotifierCandidate(): void
    {
        $container = new ContainerBuilder();
        $container->setAlias(ContactSubmissionNotifierInterface::class, 'app.notifier');

        (new ContactFormOptionalServicesPass())->process($container);

        self::assertSame('app.notifier', (string) $container->getAlias(ContactSubmissionNotifierInterface::class));
    }

    public function testIgnoresMailerNotifierDefinedByHost(): void
    {
        $container = new ContainerBuilder();
        $container->register(MailerContactSubmissionNotifier::class)->setArguments([new Reference('app.mailer'), 'a@b.c', 'S']);
        $container->setAlias(ContactSubmissionNotifierInterface::class, MailerContactSubmissionNotifier::class);

        (new ContactFormOptionalServicesPass())->process($container);

        self::assertSame('app.mailer', (string) $container->getDefinition(MailerContactSubmissionNotifier::class)->getArgument(0));
    }

    public function testWiresMailerMailerService(): void
    {
        $container = $this->containerWithCandidate();
        $container->register('mailer.mailer', stdClass::class);
        $container->register('mailer', stdClass::class);

        (new ContactFormOptionalServicesPass())->process($container);

        $definition = $container->getDefinition(MailerContactSubmissionNotifier::class);
        self::assertSame('mailer.mailer', (string) $definition->getArgument(0));
        self::assertFalse($definition->hasTag(ContactFormOptionalServicesPass::MAILER_NOTIFIER_TAG));
        self::assertSame(
            MailerContactSubmissionNotifier::class,
            (string) $container->getAlias(ContactSubmissionNotifierInterface::class),
        );
    }

    public function testWiresMailerAlias(): void
    {
        $container = $this->containerWithCandidate();
        $container->register('app.mailer', stdClass::class);
        $container->setAlias('mailer', 'app.mailer');

        (new ContactFormOptionalServicesPass())->process($container);

        self::assertSame('mailer', (string) $container->getDefinition(MailerContactSubmissionNotifier::class)->getArgument(0));
    }

    public function testFallsBackToNullNotifierWithoutMailer(): void
    {
        $container = $this->containerWithCandidate();

        (new ContactFormOptionalServicesPass())->process($container);

        self::assertFalse($container->hasDefinition(MailerContactSubmissionNotifier::class));
        self::assertSame(
            NullContactSubmissionNotifier::class,
            (string) $container->getAlias(ContactSubmissionNotifierInterface::class),
        );
    }

    private function containerWithCandidate(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(MailerContactSubmissionNotifier::class)
            ->setArguments([null, '$from' => 'a@b.c', '$subjectTemplate' => 'S'])
            ->addTag(ContactFormOptionalServicesPass::MAILER_NOTIFIER_TAG);
        $container->setAlias(ContactSubmissionNotifierInterface::class, NullContactSubmissionNotifier::class);

        return $container;
    }
}
