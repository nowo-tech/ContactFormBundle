<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

use LogicException;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Default handler that rejects uploads until the host app provides a custom implementation.
 */
final class NullContactFormFileUploadHandler implements ContactFormFileUploadHandlerInterface
{
    public function upload(UploadedFile $file, ContactForm $form, ContactFormField $field): string
    {
        throw new LogicException('File upload fields require a service implementing ContactFormFileUploadHandlerInterface. Configure nowo_contact_form.file_upload.service in your application.');
    }
}
