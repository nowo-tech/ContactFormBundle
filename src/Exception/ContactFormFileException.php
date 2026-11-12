<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Exception;

use Nowo\ContactFormBundle\Upload\ContactFormFileUploaderInterface;
use Nowo\ContactFormBundle\Upload\UploadedContactFile;
use RuntimeException;

/**
 * A submitted contact form file cannot be read or stored (1.8, {@see UploadedContactFile}
 * and {@see ContactFormFileUploaderInterface}).
 */
final class ContactFormFileException extends RuntimeException
{
}
