<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Service;

use Nowo\ContactFormBundle\Service\IpAnonymizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function strlen;

#[CoversClass(IpAnonymizer::class)]
final class IpAnonymizerTest extends TestCase
{
    public function testAnonymizeReturnsHash(): void
    {
        $anonymizer = new IpAnonymizer('salt');
        $hash       = $anonymizer->anonymize('192.168.1.1');

        self::assertNotNull($hash);
        self::assertSame(64, strlen($hash));
        self::assertSame($hash, $anonymizer->anonymize('192.168.1.1'));
    }

    public function testAnonymizeReturnsNullForEmptyIp(): void
    {
        $anonymizer = new IpAnonymizer('salt');

        self::assertNull($anonymizer->anonymize(null));
        self::assertNull($anonymizer->anonymize(''));
    }

    public function testDifferentSaltsProduceDifferentHashes(): void
    {
        $ip = '10.0.0.1';

        self::assertNotSame(
            (new IpAnonymizer('a'))->anonymize($ip),
            (new IpAnonymizer('b'))->anonymize($ip),
        );
    }
}
