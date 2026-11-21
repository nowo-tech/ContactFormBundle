<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

use LogicException;
use Nowo\ContactFormBundle\Upload\ContactFormFileTarget;
use Nowo\ContactFormBundle\Upload\ContactFormFileUploaderInterface;
use Nowo\ContactFormBundle\Upload\UploadedContactFile;

/**
 * Default handler that rejects uploads until the host app provides a custom implementation.
 *
 * The default of {@see ContactFormFileUploaderInterface}, the only upload port since 2.0.
 */
final class NullContactFormFileUploadHandler implements ContactFormFileUploaderInterface
{
    public const MESSAGE = 'File upload fields require a service implementing ContactFormFileUploaderInterface. Configure nowo_contact_form.file_upload.service in your application.';

    public function store(UploadedContactFile $file, ContactFormFileTarget $target): string
    {
        throw new LogicException(self::MESSAGE);
    }
}
