<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\RemovedPorts;

use LogicException;
use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormRemovedPortsPass;
use Nowo\ContactFormBundle\Tests\Fixtures\RemovedPorts\Unrelated\OtherMissingInterfaceUser;
use Nowo\ContactFormBundle\Upload\ContactFormFileUploaderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * W1-1 (XR-WF-M1) through the real `Bundle::build()`: every host service still tied to the upload port removed in 2.0
 * (`Service\ContactFormFileUploadHandlerInterface`, deprecated since 1.8), registered by resource autodiscovery as a
 * standard host does, fails the container build with a message naming the service, the removed interface, the version
 * that deprecated it and the replacement. Without the check Symfony drops such a service without a word (a class that
 * cannot be loaded keeps only a loading error and is removed as unused; an `IGNORE` decorator of a missing id is
 * removed), so a host uploader or a quarantine / antivirus decorator would silently stop running.
 */
#[CoversClass(ContactFormRemovedPortsPass::class)]
final class RemovedPortsKernelTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedHosts(): iterable
    {
        yield 'class implementing only the removed interface (+ #[AsAlias] of it)' => ['ImplementsOnly', 'The service "Nowo\ContactFormBundle\Tests\Fixtures\RemovedPorts\ImplementsOnly\LegacyUploadHandler" cannot be loaded: Class "Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface" not found'];
        yield '#[AsAlias] of the removed id' => ['AsAlias', 'The service id "Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface" (an alias of "Nowo\ContactFormBundle\Tests\Fixtures\RemovedPorts\AsAlias\HostUploadHandler") is no longer read'];
        yield '#[AsDecorator] of the removed id' => ['AsDecorator', 'The service "Nowo\ContactFormBundle\Tests\Fixtures\RemovedPorts\AsDecorator\QuarantineUploadDecorator" decorates "Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface", which no longer exists'];
        yield '#[AsDecorator] of the removed id with onInvalid: IGNORE' => ['AsDecoratorIgnore', 'The service "Nowo\ContactFormBundle\Tests\Fixtures\RemovedPorts\AsDecoratorIgnore\OptionalScanDecorator" decorates "Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface", which no longer exists'];
    }

    #[DataProvider('refusedHosts')]
    public function testAHostServiceOnTheRemovedPortFailsTheBuild(string $variant, string $message): void
    {
        $kernel = new RemovedPortsKernel($variant);
        (new Filesystem())->remove($kernel->baseDir());

        try {
            $kernel->boot();
            self::fail('The container build must fail.');
        } catch (LogicException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
            self::assertStringContainsString('(deprecated since 1.8) was removed in 2.0', $exception->getMessage());
            self::assertStringContainsString('implement "' . ContactFormFileUploaderInterface::class . '"', $exception->getMessage());
        } finally {
            $kernel->shutdown();
            (new Filesystem())->remove($kernel->baseDir());
        }
    }

    /**
     * Only the exact class name of the removed interface matches: an unused class implementing another missing
     * interface (whose name merely starts like it) keeps its lazy loading error and the container compiles.
     */
    public function testAnUnrelatedMissingInterfaceStillCompiles(): void
    {
        $kernel = new RemovedPortsKernel('Unrelated');
        (new Filesystem())->remove($kernel->baseDir());

        try {
            $kernel->boot();
            self::assertFalse($kernel->getContainer()->has(OtherMissingInterfaceUser::class));
            self::assertSame('test', $kernel->getContainer()->getParameter('kernel.environment'));
        } finally {
            $kernel->shutdown();
            (new Filesystem())->remove($kernel->baseDir());
        }
    }
}
