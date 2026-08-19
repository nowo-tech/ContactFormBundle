<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\DependencyInjection;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\ContactFormBundle\DependencyInjection\TablePrefixListener;
use Nowo\ContactFormBundle\Entity\ContactForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TablePrefixListener::class)]
final class TablePrefixListenerTest extends TestCase
{
    #[Test]
    public function emptyPrefixIsANoOp(): void
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->expects(self::never())->method('setPrimaryTable');

        $event = $this->createMock(LoadClassMetadataEventArgs::class);
        $event->method('getClassMetadata')->willReturn($metadata);

        (new TablePrefixListener(''))->loadClassMetadata($event);
    }

    #[Test]
    public function nonContactFormEntityIsANoOp(): void
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getName')->willReturn('App\\Entity\\Post');
        $metadata->expects(self::never())->method('setPrimaryTable');

        $event = $this->createMock(LoadClassMetadataEventArgs::class);
        $event->method('getClassMetadata')->willReturn($metadata);

        (new TablePrefixListener('cf_'))->loadClassMetadata($event);
    }

    #[Test]
    public function contactFormEntityTableNameIsPrefixed(): void
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getName')->willReturn(ContactForm::class);
        $metadata->method('getTableName')->willReturn('nowo_contact_form');
        $metadata->expects(self::once())
            ->method('setPrimaryTable')
            ->with(['name' => 'cf_nowo_contact_form']);

        $event = $this->createMock(LoadClassMetadataEventArgs::class);
        $event->method('getClassMetadata')->willReturn($metadata);

        (new TablePrefixListener('cf_'))->loadClassMetadata($event);
    }
}
