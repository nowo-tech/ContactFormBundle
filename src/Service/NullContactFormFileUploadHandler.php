<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

use LogicException;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Upload\ContactFormFileTarget;
use Nowo\ContactFormBundle\Upload\ContactFormFileUploaderInterface;
use Nowo\ContactFormBundle\Upload\UploadedContactFile;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Default handler that rejects uploads until the host app provides a custom implementation.
 *
 * Implements both the 1.8 {@see ContactFormFileUploaderInterface} and the deprecated
 * {@see ContactFormFileUploadHandlerInterface}, so both default aliases point at it.
 */
final class NullContactFormFileUploadHandler implements ContactFormFileUploaderInterface, ContactFormFileUploadHandlerInterface
{
    public const MESSAGE = 'File upload fields require a service implementing ContactFormFileUploaderInterface. Configure nowo_contact_form.file_upload.service in your application.';

    public function store(UploadedContactFile $file, ContactFormFileTarget $target): string
    {
        throw new LogicException(self::MESSAGE);
    }

    public function upload(UploadedFile $file, ContactForm $form, ContactFormField $field): string
    {
        throw new LogicException(self::MESSAGE);
    }
}
