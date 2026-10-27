<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Ui;

use DateTimeImmutable;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\BackLink;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\DetailItem;
use Nowo\UiKitBundle\View\DetailSection;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\Pagination;
use Nowo\UiKitBundle\View\ShowPage;
use Nowo\UiKitBundle\View\WizardPage;
use Nowo\UiKitBundle\View\WizardStep;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function is_string;

/**
 * Builds the UiKit view models of the bundle pages (REQ-UI-003) from the controller data: page headers (title, intro,
 * actions, back link), columns, row actions (POST + CSRF-only FormViews behind a Confirm for deletes), pagination,
 * empty states, detail sections and wizard steps. The controllers and the page contract test share it, so the test
 * renders exactly the view models the application gets. Stateless: safe in worker mode.
 */
final readonly class ContactFormPageFactory
{
    /** Pseudo row of the field list for the form-level GDPR consent checkbox (not a ContactFormField). */
    public const CONSENT_ROW = 'gdpr_consent';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private ContactFormPages $pages = new ContactFormPages(),
    ) {
    }

    /**
     * Template of a page: the `web_ui.templates.<page>` override, else the bundle template.
     */
    public function template(string $page): string
    {
        return $this->pages->template($page);
    }

    /**
     * Admin page variables plus the shared ones (legacy layout bridge).
     *
     * @param array<string, mixed> $vars
     *
     * @return array<string, mixed>
     */
    public function context(array $vars): array
    {
        return $this->pages->context($vars);
    }

    /**
     * Contact forms list.
     *
     * @param list<ContactForm> $forms
     * @param array<int, FormView> $deleteForms contact form id => CSRF-only delete form
     */
    public function formIndex(array $forms, Pagination $pagination, array $deleteForms): ListPage
    {
        $newAction = Action::create($this->url('nowo_contact_form_admin_new'), ContactFormPages::label('nowo_contact_form.admin.form.new'));
        $actions   = [];
        foreach ($forms as $form) {
            $id           = (int) $form->getId();
            $actions[$id] = [
                Action::edit($this->url('nowo_contact_form_admin_edit', ['id' => $id]), ContactFormPages::label('nowo_contact_form.admin.actions.edit')),
                new Action('custom', ContactFormPages::label('nowo_contact_form.admin.actions.fields'), $this->url('nowo_contact_form_fields_index', ['formId' => $id]), icon: 'list'),
                new Action('custom', ContactFormPages::label('nowo_contact_form.admin.actions.submissions'), $this->url('nowo_contact_form_submissions_index', ['formId' => $id]), icon: 'inbox'),
            ];
            if (isset($deleteForms[$id])) {
                $actions[$id][] = $this->deleteAction($deleteForms[$id], 'form');
            }
        }

        return new ListPage(
            header: new PageHeader(ContactFormPages::label('nowo_contact_form.admin.form.index_title'), actions: [$newAction]),
            columns: [
                new Column('name', ContactFormPages::label('nowo_contact_form.admin.form.columns.name'), required: true),
                new Column('slug', ContactFormPages::label('nowo_contact_form.admin.form.columns.slug')),
                new Column('enabled', ContactFormPages::label('nowo_contact_form.admin.form.columns.enabled'), priority: 2),
            ],
            rows: $forms,
            rowActions: static fn (ContactForm $row): array => $actions[(int) $row->getId()] ?? [],
            pagination: $pagination,
            empty: new EmptyState(null, ContactFormPages::label('nowo_contact_form.admin.form.empty'), $newAction),
            bundle: ContactFormPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'contact-form-forms'],
            actionsLabel: ContactFormPages::label('nowo_contact_form.admin.form.columns.actions'),
        );
    }

    /**
     * Contact form create / edit (the delete zone and the links to its fields / submissions on edit).
     */
    public function formForm(FormView $form, ContactForm $contactForm, bool $isNew, ?FormView $deleteForm = null): FormPage
    {
        $indexUrl  = $this->url('nowo_contact_form_admin_index');
        $id        = (int) $contactForm->getId();
        $secondary = $isNew ? [] : [
            new Action('custom', ContactFormPages::label('nowo_contact_form.admin.actions.fields'), $this->url('nowo_contact_form_fields_index', ['formId' => $id]), icon: 'list'),
            new Action('custom', ContactFormPages::label('nowo_contact_form.admin.actions.submissions'), $this->url('nowo_contact_form_submissions_index', ['formId' => $id]), icon: 'inbox'),
        ];

        return new FormPage(
            header: new PageHeader(
                ContactFormPages::label($isNew ? 'nowo_contact_form.admin.form.new_title' : 'nowo_contact_form.admin.form.edit_title'),
                $isNew ? null : $contactForm->getName(),
                back: new BackLink($indexUrl, ContactFormPages::label('nowo_contact_form.admin.back'), 'contact-form-back'),
            ),
            form: $form,
            submitLabel: ContactFormPages::label('nowo_contact_form.admin.form.save'),
            cancelHref: $indexUrl,
            cancelLabel: ContactFormPages::label('nowo_contact_form.admin.back'),
            secondaryActions: $secondary,
            deleteAction: $deleteForm instanceof FormView ? $this->deleteAction($deleteForm, 'form') : null,
            bundle: ContactFormPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'contact-form-form'],
        );
    }

    /**
     * Fields of a contact form (plus the form-level GDPR consent checkbox as a read-only pseudo row).
     *
     * @param list<ContactFormField> $fields
     * @param array<int, FormView> $deleteForms field id => CSRF-only delete form
     */
    public function fieldIndex(ContactForm $contactForm, array $fields, array $deleteForms): ListPage
    {
        $formId    = (int) $contactForm->getId();
        $newAction = Action::create($this->url('nowo_contact_form_fields_new', ['formId' => $formId, 'step' => 'definition']), ContactFormPages::label('nowo_contact_form.admin.field.new'));
        $actions   = [];
        foreach ($fields as $field) {
            $id           = (int) $field->getId();
            $actions[$id] = [Action::edit($this->url('nowo_contact_form_fields_edit', ['formId' => $formId, 'id' => $id, 'step' => 'definition']), ContactFormPages::label('nowo_contact_form.admin.actions.edit'))];
            if (isset($deleteForms[$id])) {
                $actions[$id][] = $this->deleteAction($deleteForms[$id], 'field');
            }
        }

        $rows = $fields;
        if ($contactForm->isRequireConsent()) {
            $rows[] = ['consent' => true, 'name' => self::CONSENT_ROW];
        }
        $consentActions = [Action::edit($this->url('nowo_contact_form_admin_edit', ['id' => $formId]), ContactFormPages::label('nowo_contact_form.admin.field.consent_row.edit'))];

        return new ListPage(
            header: new PageHeader(
                ContactFormPages::label('nowo_contact_form.admin.field.index_title'),
                $contactForm->getName(),
                [$newAction],
                back: $this->backToForms(),
            ),
            columns: [
                new Column('name', ContactFormPages::label('nowo_contact_form.admin.field.columns.name'), required: true),
                new Column('type', ContactFormPages::label('nowo_contact_form.admin.field.columns.type')),
                new Column('required', ContactFormPages::label('nowo_contact_form.admin.field.columns.required'), priority: 2),
            ],
            rows: $rows,
            rowActions: static fn (ContactFormField|array $row): array => $row instanceof ContactFormField ? $actions[(int) $row->getId()] ?? [] : $consentActions,
            empty: new EmptyState(null, ContactFormPages::label('nowo_contact_form.admin.field.empty'), $newAction),
            bundle: ContactFormPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'contact-form-fields'],
            actionsLabel: ContactFormPages::label('nowo_contact_form.admin.field.columns.actions'),
        );
    }

    /**
     * Field wizard (Symfony FormFlow): one step per visible flow step, the done steps link back to their URL.
     *
     * @param list<string> $steps visible step names, in order
     */
    public function fieldWizard(ContactForm $contactForm, ?int $fieldId, FormView $form, array $steps, string $current): WizardPage
    {
        $formId     = (int) $contactForm->getId();
        $isNew      = $fieldId === null;
        $route      = $isNew ? 'nowo_contact_form_fields_new' : 'nowo_contact_form_fields_edit';
        $params     = $isNew ? ['formId' => $formId] : ['formId' => $formId, 'id' => $fieldId];
        $indexUrl   = $this->url('nowo_contact_form_fields_index', ['formId' => $formId]);
        $stepModels = [];
        foreach ($steps as $step) {
            $stepModels[] = new WizardStep($step, ContactFormPages::label('nowo_contact_form.admin.field.flow.steps.' . $step), $this->url($route, $params + ['step' => $step]));
        }

        return new WizardPage(
            header: new PageHeader(
                ContactFormPages::label($isNew ? 'nowo_contact_form.admin.field.new_title' : 'nowo_contact_form.admin.field.edit_title'),
                $contactForm->getName(),
                back: new BackLink($indexUrl, ContactFormPages::label('nowo_contact_form.admin.back'), 'contact-form-back'),
            ),
            steps: $stepModels,
            current: $current,
            form: $form,
            backHref: $indexUrl,
            nextLabel: ContactFormPages::label('nowo_contact_form.admin.field.flow.next'),
            backLabel: ContactFormPages::label('nowo_contact_form.admin.back'),
            bundle: ContactFormPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'contact-form-field-wizard'],
        );
    }

    /**
     * Submissions of a contact form.
     *
     * @param list<ContactSubmission> $submissions
     * @param array<int, FormView> $deleteForms submission id => CSRF-only delete form
     */
    public function submissionIndex(ContactForm $contactForm, array $submissions, Pagination $pagination, array $deleteForms): ListPage
    {
        $formId  = (int) $contactForm->getId();
        $actions = [];
        foreach ($submissions as $submission) {
            $id           = (int) $submission->getId();
            $actions[$id] = [Action::view($this->url('nowo_contact_form_submissions_show', ['formId' => $formId, 'id' => $id]), ContactFormPages::label('nowo_contact_form.admin.actions.view'))];
            if (isset($deleteForms[$id])) {
                $actions[$id][] = $this->deleteAction($deleteForms[$id], 'submission');
            }
        }

        return new ListPage(
            header: new PageHeader(
                ContactFormPages::label('nowo_contact_form.admin.submission.index_title'),
                $contactForm->getName(),
                back: $this->backToForms(),
            ),
            columns: [
                new Column('date', ContactFormPages::label('nowo_contact_form.admin.submission.columns.date'), required: true),
                new Column('client', ContactFormPages::label('nowo_contact_form.admin.submission.columns.client')),
                new Column('locale', ContactFormPages::label('nowo_contact_form.admin.submission.columns.locale'), priority: 2),
            ],
            rows: $submissions,
            rowActions: static fn (ContactSubmission $row): array => $actions[(int) $row->getId()] ?? [],
            pagination: $pagination,
            empty: new EmptyState(null, ContactFormPages::label('nowo_contact_form.admin.submission.empty')),
            bundle: ContactFormPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'contact-form-submissions'],
            actionsLabel: ContactFormPages::label('nowo_contact_form.admin.submission.columns.actions'),
        );
    }

    /**
     * Submission detail: metadata and submitted values as detail sections, delete in the header (confirm dialog).
     */
    public function submissionShow(ContactForm $contactForm, ContactSubmission $submission, FormView $deleteForm): ShowPage
    {
        $metadata = [
            new DetailItem(ContactFormPages::label('nowo_contact_form.admin.submission.columns.date'), $submission->getCreatedAt()->format('Y-m-d H:i:s')),
            new DetailItem(
                ContactFormPages::label('nowo_contact_form.admin.submission.columns.client'),
                $submission->isAnonymous() ? ContactFormPages::label('nowo_contact_form.admin.submission.anonymous') : ($submission->getClientLabel() ?? $submission->getClientId()),
            ),
            new DetailItem(ContactFormPages::label('nowo_contact_form.admin.submission.columns.locale'), $submission->getLocale()),
        ];
        $consentAt = $submission->getConsentGivenAt();
        if ($consentAt instanceof DateTimeImmutable) {
            $metadata[] = new DetailItem(ContactFormPages::label('nowo_contact_form.admin.submission.consent_at'), $consentAt->format('Y-m-d H:i:s'));
        }

        $values = [];
        foreach ($submission->getValues() as $value) {
            $values[] = new DetailItem((string) $value->getFieldName(), $value->getValue());
        }

        return new ShowPage(
            header: new PageHeader(
                ContactFormPages::label('nowo_contact_form.admin.submission.show_title'),
                $contactForm->getName(),
                [$this->deleteAction($deleteForm, 'submission', ContactFormPages::label('nowo_contact_form.admin.submission.delete'))],
                back: new BackLink(
                    $this->url('nowo_contact_form_submissions_index', ['formId' => (int) $contactForm->getId()]),
                    ContactFormPages::label('nowo_contact_form.admin.back'),
                    'contact-form-back',
                ),
            ),
            sections: [
                new DetailSection(ContactFormPages::label('nowo_contact_form.admin.submission.metadata_title'), $metadata, 'metadata'),
                new DetailSection(ContactFormPages::label('nowo_contact_form.admin.submission.values_title'), $values, 'values'),
            ],
            bundle: ContactFormPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'contact-form-submission'],
        );
    }

    /**
     * Page header of the public contact form: its translated title and description.
     */
    public function publicHeader(ContactFormTranslation $translation): PageHeader
    {
        $description = $translation->getDescription();

        return new PageHeader($translation->getTitle(), is_string($description) && $description !== '' ? $description : null, breadcrumbsMode: 'own');
    }

    /**
     * Layout of the public contact form page (null = UiKit `nowo_ui_layout.guest`).
     */
    public function publicLayout(): ?string
    {
        return $this->pages->publicLayout();
    }

    /**
     * Delete action (POST + CSRF-only FormView) behind the UiKit confirm dialog.
     *
     * @param 'field'|'form'|'submission' $subject
     */
    private function deleteAction(FormView $form, string $subject, ?Label $label = null): Action
    {
        $action = $form->vars['action'] ?? '';

        return Action::delete(
            is_string($action) ? $action : '',
            null,
            new Confirm(
                ContactFormPages::label('nowo_contact_form.admin.' . $subject . '.delete_title'),
                ContactFormPages::label('nowo_contact_form.admin.' . $subject . '.delete_confirm'),
                ContactFormPages::label('nowo_contact_form.admin.actions.delete'),
            ),
            $label ?? ContactFormPages::label('nowo_contact_form.admin.actions.delete'),
            $form,
        );
    }

    private function backToForms(): BackLink
    {
        return new BackLink($this->url('nowo_contact_form_admin_index'), ContactFormPages::label('nowo_contact_form.admin.back'), 'contact-form-back');
    }

    /**
     * @param array<string, mixed> $params
     */
    private function url(string $route, array $params = []): string
    {
        return $this->urlGenerator->generate($route, $params);
    }
}
