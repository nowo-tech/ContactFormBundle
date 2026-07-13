<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Service;

use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\Service\ContactPhonePrefixResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContactPhonePrefixResolver::class)]
final class ContactPhonePrefixResolverTest extends TestCase
{
    private ContactPhonePrefixResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ContactPhonePrefixResolver([
            '+34' => 'ES (+34)',
            '+1'  => 'US (+1)',
            '+44' => 'UK (+44)',
        ]);
    }

    public function testUsesDefaultsWhenFieldHasNoOptions(): void
    {
        $field = (new ContactFormField())->setType(ContactFieldType::Phone);

        self::assertSame([
            '+34' => 'ES (+34)',
            '+1'  => 'US (+1)',
            '+44' => 'UK (+44)',
        ], $this->resolver->resolveForField($field));
    }

    public function testUsesConfiguredPrefixesForPhoneField(): void
    {
        $field = (new ContactFormField())
            ->setType(ContactFieldType::Phone)
            ->setOptions(['widget' => 'symfony', 'prefixes' => ['+34', '+1']]);

        self::assertSame([
            '+34' => 'ES (+34)',
            '+1'  => 'US (+1)',
        ], $this->resolver->resolveForField($field));
    }
}
