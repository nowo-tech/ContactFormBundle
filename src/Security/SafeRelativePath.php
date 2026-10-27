<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Security;

use function explode;
use function preg_match;
use function rawurldecode;
use function rtrim;
use function str_starts_with;

/**
 * Same-origin relative paths for redirects and links (CWE-601).
 *
 * Browsers normalise `\` to `/` and drop tabs / newlines in URLs (WHATWG URL parser), so `/\evil.example`,
 * `/<TAB>/evil.example` or `\\evil.example` all become the protocol-relative `//evil.example`. A safe path starts with a
 * single `/`, and contains no backslash, no whitespace and no control character anywhere.
 */
final class SafeRelativePath
{
    public static function isSafe(string $path): bool
    {
        return str_starts_with($path, '/')
            && !str_starts_with($path, '//')
            && preg_match('/[\\\\\x00-\x20\x7f]/', $path) !== 1
            && !self::hasDotSegment($path);
    }

    /**
     * `.` / `..` path segments (also percent-encoded, any case) would let `/admin/../evil` or `/admin/.%2e/.%2e//host`
     * leave the prefix once the browser resolves them.
     */
    private static function hasDotSegment(string $path): bool
    {
        $bare = rawurldecode(explode('?', $path, 2)[0]);

        foreach (explode('/', $bare) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return true;
            }
        }

        return false;
    }

    /**
     * A safe path inside `$prefix` (the prefix itself, or below it).
     */
    public static function isSafeUnder(string $path, string $prefix): bool
    {
        if (!self::isSafe($path)) {
            return false;
        }

        $prefix = rtrim($prefix, '/');
        $bare   = explode('?', $path, 2)[0];

        return $prefix === '' || $bare === $prefix || str_starts_with($bare, $prefix . '/');
    }
}
