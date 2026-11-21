<?php

declare(strict_types=1);

namespace App\Service;

use Nowo\ContactFormBundle\Upload\ContactFormFileTarget;
use Nowo\ContactFormBundle\Upload\ContactFormFileUploaderInterface;
use Nowo\ContactFormBundle\Upload\UploadedContactFile;
use RuntimeException;

use function sprintf;

/**
 * Demo implementation of the upload port: stores uploads under var/contact-uploads.
 */
final class DemoContactFormFileUploadHandler implements ContactFormFileUploaderInterface
{
    public function __construct(
        private readonly string $uploadDirectory,
    ) {
    }

    public function store(UploadedContactFile $file, ContactFormFileTarget $target): string
    {
        if (!is_dir($this->uploadDirectory) && !mkdir($this->uploadDirectory, 0775, true) && !is_dir($this->uploadDirectory)) {
            throw new RuntimeException(sprintf('Unable to create upload directory "%s".', $this->uploadDirectory));
        }

        $extension = $file->getClientExtension();
        $filename  = sprintf(
            '%s_%s_%s.%s',
            preg_replace('/[^a-z0-9_]+/i', '_', $target->formSlug) ?? 'form',
            preg_replace('/[^a-z0-9_]+/i', '_', $target->fieldName) ?? 'file',
            bin2hex(random_bytes(8)),
            preg_match('/^[a-z0-9]{1,8}$/i', $extension) === 1 ? $extension : 'bin',
        );

        $file->moveTo($this->uploadDirectory, $filename);

        return $filename;
    }
}
