<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\DddHost\Infrastructure;

use Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\DddHost\Domain\AttachmentVault;
use Nowo\ContactFormBundle\Upload\ContactFormFileTarget;
use Nowo\ContactFormBundle\Upload\ContactFormFileUploaderInterface;
use Nowo\ContactFormBundle\Upload\UploadedContactFile;

use function fclose;
use function preg_replace;

/**
 * The DDD host's adapter of the bundle port: maps the bundle VOs to its own domain port. No HttpFoundation, no
 * Doctrine, no bundle entity.
 */
final readonly class ContactFormAttachmentAdapter implements ContactFormFileUploaderInterface
{
    public function __construct(private AttachmentVault $vault)
    {
    }

    public function store(UploadedContactFile $file, ContactFormFileTarget $target): string
    {
        $stream = $file->openStream();

        try {
            $safe = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', UploadedContactFile::lastSegment($file->getClientName()));

            return $this->vault->keep($target->formSlug . '/' . $target->fieldName, $safe, $stream);
        } finally {
            fclose($stream);
        }
    }
}
