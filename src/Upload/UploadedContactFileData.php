<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Upload;

use LogicException;
use Nowo\ContactFormBundle\Exception\ContactFormFileException;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WeakMap;

/**
 * Holds the values of each {@see UploadedContactFile} outside the object (and, for a real upload, the HttpFoundation
 * file it was built from), so no dump of the VO can reach the temporary path or the client name.
 *
 * Keyed by the VO in a {@see WeakMap}: an entry disappears with its VO, so nothing outlives the request in a FrankenPHP
 * worker (no reset needed, REQ-RUNTIME-002).
 *
 * @internal
 */
final class UploadedContactFileData
{
    /** @var WeakMap<UploadedContactFile, array{path: string, clientName: string, size: int|null, mimeType: string|null}>|null */
    private static ?WeakMap $values = null; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (WeakMap keyed by the per-request VO)

    /** @var WeakMap<UploadedContactFile, UploadedFile>|null */
    private static ?WeakMap $origins = null; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (WeakMap keyed by the per-request VO)

    /**
     * @param array{path: string, clientName: string, size: int|null, mimeType: string|null} $values
     */
    public static function put(UploadedContactFile $file, array $values): void
    {
        // @igor-ignore - WeakMap keyed by the per-request VO; entries are collected with it.
        self::$values ??= new WeakMap(); // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
        // @igor-ignore - WeakMap keyed by the per-request VO; entries are collected with it.
        self::$values[$file] = $values; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
    }

    /**
     * Builds the VO of a validated HTTP upload: the client name, the real size and the MIME type guessed from the
     * content (`UploadedFile::getMimeType()`, the value the form's MIME allowlist checked).
     */
    public static function fromUploadedFile(UploadedFile $upload): UploadedContactFile
    {
        $size = $upload->getSize();
        $file = new UploadedContactFile(
            $upload->getPathname(),
            $upload->getClientOriginalName(),
            $size === false ? null : $size,
            $upload->getMimeType(),
        );

        // @igor-ignore - WeakMap keyed by the per-request VO; entries are collected with it.
        self::$origins ??= new WeakMap(); // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
        // @igor-ignore - WeakMap keyed by the per-request VO; entries are collected with it.
        self::$origins[$file] = $upload; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty

        return $file;
    }

    /**
     * @return array{path: string, clientName: string, size: int|null, mimeType: string|null}
     */
    public static function get(UploadedContactFile $file): array
    {
        if (!isset(self::$values[$file])) {
            // @codeCoverageIgnoreStart
            throw new LogicException('This uploaded contact form file has no data.');
            // @codeCoverageIgnoreEnd
        }

        return self::$values[$file];
    }

    public static function move(UploadedContactFile $file, string $directory, ?string $name): string
    {
        $values = self::get($file);
        $source = self::$origins[$file] ?? new File($values['path'], false);
        $name   = $name === null ? null : UploadedContactFile::lastSegment($name);

        try {
            $moved = $source->move($directory, $name);
        } catch (FileException $exception) {
            throw new ContactFormFileException($exception->getMessage(), 0, $exception);
        }

        $values['path'] = $moved->getPathname();
        self::put($file, $values);
        unset(self::$origins[$file]); // @phpstan-ignore frankenphp.worker.noMutableStaticProperty

        return $moved->getPathname();
    }
}
