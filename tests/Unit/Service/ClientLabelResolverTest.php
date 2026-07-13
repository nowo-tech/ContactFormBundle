<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Service;

use Nowo\ContactFormBundle\Service\ClientLabelResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(ClientLabelResolver::class)]
final class ClientLabelResolverTest extends TestCase
{
    public function testResolveLabelFromProperty(): void
    {
        $client = new class {
            public string $email = 'user@example.com';
        };
        $resolver = new ClientLabelResolver('App\\Entity\\Client', 'email');

        self::assertSame('App\\Entity\\Client', $resolver->getClientEntityClass());
        self::assertSame('user@example.com', $resolver->resolveLabel($client));
    }

    public function testResolveLabelFromMethodAndGetter(): void
    {
        $client = new class {
            public function name(): string
            {
                return 'Acme Corp';
            }
        };

        $resolver = new ClientLabelResolver(null, 'name');
        self::assertSame('Acme Corp', $resolver->resolveLabel($client));
    }

    public function testResolveLabelFallbackToId(): void
    {
        $client = new class {
            public function getId(): int
            {
                return 99;
            }
        };

        $resolver = new ClientLabelResolver(null, 'missing');
        self::assertSame('99', $resolver->resolveLabel($client));
    }

    public function testResolveLabelDefaultWhenNothingMatches(): void
    {
        $resolver = new ClientLabelResolver(null, 'missing');
        self::assertSame('client', $resolver->resolveLabel(new stdClass()));
    }
}
