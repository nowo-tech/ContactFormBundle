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
        self::assertSame('/admin/contact-forms', $config['admin_route_prefix']);
        self::assertTrue($config['web_ui']['enabled']);
        self::assertSame('bootstrap5', $config['web_ui']['css_framework']);
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
}
