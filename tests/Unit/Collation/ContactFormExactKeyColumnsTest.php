<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Collation;

use Nowo\ContactFormBundle\Collation\ContactFormExactKeyColumns;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContactFormExactKeyColumns::class)]
final class ContactFormExactKeyColumnsTest extends TestCase
{
    public function testDeclaresTheClientReferenceColumn(): void
    {
        $columns = (new ContactFormExactKeyColumns())->exactKeyColumns();

        self::assertCount(1, $columns);
        self::assertSame('nowo_contact_submission.client_reference', $columns[0]->describe());
        self::assertSame(ContactFormExactKeyColumns::OWNER, $columns[0]->owner);
        self::assertNotSame('', $columns[0]->purpose);
        self::assertTrue($columns[0]->active);
    }

    public function testTheConfiguredTablePrefixIsApplied(): void
    {
        // The declaration must name the table the host really has, or a host-side aggregate run reports a false
        // failure on every host that sets nowo_contact_form.doctrine.table_prefix.
        self::assertSame(
            'acme_nowo_contact_submission.client_reference',
            (new ContactFormExactKeyColumns('acme_'))->exactKeyColumns()[0]->describe(),
        );
    }
}
