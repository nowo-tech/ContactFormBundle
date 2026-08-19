<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\DependencyInjection;

use Nowo\ContactFormBundle\DependencyInjection\NowoContactFormExtension;
use Nowo\ContactFormBundle\DependencyInjection\TablePrefixListener;
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
use Symfony\Component\DependencyInjection\Extension\Extension;

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
        self::assertSame('', $container->getParameter('nowo_contact_form.doctrine.table_prefix'));
        self::assertFalse($container->hasDefinition(TablePrefixListener::class));
    }

    public function testLoadRegistersTablePrefixListenerWhenConfigured(): void
    {
        $container = new ContainerBuilder();
        $extension = new NowoContactFormExtension();
        $extension->load([array_merge($this->baseConfig(), [
            'doctrine' => ['table_prefix' => 'cf_'],
        ])], $container);

        self::assertSame('cf_', $container->getParameter('nowo_contact_form.doctrine.table_prefix'));
        self::assertTrue($container->hasDefinition(TablePrefixListener::class));
        $definition = $container->getDefinition(TablePrefixListener::class);
        self::assertSame(['cf_'], $definition->getArguments());
        self::assertTrue($definition->hasTag('doctrine.event_listener'));
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

    public function testPrependSkipsFormKitAndUiKitWhenExtensionsMissing(): void
    {
        $container = new ContainerBuilder();
        (new NowoContactFormExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('nowo_form_kit'));
        self::assertSame([], $container->getExtensionConfig('nowo_ui_kit'));
    }

    public function testPrependSeedsFormKitContactFormProfileWhenHostUnset(): void
    {
        $container = new ContainerBuilder();
        $this->registerStubExtension($container, 'nowo_form_kit');

        (new NowoContactFormExtension())->prepend($container);

        $found = false;
        foreach ($container->getExtensionConfig('nowo_form_kit') as $cfg) {
            if (($cfg['css_framework'] ?? null) === 'bootstrap'
                && ($cfg['profiles']['contact_form']['alias'] ?? null) === 'contact_form'
            ) {
                $found = true;
                self::assertSame('NowoContactFormBundle', $cfg['profiles']['contact_form']['translation_domain']);
                break;
            }
        }
        self::assertTrue($found);
    }

    public function testPrependDoesNotOverrideExplicitFormKitHostConfig(): void
    {
        $container = new ContainerBuilder();
        $this->registerStubExtension($container, 'nowo_form_kit');
        $container->prependExtensionConfig('nowo_form_kit', [
            'css_framework' => 'none',
            'profiles'      => [
                'contact_form' => [
                    'alias'              => 'contact_form',
                    'translation_domain' => 'HostDomain',
                ],
            ],
        ]);

        (new NowoContactFormExtension())->prepend($container);

        foreach ($container->getExtensionConfig('nowo_form_kit') as $cfg) {
            if (($cfg['css_framework'] ?? null) === 'bootstrap') {
                self::fail('Must not re-seed css_framework when host already set it.');
            }
            if (($cfg['profiles']['contact_form']['translation_domain'] ?? null) === 'NowoContactFormBundle') {
                self::fail('Must not re-seed contact_form profile when host already defined it.');
            }
        }
        $first = $container->getExtensionConfig('nowo_form_kit')[0];
        self::assertSame('none', $first['css_framework'] ?? null);
        self::assertSame('HostDomain', $first['profiles']['contact_form']['translation_domain'] ?? null);
    }

    public function testPrependSeedsUiKitFromWebUiWhenHostUnset(): void
    {
        $container = new ContainerBuilder();
        $this->registerStubExtension($container, 'nowo_ui_kit');
        $container->prependExtensionConfig('nowo_contact_form', [
            'web_ui' => [
                'css_framework' => 'bootstrap5',
                'icon_set'      => 'bootstrap-icons',
            ],
        ]);

        (new NowoContactFormExtension())->prepend($container);

        $found = false;
        foreach ($container->getExtensionConfig('nowo_ui_kit') as $cfg) {
            if (($cfg['css_framework'] ?? null) === 'bootstrap5'
                && ($cfg['icon_set'] ?? null) === 'bootstrap-icons'
            ) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found);
    }

    public function testPrependDoesNotOverrideExplicitUiKitHostConfig(): void
    {
        $container = new ContainerBuilder();
        $this->registerStubExtension($container, 'nowo_ui_kit');
        $container->prependExtensionConfig('nowo_ui_kit', [
            'css_framework' => 'tailwind',
            'icon_set'      => 'heroicons',
        ]);

        (new NowoContactFormExtension())->prepend($container);

        foreach ($container->getExtensionConfig('nowo_ui_kit') as $cfg) {
            if (($cfg['css_framework'] ?? null) === 'bootstrap5') {
                self::fail('Must not override host UiKit css_framework.');
            }
            if (($cfg['icon_set'] ?? null) === 'bootstrap-icons' && !isset($cfg['css_framework'])) {
                // icon-only seed when framework already set would be ok; both set means skip entirely
            }
        }
        self::assertSame([], array_filter(
            $container->getExtensionConfig('nowo_ui_kit'),
            static fn (array $cfg): bool => ($cfg['css_framework'] ?? null) === 'bootstrap5',
        ));
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

    private function registerStubExtension(ContainerBuilder $container, string $alias): void
    {
        $container->registerExtension(new class($alias) extends Extension {
            public function __construct(private readonly string $aliasName)
            {
            }

            public function getAlias(): string
            {
                return $this->aliasName;
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }
        });
    }
}
