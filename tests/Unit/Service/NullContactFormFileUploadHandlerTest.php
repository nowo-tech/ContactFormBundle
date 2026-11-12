<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Service;

use LogicException;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Service\NullContactFormFileUploadHandler;
use Nowo\ContactFormBundle\Upload\ContactFormFileTarget;
use Nowo\ContactFormBundle\Upload\UploadedContactFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[CoversClass(NullContactFormFileUploadHandler::class)]
final class NullContactFormFileUploadHandlerTest extends TestCase
{
    public function testUploadThrowsLogicException(): void
    {
        $handler = new NullContactFormFileUploadHandler();
        $file    = new UploadedFile(__FILE__, 'test.php', null, null, true);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('nowo_contact_form.file_upload.service');

        $handler->upload($file, new ContactForm(), new ContactFormField());
    }

    public function testStoreThrowsTheSameLogicException(): void
    {
        $handler = new NullContactFormFileUploadHandler();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(NullContactFormFileUploadHandler::MESSAGE);

        $handler->store(new UploadedContactFile(__FILE__, 'test.php'), new ContactFormFileTarget(1, 'support', 2, 'attachment'));
    }
}
