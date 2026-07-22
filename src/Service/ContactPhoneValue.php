<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

use function strlen;

/**
 * Combines and splits international phone numbers with a dialing prefix.
 */
final class ContactPhoneValue
{
    /**
     * @param list<string> $prefixes
     *
     * @return array{prefix: string, number: string}
     */
    public static function split(?string $value, array $prefixes): array
    {
        $defaultPrefix = $prefixes[0] ?? '+34';

        if ($value === null || $value === '') {
            return ['prefix' => $defaultPrefix, 'number' => ''];
        }

        foreach (self::sortByLengthDesc($prefixes) as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return [
                    'prefix' => $prefix,
                    'number' => substr($value, strlen($prefix)),
                ];
            }
        }

        return ['prefix' => $defaultPrefix, 'number' => $value];
    }

    public static function combine(string $prefix, string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number) ?? '';

        if ($digits === '') {
            return '';
        }

        return $prefix . $digits;
    }

    /**
     * @param list<string> $prefixes
     *
     * @return list<string>
     */
    private static function sortByLengthDesc(array $prefixes): array
    {
        $sorted = $prefixes;
        usort($sorted, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $sorted;
    }
}
