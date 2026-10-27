<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Service;

use Nowo\ContactFormBundle\Service\ContactFormEnabledLocales;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

#[CoversClass(ContactFormEnabledLocales::class)]
final class ContactFormEnabledLocalesTest extends TestCase
{
    public function testReturnsTheKernelEnabledLocalesInOrder(): void
    {
        self::assertSame(['es', 'en'], (new ContactFormEnabledLocales(new ParameterBag(['kernel.enabled_locales' => ['x' => 'es', 'y' => 'en']])))->all());
    }

    public function testFallsBackToEnglish(): void
    {
        self::assertSame(['en'], (new ContactFormEnabledLocales(new ParameterBag(['kernel.enabled_locales' => []])))->all());
        self::assertSame(['en'], (new ContactFormEnabledLocales(new ParameterBag(['kernel.enabled_locales' => ['', 3]])))->all());
        self::assertSame(['en'], (new ContactFormEnabledLocales(new ParameterBag()))->all());
        self::assertSame(['en'], (new ContactFormEnabledLocales(new ParameterBag(['kernel.enabled_locales' => 'en,es'])))->all());
    }
}
