# Templates

Pages of ContactFormBundle on the UiKit page contract (REQ-UI-003, UiKit 2.15).
`Nowo\ContactFormBundle\Ui\ContactFormPageFactory` builds the UiKit view models (`Nowo\UiKitBundle\View\*`) for the
controllers; `Nowo\ContactFormBundle\Ui\ContactFormPages` resolves the layouts (`web_ui.layout_template`, null =
`nowo_ui_kit.layouts.admin`; `web_ui.public_layout_template`, null = `nowo_ui_kit.layouts.guest`) and the per-page
overrides (`web_ui.templates.<page>`). Admin templates extend a UiKit scaffold and fill data blocks only; the public
contact form is a unique page on the guest layout. Contract test: `tests/Integration/Contract/PageContractTest.php`
(UiKit `PageContractTestCase`, strict missing-translation rule). Twig namespace: `@NowoContactFormBundle` (views in
`src/Resources/views/`).

## Table of contents

- [Pages](#pages)
- [Unique pages](#unique-pages)
- [Per-page overrides](#per-page-overrides)
- [Overriding templates (REQ-TWIG-001)](#overriding-templates-req-twig-001)
- [Bundle partials](#bundle-partials)
- [Translation tabs](#translation-tabs)
- [Admin interface language](#admin-interface-language)
- [Layout bridge (1.x layouts)](#layout-bridge-1x-layouts)
- [Allowed exceptions](#allowed-exceptions)

## Pages

Every view model passes `bundle: 'NowoContactFormBundle'` (theme exception
`nowo_ui_kit.theme.bundles.NowoContactFormBundle`) and `layout: ContactFormPages::layout()`. Labels are
`NowoContactFormBundle` translation keys (`ContactFormPages::label()`). Delete actions are POST + CSRF-only
`FormView`s (FormKit `CsrfOnlyFormFactory`, field `_token`) behind the UiKit confirm `<dialog>`.

| Page (`web_ui.templates` id) | Template | Scaffold | View model | Data blocks filled |
| --- | --- | --- | --- | --- |
| Contact forms (`form_index`) | `admin/form/index.html.twig` | `page/list` | `ListPage` (header `admin.form.index_title`, action New form; columns name, slug, enabled (priority 2); row actions Edit, Fields, Submissions, Delete; `Pagination` (`web_ui.list_page_size`); `EmptyState` `admin.form.empty` with New form; `data-testid="contact-form-forms"`) | `nowo_ui_list_row` (slug on the `code` slot, enabled on `badge_success` / `badge_neutral`) |
| Contact form new / edit (`form_form`) | `admin/form/form.html.twig` | `page/form` | `FormPage` (header `admin.form.new_title` / `admin.form.edit_title`, intro = form name on edit, back link to the list; `ContactFormType`; submit `admin.form.save`, cancel = list; on edit the Fields / Submissions secondary actions and the delete zone; `data-testid="contact-form-form"`) | `nowo_ui_form_body` (REQ-TWIG-003 child loop, then the translation tabs) |
| Fields (`field_index`) | `admin/field/index.html.twig` | `page/list` | `ListPage` (header `admin.field.index_title`, intro = form name, action New field, back link to the forms; columns name, type, required (priority 2); row actions Edit / Delete; when the form requires GDPR consent, a read-only `gdpr_consent` pseudo row (`{consent: true, name}`) with an Edit form action; `EmptyState` `admin.field.empty` with New field; `data-testid="contact-form-fields"`) | `nowo_ui_list_row` (type label `admin.field.type.<type>` on `badge_neutral`, consent row on `badge_info`) |
| Field wizard (`field_form`) | `admin/field/form.html.twig` | `page/wizard` | `WizardPage` (header `admin.field.new_title` / `admin.field.edit_title`, intro = form name, back link to the fields; one `WizardStep` per visible step of the Symfony FormFlow `ContactFormFieldFlowType` — definition, select_options (select fields), phone_prefixes (phone fields), content — with its URL (done steps link back); `current` = flow step; `form` = flow `FormView`; `data-testid="contact-form-field-wizard"`) | `nowo_ui_wizard_step` (current step: REQ-TWIG-003 child loop + translation tabs; flow navigator previous / next / finish on the `btn_*` slots and a back link) |
| Submissions (`submission_index`) | `admin/submission/index.html.twig` | `page/list` | `ListPage` (header `admin.submission.index_title`, intro = form name, back link to the forms; columns date, client, locale (priority 2); row actions View / Delete; `Pagination`; `EmptyState` `admin.submission.empty`; `data-testid="contact-form-submissions"`) | `nowo_ui_list_row` (anonymous client on the `muted` slot, locale on `badge_neutral`) |
| Submission (`submission_show`) | `admin/submission/show.html.twig` | `page/show` | `ShowPage` (header `admin.submission.show_title`, intro = form name, action Delete submission, back link to the submissions; sections `metadata` (date, client, locale, consent date) and `values` (field name => value); `data-testid="contact-form-submission"`) | — (the scaffold renders the sections) |

The controllers also pass `contactForm` (field / submission pages, form page), `field`, `isNew`, `currentStep` (field
wizard) and `submission` (submission page) for host templates set through `web_ui.templates.<page>`.

## Unique pages

The public contact form is the host's front page, not an admin screen: it extends `web_ui.public_layout_template`
(`page_layout`), else the UiKit guest layout (`nowo_ui_layout.guest`), sets `nowo_ui_bundle` and fills
`nowo_ui_document_title` (translated title), `nowo_ui_page_header` (`nowo_ui_page_header(header)`: title +
description, breadcrumbs mode `own`) and `nowo_ui_content` (the form in the `form_panel` slot, submit on
`btn_primary`). The flashes (success message after a submission) come from the layout.

- `public/contact_form.html.twig` — public contact form (`/{_locale}/contact/{slug}`, id `public_form`); variables
  `header` (`View\PageHeader`), `page_layout`, `contactForm`, `translation`, `form`;
  `data-testid="contact-form-public"`.

## Per-page overrides

- `nowo_contact_form.web_ui.templates.<page>` (ids in the [Pages](#pages) table and `public_form`): a host template
  that extends `@!NowoContactFormBundle/<template>` and overrides a data block (`nowo_ui_list_row`,
  `nowo_ui_form_body`, `nowo_ui_wizard_step`, `nowo_ui_content`, …). Unknown page ids fail the container compilation.
  Prefer theming the UiKit slots (`nowo_ui_kit.theme`) and scaffolds once for every bundle.
- Record such overrides in the application's override inventory.

## Overriding templates (REQ-TWIG-001)

Any bundle template can be replaced by a file at `templates/bundles/NowoContactFormBundle/<subpath>` (same relative
path as under `src/Resources/views/`). `TwigPathsPass` registers the application directory first (`prependPath`)
and the bundle views after it (`addPath`), so **an application file at the override path always wins**. The paths are
registered whenever the bundle is enabled (the public form and the admin share them; `web_ui.enabled: false` only
disables the admin routes — see [CONFIGURATION](CONFIGURATION.md)).

**Freeze rule.** An application file at the override path always wins and will not pick up vendor changes for that
path until you remove or merge it: after `composer update` the application keeps rendering its own copy.
Upgrade-safe customisation, in this order:

| Depth | How | On a ContactFormBundle upgrade |
| --- | --- | --- |
| Preferred — no Twig fork | `nowo_ui_kit.layouts.{admin,guest}` (or `web_ui.layout_template` / `public_layout_template`), `nowo_ui_kit.theme` (class / attribute maps, tokens, `theme.bundles.NowoContactFormBundle`), translations (`translations/NowoContactFormBundle.<locale>.yaml`), the FormKit profile `contact_form`, CSS on `nowo-ui-*` / `nowo-contact-form-*` / `--nowo-ui-*` | Vendor templates and fixes apply automatically |
| Surgical | `web_ui.templates.<page>` with a thin `{% extends '@!NowoContactFormBundle/...' %}` overriding one data block (with `{{ parent() }}` where possible), or a single partial (`public/_form_fields.html.twig`, `admin/form/_translation_tabs.html.twig`, …) | Only that block / partial is frozen |
| Full-file override | Copy a page into `templates/bundles/NowoContactFormBundle/<subpath>` | That `<subpath>` is frozen until removed or merged |

**Procedure.**

1. Find the `<subpath>` in the table below (path relative to `src/Resources/views/`).
2. Create `templates/bundles/NowoContactFormBundle/<subpath>` in the application (same file name).
3. Clear the cache in dev if needed: `php bin/console cache:clear`.
4. Treat the file as a fork: it does not change when the bundle ships a new version of that `<subpath>`.

Example: to restyle only the public form body, create
`templates/bundles/NowoContactFormBundle/public/_form_fields.html.twig`.

**Recovering upstream after a freeze.** Delete (or rename aside) the application file, clear the Twig cache
(`php bin/console cache:clear`), then re-apply only surgical customisations if still needed. Diff your copy against
the new vendor file under `vendor/nowo-tech/contact-form-bundle/src/Resources/views/<subpath>` before re-copying
anything.

**Overridable subpaths.**

| `<subpath>` | Purpose |
| --- | --- |
| `admin/form/index.html.twig` | Contact forms list (list scaffold) |
| `admin/form/form.html.twig` | Contact form create / edit (form scaffold) |
| `admin/form/_translation_tabs.html.twig` | Locale tabs of the contact form translations |
| `admin/field/index.html.twig` | Fields of a contact form (list scaffold) |
| `admin/field/form.html.twig` | Field create / edit wizard (wizard scaffold over the FormFlow) |
| `admin/field/_translation_tabs.html.twig` | Locale tabs of the field translations (content step) |
| `admin/submission/index.html.twig` | Submissions of a contact form (list scaffold) |
| `admin/submission/show.html.twig` | Submission detail (show scaffold) |
| `admin/_legacy_layout.html.twig` | Deprecated 1.x layout bridge |
| `public/contact_form.html.twig` | Public contact form page |
| `public/_form_fields.html.twig` | Public form body: child loop + GDPR consent and privacy policy link |
| `form/contact_phone.html.twig` | Form theme of `ContactPhoneType` (prefix + number in `.nowo-contact-form-phone`, `role="group"`) |

Renaming or removing a `<subpath>` or a data block listed here is a breaking change (see [UPGRADING](UPGRADING.md)).

## Bundle partials

| Partial | Role |
| --- | --- |
| `admin/form/_translation_tabs.html.twig`, `admin/field/_translation_tabs.html.twig` | Translation tabs (see below); variables `form`, optional `bundle` |
| `public/_form_fields.html.twig` | Public form body; variables `form`, `contactForm`, `translation`, optional `bundle` |
| `form/contact_phone.html.twig` | `contact_phone_widget` block (framework neutral; lay the two controls out with CSS on `.nowo-contact-form-phone`, e.g. `display: flex; gap: .5rem`) |

The bundle ships no layout, no local pagination, flash, confirm, modal, page header or empty-state partial and no
inline script: the scaffolds and the UiKit partials (`action`, `confirm`, `empty`, `flashes`, `pagination`,
`page_header`, `scripts`) render them, and the host layout loads the CSS framework.

## Translation tabs

| Template | Page | Tabs id |
| --- | --- | --- |
| `admin/form/_translation_tabs.html.twig` | contact form new / edit (`form.translations`, `ContactFormTranslationType` entries) | `form-translation` |
| `admin/field/_translation_tabs.html.twig` | field wizard, content step (`stepForm.translations`, `ContactFormFieldTranslationType` entries) | `field-translation` |

Both take `form` (the form holding `translations`) and include FormKit's `@NowoFormKitBundle/form/_locale_tabs.html.twig`
in mode `entries`: `default_locale` = `contact_form_admin_default_locale()` (`kernel.default_locale` when enabled, else
the first enabled locale), `required_locales` = every enabled locale (title / label are required), tab names from
`nowo_contact_form.admin.locales.<code>`. FormKit renders through UiKit's `locale_tabs` partial: root `#<tabs id>`,
buttons `#tab-<tabs id>-<locale>`, panels `#tab-<tabs id>-<locale>-panel`, theme slots `tabs`, `tab`, `tab_active`,
`tab_panel`. The fieldset uses the `form_row` / `form_label` slots; without translations the partial prints the
`translations_empty` message on the `muted` slot.

## Admin interface language

The 1.x layout rendered a locale switch in its navbar. Since 1.6 the host layout owns the chrome: link to
`nowo_contact_form_admin_switch_locale` (`/locale/{locale}` under the admin prefix, stored in the session and applied
by `ContactFormAdminLocaleSubscriber`; it redirects back to a safe admin referer) from your own locale switcher — e.g.
UiKit's `locale_switcher` partial — with `contact_form_admin_locales()` as the list of locales.

## Layout bridge (1.x layouts)

`web_ui.layout_template` may still point at a 1.x layout (no `nowo_ui_page_header` block, e.g. a copy of the removed
`admin/layout.html.twig` rendering `content`): `ContactFormPages` then wraps it in `admin/_legacy_layout.html.twig`
(maps the canonical blocks into `content`, `title`, `stylesheets`, `javascripts`) and triggers a deprecation.
Canonical blocks the 1.x layout renders itself (`nowo_ui_flashes`, `nowo_ui_content`, `nowo_ui_modals`, …) are not
printed twice; when it renders `nowo_ui_flashes`, the page header is printed there. The value
`@NowoContactFormBundle/admin/layout.html.twig` (the 1.x default) is mapped to null. The bridge is removed in 2.0.

## Allowed exceptions

- (none) — the contract test declares no `allowedClasses()`.
