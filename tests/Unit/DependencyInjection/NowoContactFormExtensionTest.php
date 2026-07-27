<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\DependencyInjection;

use Nowo\ContactFormBundle\DependencyInjection\NowoContactFormExtension;
use Nowo\ContactFormBundle\Notification\ContactSubmissionNotifierInterface;
use Nowo\ContactFormBundle\Notification\MailerContactSubmissionNotifier;
use Nowo\ContactFormBundle\Notification\NullContactSubmissionNotifier;
use Nowo\ContactFormBundle\Security\AllowAllContactFormAccessChecker;
use Nowo\ContactFormBundle\Security\ContactFormAccessCheckerInterface;
use Nowo\ContactFormBundle\Service\ClientResolverInterface;
use Nowo\ContactFormBundle\Service\IpAnonymizer;
use Nowo\ContactFormBundle\Service\SecurityClientResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(NowoContactFormExtension::class)]
final class NowoContactFormExtensionTest extends TestCase
{
    public function testLoadSetsParametersAndNullNotifierByDefault(): void
    {
        $container = new ContainerBuilder();
        $extension = new NowoContactFormExtension();

        $extension->load([[
            'client_entity_class'    => 'App\\Entity\\Client',
            'client_label_property'  => 'name',
            'client_user_accessor'   => 'getClient',
            'ip_anonymization_salt'  => 'test-salt',
            'default_retention_days' => 90,
            'admin_route_prefix'     => '/admin/custom',
            'notifications'          => [
                'enabled'           => false,
                'service'           => null,
                'default_recipient' => null,
                'mailer'            => [
                    'enabled' => false,
                    'from'    => 'noreply@example.com',
                    'subject' => 'Test',
                ],
            ],
        ]], $container);

        self::assertSame('App\\Entity\\Client', $container->getParameter('nowo_contact_form.client_entity_class'));
        self::assertSame(90, $container->getParameter('nowo_contact_form.default_retention_days'));
        self::assertSame('/admin/custom', $container->getParameter('nowo_contact_form.admin_route_prefix'));
        self::assertTrue($container->hasDefinition(IpAnonymizer::class));
        self::assertSame(
            NullContactSubmissionNotifier::class,
            (string) $container->getAlias(ContactSubmissionNotifierInterface::class),
        );
        self::assertSame(
            SecurityClientResolver::class,
            (string) $container->getAlias(ClientResolverInterface::class),
        );
        self::assertTrue($container->hasParameter('nowo_contact_form.web_ui.layout_template'));
        self::assertSame(['ROLE_ADMIN'], $container->getParameter('nowo_contact_form.security.access_roles'));
    }

    public function testLoadRegistersAllowAllAccessCheckerWithoutSecurity(): void
    {
        $container = new ContainerBuilder();
        $extension = new NowoContactFormExtension();
        $extension->load([array_merge($this->baseConfig(), [
            'security' => [
                'access_roles'          => ['ROLE_ADMIN'],
                'access_checker'        => null,
                'allow_unauthenticated' => true,
            ],
        ])], $container);

        self::assertTrue($container->hasAlias(ContactFormAccessCheckerInterface::class));
        self::assertSame(
            'nowo_contact_form.access_checker.allow_all',
            (string) $container->getAlias(ContactFormAccessCheckerInterface::class),
        );
    }

    public function testLoadUsesCustomAccessCheckerService(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.access_checker', AllowAllContactFormAccessChecker::class);

        $extension = new NowoContactFormExtension();
        $extension->load([array_merge($this->baseConfig(), [
            'security' => [
                'access_roles'          => ['ROLE_ADMIN'],
                'access_checker'        => 'app.access_checker',
                'allow_unauthenticated' => false,
            ],
        ])], $container);

        self::assertSame(
            'app.access_checker',
            (string) $container->getAlias(ContactFormAccessCheckerInterface::class),
        );
    }

    public function testLoadUsesCustomNotifierServiceWhenConfigured(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.notifier', NullContactSubmissionNotifier::class);

        $extension = new NowoContactFormExtension();
        $extension->load([array_merge($this->baseConfig(), [
            'notifications' => [
                'enabled'           => true,
                'service'           => 'app.notifier',
                'default_recipient' => null,
                'mailer'            => ['enabled' => false, 'from' => 'a@b.c', 'subject' => 'S'],
            ],
        ])], $container);

        self::assertSame('app.notifier', (string) $container->getAlias(ContactSubmissionNotifierInterface::class));
    }

    public function testLoadRegistersMailerNotifierWhenMailerAvailable(): void
    {
        $container = new ContainerBuilder();
        $container->register('mailer.mailer', stdClass::class);

        $extension = new NowoContactFormExtension();
        $extension->load([array_merge($this->baseConfig(), [
            'notifications' => [
                'enabled'           => true,
                'service'           => null,
                'default_recipient' => 'admin@example.com',
                'mailer'            => [
                    'enabled' => true,
                    'from'    => 'noreply@example.com',
                    'subject' => 'New: {form}',
                ],
            ],
        ])], $container);

        self::assertTrue($container->hasDefinition(MailerContactSubmissionNotifier::class));
        self::assertSame(
            MailerContactSubmissionNotifier::class,
            (string) $container->getAlias(ContactSubmissionNotifierInterface::class),
        );
    }

    public function testGetAlias(): void
    {
        self::assertSame('nowo_contact_form', (new NowoContactFormExtension())->getAlias());
    }

    /**
     * @return array<string, mixed>
     */
    private function baseConfig(): array
    {
        return [
            'client_entity_class'    => null,
            'client_label_property'  => 'email',
            'client_user_accessor'   => null,
            'ip_anonymization_salt'  => 'salt',
            'default_retention_days' => 365,
            'admin_route_prefix'     => '/admin/contact-forms',
        ];
    }
}
