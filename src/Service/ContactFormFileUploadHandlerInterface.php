<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Upload\ContactFormFileUploaderInterface;
use Nowo\ContactFormBundle\Upload\UploadedContactFile;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Host application hook for storing files submitted through contact form file fields.
 *
 * The returned string is persisted in the submission value (path, token, URL, etc.).
 *
 * @deprecated since 1.8, implement {@see ContactFormFileUploaderInterface} instead (framework-free: an
 *             {@see UploadedContactFile} VO and the field ids, no `UploadedFile` and no
 *             entity); removed in 2.0. Until then the bundle still calls a service that implements only this interface.
 */
interface ContactFormFileUploadHandlerInterface
{
    public function upload(UploadedFile $file, ContactForm $form, ContactFormField $field): string;
}
