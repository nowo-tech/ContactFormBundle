<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Enum;

/**
 * Supported customizable field types for contact forms.
 */
enum ContactFieldType: string
{
    case Text     = 'text';
    case Email    = 'email';
    case Phone    = 'phone';
    case Textarea = 'textarea';
    case Select   = 'select';
    case Checkbox = 'checkbox';
    case Number   = 'number';
    case Date     = 'date';
    case Url      = 'url';
    case File     = 'file';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
