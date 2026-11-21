<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\DependencyInjection;

use Nowo\ContactFormBundle\DependencyInjection\Configuration;
use Nowo\ContactFormBundle\Service\DynamicContactFormBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(Configuration::class)]
final class ConfigurationTest extends TestCase
{
    public function testDefaultConfiguration(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), []);

        self::assertNull($config['client_entity_class']);
        self::assertSame('email', $config['client_label_property']);
        self::assertSame(365, $config['default_retention_days']);
        self::assertArrayNotHasKey('admin_route_prefix', $config);
        self::assertTrue($config['web_ui']['enabled']);
        self::assertNull($config['web_ui']['path_prefix']);
        self::assertNull($config['web_ui']['layout_template']);
        self::assertNull($config['web_ui']['public_layout_template']);
        self::assertSame([], $config['web_ui']['templates']);
        self::assertArrayNotHasKey('css_framework', $config['web_ui']);
        self::assertArrayNotHasKey('icon_set', $config['web_ui']);
        self::assertNull($config['doctrine']['private_entity_manager']);
        self::assertSame(20, $config['web_ui']['list_page_size']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['access_roles']);
        self::assertFalse($config['security']['allow_unauthenticated']);
        self::assertFalse($config['notifications']['enabled']);
        self::assertFalse($config['notifications']['mailer']['enabled']);
        self::assertSame('', $config['doctrine']['table_prefix']);
        self::assertSame('attribute', $config['doctrine']['mapping_type']);
        self::assertSame('5M', $config['file_upload']['max_size']);
        self::assertSame(DynamicContactFormBuilder::DEFAULT_FILE_MIME_TYPES, $config['file_upload']['mime_types']);
    }

    public function testFileUploadLimitsAreConfigurable(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'file_upload' => ['max_size' => '2Mi', 'mime_types' => ['application/pdf']],
        ]]);
        self::assertSame('2Mi', $config['file_upload']['max_size']);
        self::assertSame(['application/pdf'], $config['file_upload']['mime_types']);

        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'file_upload' => ['max_size' => null, 'mime_types' => []],
        ]]);
        self::assertNull($config['file_upload']['max_size']);
        self::assertSame([], $config['file_upload']['mime_types']);
    }

    public function testInvalidFileUploadMaxSizeIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new Configuration(), [[
            'file_upload' => ['max_size' => '5 megabytes'],
        ]]);
    }

    public function testCustomConfiguration(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[
            'client_entity_class'    => 'App\\Entity\\Client',
            'client_label_property'  => 'name',
            'ip_anonymization_salt'  => 'custom-salt',
            'default_retention_days' => 30,
            'doctrine'               => [
                'table_prefix' => 'app_',
            ],
        ]]);

        self::assertSame('App\\Entity\\Client', $config['client_entity_class']);
        self::assertSame('name', $config['client_label_property']);
        self::assertSame('custom-salt', $config['ip_anonymization_salt']);
        self::assertSame(30, $config['default_retention_days']);
        self::assertSame('app_', $config['doctrine']['table_prefix']);
    }

    public function testLegacyDefaultLayoutIsMappedToNull(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'web_ui' => ['layout_template' => Configuration::LEGACY_DEFAULT_LAYOUT],
        ]]);

        self::assertNull($config['web_ui']['layout_template']);

        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'web_ui' => ['layout_template' => 'admin/layout.html.twig', 'public_layout_template' => 'guest.html.twig'],
        ]]);

        self::assertSame('admin/layout.html.twig', $config['web_ui']['layout_template']);
        self::assertSame('guest.html.twig', $config['web_ui']['public_layout_template']);
    }

    public function testPerPageTemplatesAcceptTheKnownPageIds(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'web_ui' => ['templates' => ['form_index' => 'admin/contact/forms.html.twig', 'public_form' => 'contact.html.twig']],
        ]]);

        self::assertSame(['form_index' => 'admin/contact/forms.html.twig', 'public_form' => 'contact.html.twig'], $config['web_ui']['templates']);
    }

    public function testUnknownPerPageTemplateIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unknown web_ui.templates page(s) "dashboard"');

        (new Processor())->processConfiguration(new Configuration(), [[
            'web_ui' => ['templates' => ['dashboard' => 'dashboard.html.twig']],
        ]]);
    }

    public function testPathPrefixMustNotBeBlank(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'web_ui' => ['path_prefix' => '  '],
        ]]);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function removedKeys(): iterable
    {
        yield 'admin_route_prefix' => [['admin_route_prefix' => '/backoffice/contact'], 'The option "nowo_contact_form.admin_route_prefix" was removed in 2.0 (deprecated since 1.6): set "nowo_contact_form.web_ui.path_prefix" instead. See UPGRADING.md "To 2.0.0".'];
        yield 'web_ui.css_framework' => [['web_ui' => ['css_framework' => 'tailwind']], 'The option "nowo_contact_form.web_ui.css_framework" was removed in 2.0 (deprecated since 1.6): set "nowo_ui_kit.css_framework" instead'];
        yield 'web_ui.icon_set' => [['web_ui' => ['icon_set' => 'none']], 'The option "nowo_contact_form.web_ui.icon_set" was removed in 2.0 (deprecated since 1.6): set "nowo_ui_kit.icon_set" instead.'];
    }

    /**
     * 2.0 (owner decision Q-W4-1): the 1.6 keys are removed; a leftover key fails the build naming its replacement.
     *
     * @param array<string, mixed> $config
     */
    #[DataProvider('removedKeys')]
    public function testRemovedKeysFailTheBuild(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        (new Processor())->processConfiguration(new Configuration(), [$config]);
    }

    /**
     * 2.0: `private_entity_manager: true` is accepted (deprecated no-op); `false` fails the build — with a dedicated
     * manager the message says it keeps working.
     */
    public function testPrivateEntityManagerFalseFailsTheBuild(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['doctrine' => ['private_entity_manager' => true]]]);
        self::assertTrue($config['doctrine']['private_entity_manager']);

        try {
            (new Processor())->processConfiguration(new Configuration(), [['doctrine' => ['private_entity_manager' => false, 'entity_manager' => 'contact_form']]]);
            self::fail('false must fail the build');
        } catch (InvalidConfigurationException $exception) {
            self::assertStringContainsString('private_entity_manager: false is no longer supported', $exception->getMessage());
            self::assertStringContainsString('doctrine.entity_manager "contact_form" stays: a dedicated manager keeps working as in 1.x', $exception->getMessage());
            self::assertStringContainsString('UPGRADING.md "To 2.0.0"', $exception->getMessage());
        }

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The bundle then writes through its private entity manager');
        (new Processor())->processConfiguration(new Configuration(), [['doctrine' => ['private_entity_manager' => false]]]);
    }

    public function testConfigurationIsFinal(): void
    {
        self::assertTrue((new ReflectionClass(Configuration::class))->isFinal());
        self::assertSame(['bootstrap', 'bootstrap4', 'bootstrap5', 'tailwind', 'foundation', 'custom', 'tabler', 'none'], Configuration::CSS_FRAMEWORKS);
    }

    public function testConflictResponseDefaultsToNullAndAccepts409Or503(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), []);
        self::assertSame(['status' => null, 'retry_after' => null, 'commit_tracking' => 'bundle'], $config['conflict_response']);

        $config = (new Processor())->processConfiguration(new Configuration(), [['conflict_response' => ['status' => 503, 'retry_after' => 3, 'commit_tracking' => 'off']]]);
        self::assertSame(['status' => 503, 'retry_after' => 3, 'commit_tracking' => 'off'], $config['conflict_response']);
    }

    public function testConflictResponseRejectsOtherStatuses(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must be 409 or 503');

        (new Processor())->processConfiguration(new Configuration(), [['conflict_response' => ['status' => 500]]]);
    }

    public function testCommitTrackingRejectsUnknownModes(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [['conflict_response' => ['commit_tracking' => 'host']]]);
    }
}
