<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Enum;

/**
 * Phone field rendering strategy on public forms.
 */
enum ContactPhoneWidget: string
{
    case Symfony    = 'symfony';
    case PhoneInput = 'phone_input';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
