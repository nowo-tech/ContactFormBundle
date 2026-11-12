<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures;

use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function file_get_contents;

/**
 * A pre-1.8 host handler: the deprecated port only (UploadedFile + entities).
 */
final readonly class LegacyHostFileHandler implements ContactFormFileUploadHandlerInterface
{
    public function __construct(private UploadRecorder $recorder)
    {
    }

    public function upload(UploadedFile $file, ContactForm $form, ContactFormField $field): string
    {
        $size     = $file->getSize();
        $name     = $file->getClientOriginalName();
        $mimeType = $file->getMimeType();
        $content  = (string) file_get_contents($file->getPathname());
        $moved    = $file->move($this->recorder->directory . '/legacy', $name);

        return $this->recorder->record('legacy', $name, $size === false ? null : $size, $mimeType, $content, $form->getSlug(), $field->getName(), $moved->getPathname());
    }
}
