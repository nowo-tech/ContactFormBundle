<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Ui;

use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Tests\Support\PageFixtures;
use Nowo\ContactFormBundle\Ui\ContactFormPageFactory;
use Nowo\ContactFormBundle\Ui\ContactFormPages;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\DetailSection;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\ShowPage;
use Nowo\UiKitBundle\View\WizardPage;
use Nowo\UiKitBundle\View\WizardStep;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * View models of the bundle pages (REQ-UI-003): the same fixtures the page contract test renders, checked as data.
 */
#[CoversClass(ContactFormPageFactory::class)]
final class ContactFormPageFactoryTest extends TestCase
{
    public function testFormIndexHasRowActionsAndADeleteBehindAConfirm(): void
    {
        $page = PageFixtures::formIndex()['page'];

        self::assertInstanceOf(ListPage::class, $page);
        self::assertSame(ContactFormPages::BUNDLE, $page->bundle);
        self::assertSame(['contact-form-forms'], [$page->attributes['data-testid']]);
        self::assertSame(['name', 'slug', 'enabled'], array_map(static fn (Column $column): string => $column->key, $page->columns));
        self::assertSame(2, $page->pagination?->totalPages);

        $actions = $page->rowActions($page->rows[0], 0);
        self::assertSame(['edit', 'custom', 'custom', 'delete'], array_map(static fn (Action $action): string => $action->kind, $actions));
        self::assertSame('/admin/contact-forms/1/delete', $actions[3]->href);
        self::assertTrue($actions[3]->isPost());
        self::assertNotNull($actions[3]->form);
        self::assertInstanceOf(Label::class, $actions[3]->confirm?->title);
        self::assertSame('nowo_contact_form.admin.form.delete_title', $actions[3]->confirm->title->key);

        $empty = PageFixtures::formIndex(true)['page'];
        self::assertSame([], $empty->rows);
        self::assertSame('create', $empty->empty?->action?->kind);
    }

    public function testFormPageHasTheDeleteZoneOnlyOnEdit(): void
    {
        $new  = PageFixtures::formForm()['page'];
        $edit = PageFixtures::formForm(true)['page'];

        self::assertInstanceOf(FormPage::class, $new);
        self::assertNull($new->deleteAction);
        self::assertSame([], $new->secondaryActions);
        self::assertNull($new->header->intro);

        self::assertInstanceOf(FormPage::class, $edit);
        self::assertSame('delete', $edit->deleteAction?->kind);
        self::assertCount(2, $edit->secondaryActions);
        self::assertSame('Support', $edit->header->intro);
        self::assertSame('/nowo_contact_form_admin_index', $edit->cancelHref);
    }

    public function testFieldIndexAddsTheConsentPseudoRow(): void
    {
        $page = PageFixtures::fieldIndex()['page'];

        self::assertInstanceOf(ListPage::class, $page);
        self::assertCount(3, $page->rows);
        self::assertSame(['consent' => true, 'name' => ContactFormPageFactory::CONSENT_ROW], $page->rows[2]);
        self::assertSame(['edit'], array_map(static fn (Action $action): string => $action->kind, $page->rowActions($page->rows[2], 2)));
        self::assertSame(['edit', 'delete'], array_map(static fn (Action $action): string => $action->kind, $page->rowActions($page->rows[0], 0)));

        $empty = PageFixtures::fieldIndex(true)['page'];
        self::assertSame([], $empty->rows);
    }

    public function testFieldWizardStepsLinkToTheirUrls(): void
    {
        $new  = PageFixtures::fieldWizard('definition')['page'];
        $edit = PageFixtures::fieldWizard('content', true)['page'];

        self::assertInstanceOf(WizardPage::class, $new);
        self::assertSame('definition', $new->current);
        self::assertSame(['definition', 'select_options', 'content'], array_map(static fn (WizardStep $step): string => $step->id, $new->steps));
        self::assertSame('/nowo_contact_form_fields_new?formId=1&step=definition', $new->steps[0]->href);

        self::assertInstanceOf(WizardPage::class, $edit);
        self::assertSame('/nowo_contact_form_fields_edit?formId=1&id=1&step=content', $edit->steps[2]->href);
        self::assertSame('done', $edit->status($edit->steps[0]));
        self::assertNotNull($edit->form);
    }

    public function testSubmissionPages(): void
    {
        $index = PageFixtures::submissionIndex()['page'];
        self::assertInstanceOf(ListPage::class, $index);
        self::assertSame(['view', 'delete'], array_map(static fn (Action $action): string => $action->kind, $index->rowActions($index->rows[0], 0)));
        self::assertSame(['formId' => 1], $index->pagination?->routeParams);
        self::assertSame([], PageFixtures::submissionIndex(true)['page']->rows);

        $show = PageFixtures::submissionShow()['page'];
        self::assertInstanceOf(ShowPage::class, $show);
        self::assertSame(['metadata', 'values'], array_map(static fn (DetailSection $section): ?string => $section->id, $show->sections));
        self::assertCount(4, $show->sections[0]->items, 'date, client, locale, consent');
        self::assertSame('ada@example.com', $show->sections[0]->items[1]->value);
        self::assertSame('delete', $show->header->actions[0]->kind);

        $anonymous = PageFixtures::submissionShow(true)['page'];
        self::assertInstanceOf(Label::class, $anonymous->sections[0]->items[1]->value);
    }

    public function testPublicHeaderAndLayout(): void
    {
        $context = PageFixtures::publicForm();
        self::assertSame('Contact us', $context['header']->title);
        self::assertSame('We answer within one business day.', $context['header']->intro);
        self::assertNull($context['page_layout']);

        $factory = PageFixtures::factory();
        self::assertNull($factory->publicHeader((new ContactFormTranslation())->setTitle('Hi'))->intro);
        self::assertSame(ContactFormPages::TEMPLATES['submission_show'], $factory->template('submission_show'));
        self::assertSame(['a' => 1, 'nowo_contact_form_legacy_layout' => null, 'nowo_contact_form_legacy_blocks' => []], $factory->context(['a' => 1]));
    }
}
