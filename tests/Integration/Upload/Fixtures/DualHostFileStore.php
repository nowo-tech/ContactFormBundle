<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures;

use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface;
use Nowo\ContactFormBundle\Upload\ContactFormFileTarget;
use Nowo\ContactFormBundle\Upload\ContactFormFileUploaderInterface;
use Nowo\ContactFormBundle\Upload\UploadedContactFile;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A host in the middle of the migration: implements both ports.
 */
final readonly class DualHostFileStore implements ContactFormFileUploaderInterface, ContactFormFileUploadHandlerInterface
{
    public function __construct(private UploadRecorder $recorder)
    {
    }

    public function store(UploadedContactFile $file, ContactFormFileTarget $target): string
    {
        return (new HostFileUploader($this->recorder))->store($file, $target);
    }

    public function upload(UploadedFile $file, ContactForm $form, ContactFormField $field): string
    {
        return (new LegacyHostFileHandler($this->recorder))->upload($file, $form, $field);
    }
}
