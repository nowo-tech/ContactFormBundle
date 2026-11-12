<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Upload;

use Nowo\ContactFormBundle\Exception\ContactFormFileException;
use Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface;

/**
 * Host port that stores a file submitted through a contact form file field (1.8, REQ-ARCH-001 rules 1 and 3).
 *
 * Framework-free twin of the deprecated {@see ContactFormFileUploadHandlerInterface}:
 * the file is a bundle VO ({@see UploadedContactFile}: stream, client name, size, MIME type detected on the server)
 * and the form field is referenced by id and name ({@see ContactFormFileTarget}), so no HttpFoundation `UploadedFile`
 * and no bundle entity reach the host.
 *
 * The bundle calls the port only after the public form validated the file, with the same rules as before 1.8
 * (`file_upload.max_size` / `file_upload.mime_types` and the per-field `max_size` / `mime_types` options). The client
 * name is untrusted: sanitise it, or let {@see UploadedContactFile::moveTo()} keep only its last path segment.
 *
 * The returned string is persisted in the submission value (path, token, URL, etc.).
 *
 * Point it at your service with `nowo_contact_form.file_upload.service`, or alias / decorate this interface in your
 * services; the bundle then uses your service for every upload.
 */
interface ContactFormFileUploaderInterface
{
    /**
     * @throws ContactFormFileException when the file cannot be read or stored
     */
    public function store(UploadedContactFile $file, ContactFormFileTarget $target): string;
}
