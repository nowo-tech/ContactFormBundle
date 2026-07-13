<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Service;

use Nowo\ContactFormBundle\Service\SecurityClientResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[CoversClass(SecurityClientResolver::class)]
final class SecurityClientResolverTest extends TestCase
{
    public function testResolveReturnsNullWhenNotConfigured(): void
    {
        $resolver = new SecurityClientResolver(null, null, null);

        self::assertNull($resolver->resolve());
    }

    public function testResolveReturnsClientEntityDirectly(): void
    {
        $client = new class implements UserInterface {
            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'client';
            }
        };

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($client);

        $storage = $this->createMock(TokenStorageInterface::class);
        $storage->method('getToken')->willReturn($token);

        $resolver = new SecurityClientResolver($client::class, null, $storage);

        self::assertSame($client, $resolver->resolve());
    }

    public function testResolveUsesUserAccessor(): void
    {
        $client = new class {
        };

        $user = new class($client) implements UserInterface {
            public function __construct(private object $client)
            {
            }

            public function getClient(): object
            {
                return $this->client;
            }

            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'user';
            }
        };

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $storage = $this->createMock(TokenStorageInterface::class);
        $storage->method('getToken')->willReturn($token);

        $resolver = new SecurityClientResolver($client::class, 'getClient', $storage);

        self::assertSame($client, $resolver->resolve());
    }

    public function testResolveReturnsNullForAnonymousUser(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        $storage = $this->createMock(TokenStorageInterface::class);
        $storage->method('getToken')->willReturn($token);

        $resolver = new SecurityClientResolver(stdClass::class, null, $storage);

        self::assertNull($resolver->resolve());
    }
}
