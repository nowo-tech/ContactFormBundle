<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Service;

use LogicException;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Service\NullContactFormFileUploadHandler;
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
        $this->expectExceptionMessage('ContactFormFileUploadHandlerInterface');

        $handler->upload($file, new ContactForm(), new ContactFormField());
    }
}
