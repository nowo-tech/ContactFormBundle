<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Security;

use Nowo\ContactFormBundle\Security\AllowAllContactFormAccessChecker;
use Nowo\ContactFormBundle\Security\ConfigurableContactFormAccessChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

#[CoversClass(ConfigurableContactFormAccessChecker::class)]
#[CoversClass(AllowAllContactFormAccessChecker::class)]
final class ContactFormAccessCheckerTest extends TestCase
{
    public function testConfigurableGrantsWhenAnyRoleMatches(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_ADMIN');

        $checker = new ConfigurableContactFormAccessChecker($auth, ['ROLE_USER', 'ROLE_ADMIN']);

        self::assertTrue($checker->canAccess());
    }

    public function testConfigurableDeniesWhenNoRoleMatches(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturn(false);

        $checker = new ConfigurableContactFormAccessChecker($auth, ['ROLE_ADMIN']);

        self::assertFalse($checker->canAccess());
    }

    public function testConfigurableAllowsWhenRolesEmpty(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->expects(self::never())->method('isGranted');

        $checker = new ConfigurableContactFormAccessChecker($auth, []);

        self::assertTrue($checker->canAccess());
    }

    public function testAllowAllAlwaysGrants(): void
    {
        self::assertTrue((new AllowAllContactFormAccessChecker())->canAccess());
    }
}
