<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures;

use Nowo\ContactFormBundle\Upload\ContactFormFileTarget;
use Nowo\ContactFormBundle\Upload\ContactFormFileUploaderInterface;
use Nowo\ContactFormBundle\Upload\UploadedContactFile;

/**
 * Decorator of the 1.8 port (e.g. a virus scan in front of the storage).
 */
final readonly class DecoratingFileUploader implements ContactFormFileUploaderInterface
{
    public function __construct(public ContactFormFileUploaderInterface $inner)
    {
    }

    public function store(UploadedContactFile $file, ContactFormFileTarget $target): string
    {
        return $this->inner->store($file, $target);
    }
}
