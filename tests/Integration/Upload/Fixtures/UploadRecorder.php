<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures;

use function hash;
use function is_dir;
use function mkdir;

/**
 * What a host storage saw for each upload (same shape for the deprecated handler and the 1.8 uploader), and the
 * directory it stores into. Public test service.
 */
final class UploadRecorder
{
    /** @var list<array{storage: string, clientName: string, size: int|null, mimeType: string|null, sha256: string, formSlug: string, fieldName: string, storedPath: string}> */
    public array $seen = [];

    /** @var list<string> client names that went through a legacy-port decorator */
    public array $decorated = [];

    public function __construct(public readonly string $directory)
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
    }

    /**
     * The same storage rule for both ports: content hash + the last segment of the client name.
     */
    public function record(string $storage, string $clientName, ?int $size, ?string $mimeType, string $content, string $formSlug, string $fieldName, string $storedPath): string
    {
        $sha          = hash('sha256', $content);
        $this->seen[] = [
            'storage'    => $storage,
            'clientName' => $clientName,
            'size'       => $size,
            'mimeType'   => $mimeType,
            'sha256'     => $sha,
            'formSlug'   => $formSlug,
            'fieldName'  => $fieldName,
            'storedPath' => $storedPath,
        ];

        return 'stored/' . $formSlug . '/' . $fieldName . '/' . $sha;
    }
}
