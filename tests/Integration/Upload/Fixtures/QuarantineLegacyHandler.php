<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures;

use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A pre-1.8 host decorator of the deprecated port (e.g. an antivirus / quarantine step) — MZ-C-1.
 */
final readonly class QuarantineLegacyHandler implements ContactFormFileUploadHandlerInterface
{
    public function __construct(
        public ContactFormFileUploadHandlerInterface $inner,
        private UploadRecorder $recorder,
    ) {
    }

    public function upload(UploadedFile $file, ContactForm $form, ContactFormField $field): string
    {
        $this->recorder->decorated[] = $file->getClientOriginalName();

        return $this->inner->upload($file, $form, $field);
    }
}
