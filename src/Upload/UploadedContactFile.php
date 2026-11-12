<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Upload;

use LogicException;
use Nowo\ContactFormBundle\Exception\ContactFormFileException;

use function fopen;
use function is_file;
use function is_readable;
use function pathinfo;
use function sprintf;
use function str_replace;
use function strrpos;
use function substr;

use const PATHINFO_EXTENSION;

/**
 * A file submitted through a contact form file field, as a bundle value object (1.8, REQ-ARCH-001 rule 3): read it as
 * a stream, move it, and read its client name, size and MIME type, without the HttpFoundation `UploadedFile`.
 *
 * - {@see getMimeType()} is the type **detected on the server** from the file content (the same value the public
 *   form's MIME allowlist checked), never the type the client sent.
 * - {@see getClientName()} is the name the client sent (for a form upload: `UploadedFile::getClientOriginalName()`,
 *   exactly as the deprecated handler received it). It is untrusted (a misleading extension, control characters, and
 *   for a VO you build yourself `../` or `\`): never use it as a path; {@see moveTo()} keeps only its last segment.
 *
 * None of the values is stored in a property of this object: they live in an internal {@see \WeakMap} keyed by it, so a
 * dump (VarDumper / `dump()`), `var_export()`, `print_r()`, `(array)` cast or `json_encode()` shows no temporary path
 * and no client name. It cannot be cloned or serialized. The entry dies with the object, so nothing outlives the
 * request in a FrankenPHP worker (REQ-RUNTIME-002).
 */
final readonly class UploadedContactFile
{
    /**
     * @param string $path readable local path of the file (for example the PHP upload temporary file)
     * @param string $clientName the name the client sent (untrusted)
     * @param int|null $size size in bytes, null when unknown
     * @param string|null $mimeType MIME type detected on the server, null when unknown
     */
    public function __construct(string $path, string $clientName, ?int $size = null, ?string $mimeType = null)
    {
        UploadedContactFileData::put($this, [
            'path'       => $path,
            'clientName' => $clientName,
            'size'       => $size,
            'mimeType'   => $mimeType,
        ]);
    }

    /** The name the client sent (untrusted: never use it as a path). */
    public function getClientName(): string
    {
        return $this->data()['clientName'];
    }

    /** The extension of the client name (untrusted, like the name), without the dot; '' when there is none. */
    public function getClientExtension(): string
    {
        return pathinfo($this->getClientName(), PATHINFO_EXTENSION);
    }

    /** Size in bytes, null when unknown. */
    public function getSize(): ?int
    {
        return $this->data()['size'];
    }

    /** MIME type detected on the server from the content, null when unknown. */
    public function getMimeType(): ?string
    {
        return $this->data()['mimeType'];
    }

    /**
     * Opens the file for reading. The caller closes the stream.
     *
     * @throws ContactFormFileException when the file is gone or unreadable (for example after {@see moveTo()})
     *
     * @return resource
     */
    public function openStream()
    {
        $path = $this->data()['path'];

        if (!is_file($path) || !is_readable($path)) {
            throw new ContactFormFileException('The uploaded contact form file is not readable (moved or removed).');
        }

        $stream = @fopen($path, 'r');

        // @codeCoverageIgnoreStart
        if ($stream === false) {
            throw new ContactFormFileException('The uploaded contact form file cannot be opened.');
        }
        // @codeCoverageIgnoreEnd

        return $stream;
    }

    /**
     * Moves the file into `$directory` (created when missing) and returns the new path. For a real upload this is the
     * same move as `UploadedFile::move()` (`move_uploaded_file()`, same checks and permissions).
     *
     * @param string|null $name target file name; only its last path segment is used (`../../x.pdf` becomes `x.pdf`).
     *                          Null keeps the temporary file's own name, never the client name.
     *
     * @throws ContactFormFileException when the file cannot be moved
     */
    public function moveTo(string $directory, ?string $name = null): string
    {
        return UploadedContactFileData::move($this, $directory, $name);
    }

    /** Last path segment of a name (`/` and `\` both separate), as `UploadedFile::move()` does. */
    public static function lastSegment(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $pos  = strrpos($name, '/');

        return $pos === false ? $name : substr($name, $pos + 1);
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        throw new LogicException(sprintf('%s cannot be serialized: it carries upload data.', self::class));
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException(sprintf('%s cannot be unserialized: it carries upload data.', self::class));
    }

    /**
     * Not cloneable: a copy would carry no data (`clone` fails before this body runs).
     *
     * @codeCoverageIgnore
     */
    private function __clone()
    {
    }

    /**
     * @return array{path: string, clientName: string, size: int|null, mimeType: string|null}
     */
    private function data(): array
    {
        return UploadedContactFileData::get($this);
    }
}
