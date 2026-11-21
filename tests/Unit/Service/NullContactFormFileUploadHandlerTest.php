<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Service;

use LogicException;
use Nowo\ContactFormBundle\Service\NullContactFormFileUploadHandler;
use Nowo\ContactFormBundle\Upload\ContactFormFileTarget;
use Nowo\ContactFormBundle\Upload\UploadedContactFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(NullContactFormFileUploadHandler::class)]
final class NullContactFormFileUploadHandlerTest extends TestCase
{
    public function testStoreThrowsLogicException(): void
    {
        // 2.0 removed upload() with the 1.x port (deprecated since 1.8).
        self::assertFalse((new ReflectionClass(NullContactFormFileUploadHandler::class))->hasMethod('upload'));

        $handler = new NullContactFormFileUploadHandler();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(NullContactFormFileUploadHandler::MESSAGE);

        $handler->store(new UploadedContactFile(__FILE__, 'test.php'), new ContactFormFileTarget(1, 'support', 2, 'attachment'));
    }
}
