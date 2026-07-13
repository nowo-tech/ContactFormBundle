<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Host application hook for storing files submitted through contact form file fields.
 *
 * The returned string is persisted in the submission value (path, token, URL, etc.).
 */
interface ContactFormFileUploadHandlerInterface
{
    public function upload(UploadedFile $file, ContactForm $form, ContactFormField $field): string;
}
