<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Service;

use Nowo\ContactFormBundle\Service\ClientLabelResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Stringable;

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

    public function testPrivatePropertyIsReadThroughItsGetter(): void
    {
        $client = new class {
            private string $email = 'private@example.com';

            public function getEmail(): string
            {
                return $this->email;
            }
        };

        self::assertSame('private@example.com', (new ClientLabelResolver('App\\Entity\\Client'))->resolveLabel($client));
    }

    public function testPrivatePropertyWithoutAccessorIsNeverReadDirectly(): void
    {
        $client = new class {
            private string $email = 'hidden@example.com';

            public function getId(): int
            {
                return 7;
            }

            public function contactAddress(): string
            {
                return $this->email;
            }
        };

        self::assertSame('7', (new ClientLabelResolver(null, 'email'))->resolveLabel($client));
    }

    public function testIsserIsUsed(): void
    {
        $client = new class {
            public function isName(): string
            {
                return 'Isser Corp';
            }
        };

        self::assertSame('Isser Corp', (new ClientLabelResolver(null, 'name'))->resolveLabel($client));
    }

    public function testMissingPropertyFallsBackToDefaultLabel(): void
    {
        $client = new class {
            public string $name = 'unused';
        };

        self::assertSame('client', (new ClientLabelResolver(null, 'email'))->resolveLabel($client));
    }

    public function testEmptyLabelPropertyIsIgnored(): void
    {
        $client = new class {
            public string $email = 'user@example.com';
        };

        self::assertSame('client', (new ClientLabelResolver(null, ''))->resolveLabel($client));
    }

    public function testFailingGetterFallsBackToId(): void
    {
        $client = new class {
            public function getEmail(): string
            {
                throw new RuntimeException('not loaded');
            }

            public function getId(): string
            {
                return '0195e8a4-6d2b-7c3e-9f00-1a2b3c4d5e6f';
            }
        };

        self::assertSame('0195e8a4-6d2b-7c3e-9f00-1a2b3c4d5e6f', (new ClientLabelResolver(null, 'email'))->resolveLabel($client));
    }

    public function testUninitializedPublicPropertyFallsBack(): void
    {
        $client = new class {
            public string $email;
        };

        self::assertSame('client', (new ClientLabelResolver(null, 'email'))->resolveLabel($client));
    }

    public function testNonStringLabelFallsBackToStringableId(): void
    {
        $client = new class {
            public int $email = 5;

            public function getId(): Stringable
            {
                return new class implements Stringable {
                    public function __toString(): string
                    {
                        return 'uuid-like';
                    }
                };
            }
        };

        self::assertSame('uuid-like', (new ClientLabelResolver(null, 'email'))->resolveLabel($client));
    }

    public function testNonStringableIdFallsBackToDefaultLabel(): void
    {
        $client = new class {
            public function getId(): stdClass
            {
                return new stdClass();
            }
        };

        self::assertSame('client', (new ClientLabelResolver(null, 'email'))->resolveLabel($client));
    }
}
