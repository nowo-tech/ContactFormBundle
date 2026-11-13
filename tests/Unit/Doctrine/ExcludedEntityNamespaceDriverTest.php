<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Doctrine\Persistence\Mapping\MappingException;
use Nowo\ContactFormBundle\DependencyInjection\Compiler\ContactFormEntityManagerPass;
use Nowo\ContactFormBundle\Doctrine\ExcludedEntityNamespaceDriver;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use PHPUnit\Framework\TestCase;

final class ExcludedEntityNamespaceDriverTest extends TestCase
{
    public function testMapsNothing(): void
    {
        $driver = new ExcludedEntityNamespaceDriver();

        self::assertTrue($driver->isTransient(ContactSubmission::class));
        self::assertSame([], $driver->getAllClassNames());

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(ContactSubmission::class);
        $driver->loadMetadataForClass(ContactSubmission::class, new ClassMetadata(ContactSubmission::class));
    }

    /**
     * A chain asks its namespace drivers before its default driver: a default driver that claims every entity class
     * no longer claims the contact form entities.
     */
    public function testWinsOverTheDefaultDriverOfAChain(): void
    {
        $catchAll = $this->createStub(MappingDriver::class);
        $catchAll->method('isTransient')->willReturn(false);
        $chain = new MappingDriverChain();
        $chain->setDefaultDriver($catchAll);
        self::assertFalse($chain->isTransient(ContactSubmission::class));

        $chain->addDriver(new ExcludedEntityNamespaceDriver(), ContactFormEntityManagerPass::ENTITY_NAMESPACE);

        self::assertTrue($chain->isTransient(ContactSubmission::class));
        self::assertTrue($chain->isTransient(ContactForm::class));
        self::assertFalse($chain->isTransient(self::class), 'other classes still go to the default driver');
    }
}
