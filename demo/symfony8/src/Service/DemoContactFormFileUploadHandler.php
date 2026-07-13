<?php

declare(strict_types=1);

namespace App\Service;

use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function sprintf;

/**
 * Demo implementation that stores uploads under var/contact-uploads.
 */
final class DemoContactFormFileUploadHandler implements ContactFormFileUploadHandlerInterface
{
    public function __construct(
        private readonly string $uploadDirectory,
    ) {
    }

    public function upload(UploadedFile $file, ContactForm $form, ContactFormField $field): string
    {
        if (!is_dir($this->uploadDirectory) && !mkdir($this->uploadDirectory, 0775, true) && !is_dir($this->uploadDirectory)) {
            throw new RuntimeException(sprintf('Unable to create upload directory "%s".', $this->uploadDirectory));
        }

        $extension = $file->guessExtension() ?? 'bin';
        $filename  = sprintf(
            '%s_%s_%s.%s',
            preg_replace('/[^a-z0-9_]+/i', '_', $form->getSlug()) ?? 'form',
            preg_replace('/[^a-z0-9_]+/i', '_', $field->getName()) ?? 'file',
            bin2hex(random_bytes(8)),
            $extension,
        );

        $file->move($this->uploadDirectory, $filename);

        return $filename;
    }
}
