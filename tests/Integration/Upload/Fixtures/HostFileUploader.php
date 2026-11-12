<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures;

use Nowo\ContactFormBundle\Upload\ContactFormFileTarget;
use Nowo\ContactFormBundle\Upload\ContactFormFileUploaderInterface;
use Nowo\ContactFormBundle\Upload\UploadedContactFile;

use function fclose;
use function stream_get_contents;

/**
 * A 1.8 host uploader: the new port only (VO + target). Same storage rule as {@see LegacyHostFileHandler}.
 */
final readonly class HostFileUploader implements ContactFormFileUploaderInterface
{
    public function __construct(private UploadRecorder $recorder)
    {
    }

    public function store(UploadedContactFile $file, ContactFormFileTarget $target): string
    {
        $stream  = $file->openStream();
        $content = (string) stream_get_contents($stream);
        fclose($stream);
        $moved = $file->moveTo($this->recorder->directory . '/new', $file->getClientName());

        return $this->recorder->record('new', $file->getClientName(), $file->getSize(), $file->getMimeType(), $content, $target->formSlug, $target->fieldName, $moved);
    }
}
