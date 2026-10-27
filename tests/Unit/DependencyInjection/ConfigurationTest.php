<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\DependencyInjection;

use Nowo\ContactFormBundle\DependencyInjection\Configuration;
use Nowo\ContactFormBundle\Service\DynamicContactFormBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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
        self::assertNull($config['admin_route_prefix']);
        self::assertTrue($config['web_ui']['enabled']);
        self::assertNull($config['web_ui']['path_prefix']);
        self::assertNull($config['web_ui']['layout_template']);
        self::assertNull($config['web_ui']['public_layout_template']);
        self::assertSame([], $config['web_ui']['templates']);
        self::assertNull($config['web_ui']['css_framework']);
        self::assertNull($config['web_ui']['icon_set']);
        self::assertSame(20, $config['web_ui']['list_page_size']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['access_roles']);
        self::assertFalse($config['security']['allow_unauthenticated']);
        self::assertFalse($config['notifications']['enabled']);
        self::assertFalse($config['notifications']['mailer']['enabled']);
        self::assertSame('', $config['doctrine']['table_prefix']);
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

    public function testDeprecatedKeysStillValidate(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'admin_route_prefix' => '/backoffice/contact',
            'web_ui'             => ['css_framework' => 'tailwind', 'icon_set' => 'none'],
        ]]);

        self::assertSame('/backoffice/contact', $config['admin_route_prefix']);
        self::assertSame('tailwind', $config['web_ui']['css_framework']);
        self::assertSame('none', $config['web_ui']['icon_set']);
    }
}
