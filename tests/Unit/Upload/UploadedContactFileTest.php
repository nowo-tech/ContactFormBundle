<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Upload;

use Error;
use LogicException;
use Nowo\ContactFormBundle\Exception\ContactFormFileException;
use Nowo\ContactFormBundle\Upload\UploadedContactFile;
use Nowo\ContactFormBundle\Upload\UploadedContactFileData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

use function fclose;
use function file_get_contents;
use function file_put_contents;
use function is_file;
use function json_encode;
use function print_r;
use function serialize;
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;
use function var_export;

use const UPLOAD_ERR_OK;

#[CoversClass(UploadedContactFile::class)]
#[CoversClass(UploadedContactFileData::class)]
final class UploadedContactFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cf_upload_vo_' . uniqid('', true);
        (new Filesystem())->mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testAccessorsAndStream(): void
    {
        $path = $this->file('tmp-upload', 'hello');
        $file = new UploadedContactFile($path, '../../report.final.PDF', 5, 'text/plain');

        self::assertSame('../../report.final.PDF', $file->getClientName());
        self::assertSame('PDF', $file->getClientExtension());
        self::assertSame(5, $file->getSize());
        self::assertSame('text/plain', $file->getMimeType());

        $stream = $file->openStream();
        self::assertSame('hello', stream_get_contents($stream));
        fclose($stream);
    }

    public function testUnknownSizeAndType(): void
    {
        $file = new UploadedContactFile($this->file('tmp', ''), 'noext');

        self::assertNull($file->getSize());
        self::assertNull($file->getMimeType());
        self::assertSame('', $file->getClientExtension());
    }

    public function testMoveToKeepsOnlyTheLastSegmentOfTheName(): void
    {
        $file   = new UploadedContactFile($this->file('tmp-upload', 'data'), '..\\..\\evil.pdf');
        $target = $file->moveTo($this->dir . '/out', '../../..\\evil.pdf');

        self::assertSame($this->dir . '/out/evil.pdf', $target);
        self::assertSame('data', file_get_contents($target));
        // The VO now reads the moved file.
        $stream = $file->openStream();
        self::assertSame('data', stream_get_contents($stream));
        fclose($stream);
    }

    public function testMoveToWithoutANameKeepsTheTemporaryName(): void
    {
        $file = new UploadedContactFile($this->file('phpABC123', 'data'), 'client.pdf');

        self::assertSame($this->dir . '/out/phpABC123', $file->moveTo($this->dir . '/out'));
    }

    public function testMoveOfARealUploadUsesTheUploadedFileMove(): void
    {
        // Not a test-mode upload: UploadedFile::move() refuses a file PHP did not upload, exactly as before 1.8.
        $upload = new UploadedFile($this->file('phpXYZ', 'data'), 'client.pdf', null, UPLOAD_ERR_OK, false);
        $file   = UploadedContactFileData::fromUploadedFile($upload);

        $this->expectException(ContactFormFileException::class);
        $file->moveTo($this->dir . '/out', 'client.pdf');
    }

    public function testFromUploadedFileUsesTheServerSideTypeAndRealSize(): void
    {
        $upload = new UploadedFile($this->file('phpPNG', "\x89PNG\r\n\x1a\n" . str_repeat("\0", 32)), 'photo.pdf', 'application/pdf', null, true);
        $file   = UploadedContactFileData::fromUploadedFile($upload);

        self::assertSame('photo.pdf', $file->getClientName());
        self::assertSame($upload->getSize(), $file->getSize());
        self::assertSame($upload->getMimeType(), $file->getMimeType());
        self::assertNotSame('application/pdf', $file->getMimeType());

        self::assertSame($this->dir . '/out/photo.pdf', $file->moveTo($this->dir . '/out', 'photo.pdf'));
        self::assertTrue(is_file($this->dir . '/out/photo.pdf'));
    }

    public function testOpenStreamOfAMissingFileFails(): void
    {
        $file = new UploadedContactFile($this->dir . '/missing', 'x.pdf');

        $this->expectException(ContactFormFileException::class);
        $file->openStream();
    }

    public function testNothingLeaksThroughDumpsOrCasts(): void
    {
        $file = new UploadedContactFile($this->file('phpSECRETPATH', 'x'), 'private-client-name.pdf', 1, 'text/plain');

        $dump = (new CliDumper())->dump((new VarCloner())->cloneVar($file), true);
        foreach ([var_export($file, true), print_r($file, true), (string) json_encode($file), (string) $dump, print_r((array) $file, true)] as $output) {
            self::assertStringNotContainsString('phpSECRETPATH', $output);
            self::assertStringNotContainsString('private-client-name', $output);
        }
    }

    public function testCannotBeSerialized(): void
    {
        $this->expectException(LogicException::class);
        serialize(new UploadedContactFile($this->file('tmp', 'x'), 'x.pdf'));
    }

    public function testCannotBeUnserialized(): void
    {
        $this->expectException(LogicException::class);
        (new UploadedContactFile($this->file('tmp', 'x'), 'x.pdf'))->__unserialize([]);
    }

    public function testCannotBeCloned(): void
    {
        $file = new UploadedContactFile($this->file('tmp', 'x'), 'x.pdf');

        $this->expectException(Error::class);
        $copy = clone $file; // @phpstan-ignore-line (clone of a non-cloneable object is the point of the test)
        self::assertNotSame($file, $copy);
    }

    public function testLastSegment(): void
    {
        self::assertSame('x.pdf', UploadedContactFile::lastSegment('a/b\\c/x.pdf'));
        self::assertSame('x.pdf', UploadedContactFile::lastSegment('x.pdf'));
        self::assertSame('', UploadedContactFile::lastSegment('dir/'));
    }

    private function file(string $name, string $content): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }
}
