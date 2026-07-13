<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\DependencyInjection;

use Nowo\ContactFormBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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
        self::assertFalse($config['notifications']['enabled']);
        self::assertFalse($config['notifications']['mailer']['enabled']);
    }

    public function testCustomConfiguration(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[
            'client_entity_class'    => 'App\\Entity\\Client',
            'client_label_property'  => 'name',
            'ip_anonymization_salt'  => 'custom-salt',
            'default_retention_days' => 30,
        ]]);

        self::assertSame('App\\Entity\\Client', $config['client_entity_class']);
        self::assertSame('name', $config['client_label_property']);
        self::assertSame('custom-salt', $config['ip_anonymization_salt']);
        self::assertSame(30, $config['default_retention_days']);
    }
}
