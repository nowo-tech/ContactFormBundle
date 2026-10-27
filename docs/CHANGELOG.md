# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.5.1] - 2026-10-24](#151---2026-10-24)
- [[1.5.0] - 2026-10-21](#150---2026-10-21)
- [[1.4.1] - 2026-10-20](#141---2026-10-20)
- [[1.4.0] - 2026-10-19](#140---2026-10-19)
- [[1.3.2] - 2026-10-18](#132---2026-10-18)
- [[1.3.1] - 2026-10-13](#131---2026-10-13)
- [[1.3.0] - 2026-10-13](#130---2026-10-13)
- [[1.2.0] - 2026-10-13](#120---2026-10-13)
- [[1.1.3] - 2026-10-13](#113---2026-10-13)
- [[1.1.2] - 2026-10-09](#112---2026-10-09)
- [[1.1.1] - 2026-10-09](#111---2026-10-09)
- [[1.1.0] - 2026-09-28](#110---2026-09-28)
- [[1.0.23] - 2026-09-27](#1023---2026-09-27)
- [[1.0.22] - 2026-09-24](#1022---2026-09-24)
- [[1.0.21] - 2026-08-24](#1021---2026-08-24)
- [[1.0.20] - 2026-08-20](#1020---2026-08-20)
- [[1.0.19] - 2026-08-20](#1019---2026-08-20)
- [[1.0.18] - 2026-08-19](#1018---2026-08-19)
- [[1.0.17] - 2026-08-19](#1017---2026-08-19)
- [[1.0.16] - 2026-08-19](#1016---2026-08-19)
- [[1.0.15] - 2026-08-18](#1015---2026-08-18)
- [[1.0.14] - 2026-08-04](#1014---2026-08-04)
- [[1.0.13] - 2026-07-30](#1013---2026-07-30)
- [[1.0.12] - 2026-07-29](#1012---2026-07-29)
- [[1.0.11] - 2026-07-28](#1011---2026-07-28)
- [[1.0.10] - 2026-07-27](#1010---2026-07-27)
- [[1.0.9] - 2026-07-27](#109---2026-07-27)
- [[1.0.8] - 2026-07-24](#108---2026-07-24)
- [[1.0.7] - 2026-07-22](#107---2026-07-22)
- [[1.0.6] - 2026-07-22](#106---2026-07-22)
- [[1.0.5] - 2026-07-20](#105---2026-07-20)
- [[1.0.4] - 2026-07-20](#104---2026-07-20)
- [[1.0.3] - 2026-07-20](#103---2026-07-20)
- [[1.0.2] - 2026-07-13](#102---2026-07-13)
- [[1.0.1] - 2026-07-13](#101---2026-07-13)
- [[1.0.0] - 2026-07-13](#100---2026-07-13)

## [Unreleased]

### Security

- **Open redirect in the admin locale switch (CWE-601, affects 1.0.0–1.5.1).** `{prefix}/locale/{locale}?redirect=`
  accepted any value starting with `/` but not `//`: `/%5Cevil.example/`, `/%09/evil.example/` or `%5C%5Cevil.example`
  were followed by browsers to another host. The `redirect` parameter and the same-host `Referer` fallback now go
  through `Security\SafeRelativePath` (single leading `/`, no backslash / whitespace / control character, inside
  `web_ui.path_prefix`).
- The admin access guard keys on the admin controller classes, not only on route names (a host route `name_prefix`,
  fragments and sub-requests are now guarded).
- The admin UI refuses to compile without form CSRF protection and a CSRF token manager; `symfony/security-csrf` moves
  to `require`. An empty `security.access_roles` with the default checker, and `security.allow_unauthenticated` in the
  `prod` environment, fail the container compilation.
- Privacy policy URL: `http(s)` only (`Url` constraint, `https` default protocol); a stored `javascript:` / `data:` value
  is no longer rendered as a link.
- Retention: `retentionDays` is validated (0 to 3650) and the cleanup skips forms with 0 or a negative value (a
  negative value used to make every submission expire).
- Public form: values are length-capped (`public_field_max_length`, text 255, textarea 10 000, phone 32), every POST
  counts against the rate limit (valid or not), and `public_submission_rate_limit.limiter` accepts a
  `symfony/rate-limiter` factory for atomic counting.
- Consent-label sanitizer drops comments, processing instructions and CDATA and rejects `/\host` links.
- Field wizard: a tampered hidden field type is a validation error (no 500); field names must match
  `^[a-z][a-z0-9_]*$` and cannot be `gdpr_consent` / `_token`; file fields are refused without a
  `file_upload.service`; the notification e-mail is validated; admin name / slug / URL lengths match the columns.
- Flex recipe: the separate `security_nowo_contact_form.yaml` (a second `access_control` list, refused by Symfony) is
  replaced by a post-install note.

### Added

- **UiKit page contract (REQ-UI-003).** The admin pages extend the UiKit scaffolds — `page/list` (contact forms,
  fields, submissions), `page/form` (contact form), `page/wizard` (field wizard over the Symfony FormFlow) and
  `page/show` (submission) — and fill data blocks only; `Ui\ContactFormPageFactory` builds the view models (page
  headers with back links, columns, row actions, `Pagination`, `EmptyState`, detail sections, wizard steps) and
  `Ui\ContactFormPages` resolves the layouts and per-page templates. The public contact form is a unique page on the
  UiKit guest layout. Contract test `tests/Integration/Contract/PageContractTest.php` (UiKit `PageContractTestCase`,
  strict missing-translation rule) and `LegacyLayoutBridgeTest`.
- Delete actions on the contact form and field lists, in the contact form delete zone and in the submission header,
  behind the UiKit confirm `<dialog>` (POST + CSRF-only FormKit form); the routes existed but had no button.
- Canonical configuration keys: `web_ui.path_prefix`, `web_ui.public_layout_template`, `web_ui.templates.<page>`
  (`form_index`, `form_form`, `field_index`, `field_form`, `submission_index`, `submission_show`, `public_form`).
- Demo: UiKit host layouts (`templates/layouts/`), seeded submissions inbox (`app:seed-contact-demo
  [--reset-submissions]`), Playwright e2e (`make test-e2e`, also in `release-check`) and WebP README screenshots
  (`make demo-screenshots`) — REQ-DEMO-013.
- Docs: `docs/TEMPLATES.md` (pages → scaffolds, unique page, per-page overrides, REQ-TWIG-001 freeze rule, upgrade-safe
  alternatives, recovery procedure and `<subpath>` table); USAGE / CONFIGURATION / INSTALLATION updated.

### Changed

- `web_ui.layout_template` defaults to `null` (= `nowo_ui_kit.layouts.admin`); the CSS stack, icon set and row-action
  display are inherited from `nowo_ui_kit`. The FormKit `contact_form` profile seed is framework neutral
  (`nowo-ui-input`, no row classes) and sets `convention_requires_translation: true`; the admin templates no longer
  force `bootstrap_5_layout.html.twig` — the host form theme applies.
- `ContactPhoneType` and its form theme carry no Bootstrap classes (`.nowo-contact-form-phone`, `role="group"`).
- `ContactPhoneInputAvailability` checks that the PhoneInputBundle `PhoneType` is a form type and accepts a forced
  answer (`new ContactPhoneInputAvailability(true|false)`); a phone field set to the Phone Input widget falls back to the
  Symfony prefix widget when the type is missing, instead of disappearing from the form.
- `nowo-tech/ui-kit-bundle` ^2.15.3, `nowo-tech/form-kit-bundle` ^2.13.3.
- PHPUnit: native deprecation gate (`failOnDeprecation`, `ignoreSuppressionOfDeprecations`,
  `ignoreIndirectDeprecations`), `failOnRisky` and `failOnWarning` (REQ-SF-005); the 21 risky integration tests (error
  handlers removed in `tearDown()`) are fixed.
- Coverage: no `<exclude>` left in `phpunit.xml.dist`; the gate is 100% PHP lines in `make test-coverage`,
  `composer coverage-check` and the CI coverage job (REQ-TEST-003).

### Deprecated

- `admin_route_prefix` (use `web_ui.path_prefix`), `web_ui.css_framework` / `web_ui.icon_set` (set `nowo_ui_kit.*`).
- A `web_ui.layout_template` that does not render the UiKit canonical blocks is wrapped in the
  `admin/_legacy_layout.html.twig` bridge with a deprecation (removed in 2.0).
- Twig globals `nowo_contact_form_layout_template`, `nowo_contact_form_css_framework`, `nowo_contact_form_icon_set`
  (the pages no longer read them).

### Removed

- Local chrome: `admin/layout.html.twig` (loaded Bootstrap from a CDN), `admin/base.html.twig` (inline locale-switch
  and `window.confirm()` script), `admin/_pagination.html.twig`, `admin/_locale_switch.html.twig`. The interface
  language switch moves to the host layout (route `nowo_contact_form_admin_switch_locale` unchanged).

### Fixed

- Field wizard: editing an existing field saved nothing (the FormFlow returns the session copy; it is now applied to
  the managed field), and the step data hooks never ran because Symfony does not dispatch `*_SET_DATA` on
  `inherit_data` steps — the select option labels per locale were missing, and editing a phone / select field showed
  empty prefixes / machine names. The flow now hands the field to each step (`ContactFormFieldStepDataInterface`).
- Contact form admin: a blank name, slug or title is a validation error instead of a 500 (`TypeError` on the setter).
- Placeholder convention keys (`contact_form.name.placeholder`, …) no longer leak as raw text into the admin forms.
- The admin interface language also applies under a custom `web_ui.path_prefix` (route-name match instead of the
  hard-coded `/admin/contact-forms` path).

#### Template contract

| Page | Template | Scaffold | Data blocks |
| --- | --- | --- | --- |
| Contact forms | `admin/form/index.html.twig` | `page/list` | `nowo_ui_list_row` |
| Contact form | `admin/form/form.html.twig` | `page/form` | `nowo_ui_form_body` |
| Fields | `admin/field/index.html.twig` | `page/list` | `nowo_ui_list_row` |
| Field wizard | `admin/field/form.html.twig` | `page/wizard` | `nowo_ui_wizard_step` |
| Submissions | `admin/submission/index.html.twig` | `page/list` | `nowo_ui_list_row` |
| Submission | `admin/submission/show.html.twig` | `page/show` | — |
| Public form (unique) | `public/contact_form.html.twig` | guest layout | `nowo_ui_page_header`, `nowo_ui_content` |

The page variable is `page` (UiKit view model); the 1.x variables `forms`, `fields`, `submissions`, `page` (int),
`pages`, `total`, `pageSize`, `form`, `flow`, `deleteForm` are gone (`contactForm`, `field`, `isNew`, `currentStep`,
`submission` remain).

## [1.5.1] - 2026-10-24

Bundle views render form bodies through the child loop (REQ-TWIG-003). See [UPGRADING](UPGRADING.md#to-151).

### Changed

- **Twig (REQ-TWIG-003):** `admin/form/form.html.twig` and the field wizard `admin/field/form.html.twig` render their fields through the child loop (`{% for child in form %}` + `form_row(child)`) instead of one `form_row`/`form_widget` per field (translations keep the locale tabs partial); `public/_form_fields.html.twig` skips already-rendered children. Demo: `icon_demo/_background_fragment.html.twig` uses the child loop.

## [1.5.0] - 2026-10-21

Dependency refresh, @final controllers/forms, Symfony-form delete button, coverage gate in test-coverage, CI static analysis and demo smoke. See [UPGRADING](UPGRADING.md#to-150).

### Changed

- Dependencies refreshed (Composer and pnpm/npm lock files of the bundle and its demos, campaign 2026-10-19).
- `.php-cs-fixer.dist.php`: `new ParallelConfig(2)` (REQ-CS-001).
- Controllers, form types and `Configuration` are marked `@final` (real `final` in 2.0): they are not extension points; decorate or replace the services instead (REQ-PHP-001). See [UPGRADING](UPGRADING.md).
- The submission delete button is rendered with a Symfony form (`form_start` / `form_end`, same `_token` id) instead of raw `<form>` / `<input>` markup (REQ-TWIG-005).
- `make test-coverage` prints the PHPUnit text summary, publishes `coverage-php.txt` and fails under 99% lines (`coverage-check` is now an alias); `release-check` starts with `ensure-up` and runs `check-no-raw-html-form` and `validate-translations`, which now also checks key parity (REQ-MAKE-002, REQ-MAKE-004, REQ-TEST-008).
- CI: static-analysis job (PHPStan, Rector dry run, Igor) and a `Demo smoke` workflow; `make demo-smoke` / demo `release-verify` require a final HTTP 200 (REQ-CS-006, REQ-TEST-011).
- `docs/CONFIGURATION.md` lists the supported locales (REQ-I18N-002); README coverage figure refreshed to 99.62% (REQ-TEST-003); `make help` follows the standard block order; the co-author check accepts git worktrees.

## [1.4.1] - 2026-10-20

Documentation: wave-1 REQ compliance (README, CONTRIBUTING, issue templates, Spec Kit inventory). See [UPGRADING](UPGRADING.md#to-141).

### Changed

- Documentation: wave-1 REQ compliance — canonical issue templates, README documentation list order (CI/PSR/Coverage under Additional documentation), CONTRIBUTING Cursor Agent queue settings (REQ-IDE-006), tables of contents on long docs, demo compose project name `contact-form-bundle-demo-symfony-8`, Spec Kit inventory refresh (94/94).

## [1.4.0] - 2026-10-19

Translation tabs use FormKit 2.13 locale_tabs through the UiKit 2.5 partial (default locale first, required and error markers) and the admin layout drops the Bootstrap CDN script; requires FormKit ^2.13 and UiKit ^2.5.1. See [UPGRADING](UPGRADING.md#to-140).

### Changed

- **Translation tabs** (contact form edit and field wizard content step) render through FormKit 2.13 `locale_tabs`
  (mode `entries`) and UiKit 2.5's `locale_tabs` partial instead of Bootstrap tabs: the default locale
  (`kernel.default_locale` when enabled) listed first and marked `★`, every locale marked `*` (title / label required),
  invalid tabs marked `!` and opened first, `aria-invalid` on invalid widgets, the locale child a hidden input,
  WAI-ARIA keyboard navigation, stacked fieldsets without JavaScript, theme slots `tabs` / `tab` / `tab_active` /
  `tab_panel`. Tabs were sorted alphabetically before. Translation entries use `error_bubbling: false`.
- `admin/layout.html.twig` no longer loads the Bootstrap JS bundle from the CDN; the admin locale menu (3+ locales) is
  a native `<details>` menu, and `admin/base.html.twig` loads the UiKit runtimes through UiKit's `scripts` partial.
- New Twig function `contact_form_admin_default_locale()`.
- Requires `nowo-tech/form-kit-bundle ^2.13` and `nowo-tech/ui-kit-bundle ^2.5.1` (UiKit 1.x is no longer supported).

## [1.3.2] - 2026-10-18

Allows nowo-tech/ui-kit-bundle 2.x (^1.4 || ^2.2) so hosts can move to UiKit 2.2. See [UPGRADING](UPGRADING.md#to-132).

### Changed

- Allows nowo-tech/ui-kit-bundle 2.x (`^1.4 || ^2.2`); the dev lock and the demo lock use 2.2.0. Templates are unchanged (compatibility release). With UiKit 2.x `nowo-ui.css` ships in `@layer nowo-ui`, so unlayered host/framework CSS wins equal-specificity conflicts with kit rules in the admin pages; to keep the 1.x cascade, override the admin `stylesheets` block and link `css/nowo-ui.unlayered.css` (UiKit 2.x only).

## [1.3.1] - 2026-10-13

### Changed

- `docs/SECURITY.md`: documents the file-upload limits (1.3.0), the default-on submission rate limit and the nested rich-text sanitizing fix (1.2.0).

## [1.3.0] - 2026-10-13

### Security

- File fields now carry a `File` constraint: max size `5M` and a document/image MIME allow-list by default (PDF, text, Word/ODT, JPEG/PNG/GIF/WebP; SVG/HTML excluded). Configurable via `file_upload.max_size` / `file_upload.mime_types` and per field via `options.max_size` / `options.mime_types`. Previously any file type and size reached the upload handler.

### Fixed

- Default admin access checker: `security.authorization_checker` is now injected through an optional reference instead of a `hasDefinition()` check in `load()` (always false in real apps, so the checker relied on autowiring and, with `allow_unauthenticated: true`, became allow-all even when SecurityBundle was installed). Without SecurityBundle the role checker denies (fail-closed); the allow-all checker is now selected in `ContactFormSecurityPass` only when `allow_unauthenticated: true` and SecurityBundle is absent.

## [1.2.0] - 2026-10-13

### Security

- Admin CRUD routes are now actually guarded: the admin access subscriber was never registered in real applications (the `services.yaml` exclusion left a placeholder definition that short-circuited `ContactFormSecurityPass`), so `security.access_roles` / custom access checkers were not enforced on admin routes. Keep host `access_control` on `admin_route_prefix` as defence in depth.
- Admin CRUD routes now return 404 when `web_ui.enabled: false`, even if the bundle `routes.yaml` is imported (previously they were served with no access check).
- Rich-text sanitizer (consent labels, rendered with `label_html`): markup nested inside an unwrapped disallowed element (e.g. `<p><img src=x onerror=alert(1)></p>`) is now sanitized too; previously it survived unchanged (XSS).

### Fixed

- `public_submission_rate_limit` (enabled by default) now receives `cache.app`; it was silently disabled in real applications because the extension could not see FrameworkBundle services while loading.
- The mailer notifier (`notifications.mailer.enabled`) is registered when `mailer.mailer` / `mailer` exists (decided in a compiler pass); previously the null notifier was always used.
- `SecurityClientResolver` now receives `security.token_storage` when SecurityBundle is installed, so submissions are linked to the authenticated client again.
- The bundle no longer replaces FrameworkBundle's `clock` service with its own `NativeClock` (which broke `MockClock`/`Clock::set()` in tests); a fallback `clock` is only defined when the application has none.

## [1.1.3] - 2026-10-13

### Changed

- CI: GitHub Actions aligned with the org baseline (`actions/checkout` v7, `codecov/codecov-action` v7, `actions/cache` v6).
- Repository: `.twig-cs-fixer.cache` is now git-ignored.

### Dependencies

- `doctrine/orm` 3.7.4 → 3.7.5 (development lock; #41).
- `nowo-tech/form-kit-bundle` 2.6.0 → 2.7.1 (development lock; #42).
- `nowo-tech/ui-kit-bundle` 1.9.1 → 1.11.0 (development lock; #43).

## [1.1.2] - 2026-10-09

### Changed

- CSP: the inline `<script>` in `admin/base.html.twig` now carries `nonce="…"` from the request attribute `csp_nonce` when it is set. The submission delete form (`admin/submission/show.html.twig`) no longer uses an inline `onsubmit` handler: it declares `data-confirm` and the admin base script confirms via a delegated `submit` listener. Added a template scan test (inline blocks must declare the nonce; no inline event handlers).

## [1.1.1] - 2026-10-09

### Fixed

- PHPStan 2.3 / doctrine/orm 3.7.4: `WorkerSafeServiceEntityRepository::findBy()`/`findOneBy()` forward `SortDirection` order values without a static-analysis error (ORM 3.7 accepts them at runtime).

### Dependencies

- Dependabot: `doctrine/orm` 3.7.3, `nowo-tech/form-kit-bundle` 2.5.4, `nowo-tech/ui-kit-bundle` 1.8.4, dev `igor-php/igor-php` 0.10, `nowo-tech/phpstan-frankenphp` 1.2.1, `phpstan/phpstan-phpunit`.
- Lock refresh: `doctrine/orm` 3.7.4, FormKitBundle 2.6.0, UiKitBundle 1.9.1; dev PHPStan 2.3.1, PHPUnit 11.5.57, Rector 2.7.0.
- Demo (Symfony 8): Symfony 8.1.8, `doctrine/dbal` 4.5.0, PhoneInputBundle 1.4.2, Twig 3.30.0.

## [1.1.0] - 2026-09-28

### Security

- Consent HTML is sanitized on admin persist (and again on render).
- Empty `security.access_roles` is fail-closed (deny) unless `allow_unauthenticated` or a custom `access_checker` is set.

### Changed

- **Doctrine ORM SortDirection:** replace string `'ASC'`/`'DESC'` in `#[ORM\OrderBy]` and QueryBuilder `orderBy`/`addOrderBy` with `SortDirection::Ascending`/`Descending` (doctrine/orm deprecation, https://github.com/doctrine/orm/issues/11313); require `doctrine/orm` `^3.7` where applicable.

## [1.0.23] - 2026-09-27

### Added

- **REQ-CS-008:** `igor-php/igor-php` (require-dev only), root `igor.json`, Composer/`Makefile` `igor` target, and `release-check` wiring for FrankenPHP worker-state audit.

### Changed

- **Worker safety (Igor):** justified `// @igor-ignore` annotations and/or `ResetInterface` / request-scoped fixes so `make igor` passes on package `src/`.

[1.5.1]: https://github.com/nowo-tech/ContactFormBundle/releases/tag/v1.5.1
[1.5.0]: https://github.com/nowo-tech/ContactFormBundle/releases/tag/v1.5.0
[1.4.1]: https://github.com/nowo-tech/ContactFormBundle/releases/tag/v1.4.1
[1.4.0]: https://github.com/nowo-tech/ContactFormBundle/releases/tag/v1.4.0
[1.3.2]: https://github.com/nowo-tech/ContactFormBundle/releases/tag/v1.3.2
[1.3.1]: https://github.com/nowo-tech/ContactFormBundle/releases/tag/v1.3.1
[1.3.0]: https://github.com/nowo-tech/ContactFormBundle/releases/tag/v1.3.0
[1.2.0]: https://github.com/nowo-tech/ContactFormBundle/releases/tag/v1.2.0
[1.1.3]: https://github.com/nowo-tech/ContactFormBundle/releases/tag/v1.1.3
[1.1.2]: https://github.com/nowo-tech/ContactFormBundle/releases/tag/v1.1.2
[1.1.1]: https://github.com/nowo-tech/ContactFormBundle/releases/tag/v1.1.1
[1.1.0]: https://github.com/nowo-tech/ContactFormBundle/releases/tag/v1.1.0
[1.0.23]: https://github.com/nowo-tech/ContactFormBundle/releases/tag/v1.0.23

## [1.0.22] - 2026-09-24

### Added

- **FrankenPHP worker (FR-WORKER-001)**: `ContactFormEntityManagerResolver`, `ContactFormEntityManagerSubscriber`, and `WorkerSafeServiceEntityRepository`; audit doc [`docs/FRANKENPHP-WORKER-AUDIT.md`](FRANKENPHP-WORKER-AUDIT.md).
- Optional trailing `$entityManagerResolver` on `ContactSubmissionProcessor` (wired by the extension).

### Fixed

- **FrankenPHP worker mode (no kernel reset):** closed EntityManager recovered on bundle routes (`kernel.request` / `kernel.exception`) and after a failed submission flush, so one DB error no longer breaks later requests on the worker.
- **FrankenPHP worker mode:** submissions (and values) are detached after event + notification so personal data does not accumulate in a long-lived identity map.
- **FrankenPHP worker mode:** `findOneEnabledBySlug()` / `findByFormOrdered()` fetch-join translations with `Query::HINT_REFRESH` so cross-worker admin edits are visible on the next public request.
- **FrankenPHP worker mode:** repositories always resolve the current manager from the registry (ORM 3 `ServiceEntityRepositoryProxy` no longer keeps a closed EM after `resetManager()`).
- **Demo (REQ-DEMO-011):** FrankenPHP image installs `pdo_mysql`; demo Doctrine migrations rewritten for MySQL (were SQLite `AUTOINCREMENT`).

[1.0.22]: https://github.com/nowo-tech/ContactFormBundle/releases/tag/v1.0.22

## [1.0.21] - 2026-08-24

### Changed

- **Demos:** MySQL env policy in FrankenPHP stack (REQ-DEMO-011).
- **Docs:** PHP-FIG PSR evaluation (REQ-CS-007).

### Notes

- **No API or configuration changes** for integrators unless noted above.

[1.0.21]: https://github.com/nowo-tech/ContactFormBundle/releases/tag/v1.0.21

## [1.0.20] - 2026-08-20

### Fixed

- **Public dynamic forms:** `DynamicContactFormBuilder` applies FormKit profile `contact_form` (`FormOptionsMerger`) so host `nowo_form_kit.profiles.contact_form.defaults.row_attr` / `attr` affect public fields (form name `public_contact`). CMS labels/help/placeholders stay as plain strings.

[1.0.20]: https://github.com/nowo-tech/ContactFormBundle/releases/tag/v1.0.20

## [1.0.19] - 2026-08-20

### Security

- **Flex recipe:** `when@prod` keeps `ROLE_ADMIN`, `allow_unauthenticated: false`, and tighter public rate limits; ship `security_nowo_contact_form.yaml` (`access_control` for `/admin/contact-forms`). Prefer **`^1.0.19`**.

[1.0.19]: https://github.com/nowo-tech/ContactFormBundle/releases/tag/v1.0.19

## [1.0.18] - 2026-08-19

### Security

- **CI:** run `composer audit --locked` after dependency install (REQ-SEC / P3).

## [1.0.17] - 2026-08-19

### Fixed

- Allow `doctrine/orm` 3.6.8 again (removed the temporary `<3.6.8` constraint from 1.0.16).
- Integration TestKernel uses array cache adapters so SchemaTool does not hit `DoctrineDbalCacheAdapterSchemaListener` / DBAL `Schema::edit` on DBAL 4.4.

## [1.0.16] - 2026-08-19

### Added

- `nowo_contact_form.doctrine.table_prefix` — optional prefix for entity tables (`nowo_contact_form`, `nowo_contact_submission`, …), same pattern as BlogKit / CookieConsent. Empty (default) keeps hardcoded table names.

### Changed

- Temporarily constrained `doctrine/orm` below 3.6.8 in 1.0.16 CI; lifted in **1.0.17** (prefer upgrading to 1.0.17).

## [1.0.15] - 2026-08-18

### Changed

- **Demos:** pin `nowo-tech/hot-reload-bundle` to `^1.4` with FrankenPHP Mercure/`hot_reload` (`dev`/`test` only).
- **UiKit:** Admin templates use `ui.btn` / `ui.row_actions` macros with `nowo_contact_form_css_framework` instead of hard-coded Bootstrap button classes.

## [1.0.14] - 2026-08-04

### Added

- **REQ-TWIG-004:** require `twig/extra-bundle` + `twig/string-extra`; `make check-twig-extra` in `release-check`; demos register `TwigExtraBundle`.
- **Twig-CS-Fixer:** `vincentlanglet/twig-cs-fixer`, `.twig-cs-fixer.php`, `composer twig:lint` / `twig:fix`.

### Changed

- **REQ-UI-001-kit:** Requires **[UiKitBundle](https://github.com/nowo-tech/UiKitBundle)** (`nowo-tech/ui-kit-bundle` `^1.4`). Admin `base.html.twig` loads `asset('css/nowo-ui.css', 'nowo_ui_kit')` and imports `@NowoUiKitBundle/macros/ui.html.twig` (flashes via `ui.flash`, primary list toolbar via `ui.btn` on form index). Extension implements `PrependExtensionInterface` and seeds `nowo_ui_kit` from `web_ui.css_framework` / `icon_set` when the host has not configured UiKit.
- **FormKitBundle:** depend on [`nowo-tech/form-kit-bundle`](https://github.com/nowo-tech/FormKitBundle) ^2.0. Admin form types use `FormOptionsTrait` + profile `contact_form` (`#[FormKitConfig]`). Extension prepends that profile when missing; form types are tagged `form.type` so `FormOptionsMerger` is injected.
- **Dependencies:** require `symfony/clock` (`^7.4 || ^8.0`) for the `NativeClock` fallback when the host has no `clock` service (was only `psr/clock`).

## [1.0.13] - 2026-07-30

### Added

- **Admin Twig (REQ-UI-001)**: `admin/base.html.twig` page shell extends configurable `web_ui.layout_template` and stacks `nowo_ui_styles` / `nowo_ui_scripts` with `{{ parent() }}`.

### Changed

- **Admin Twig**: Form/field/submission templates extend `admin/base.html.twig` instead of the layout global directly; default `admin/layout.html.twig` remains a full HTML root (no `parent()`).
- **Docs**: README documentation links section reorder; Configuration / Usage / inventory updated for the base layout stack.
- **Dependencies (lock)**: Symfony 7.4.x patch bumps and `nowo-tech/phpstan-frankenphp` / `phpstan/phpstan` updates in `composer.lock`.

## [1.0.12] - 2026-07-29

### Changed

- **Makefiles (REQ-MAKE-010)**: Prefer Docker Compose V2 (`docker compose`) with fallback to `docker-compose` V1 in root, demo aggregate, and `demo/symfony8` Makefiles; demo uses absolute `docker` path to avoid shadowing by a local `docker/` directory.
- **Makefiles (REQ-MAKE-009)**: Monorepo `update-deps` helpers use soft `-include` so standalone clones and GitHub Actions checkouts no longer fail on missing `../.scripts/` paths.

## [1.0.11] - 2026-07-28

### Added

- **Docs (REQ-DOCS-005 / REQ-TEST-003)**: Table of contents on long docs; `docs/COVERAGE.md` documents PHPUnit coverage exclusions.
- **Spec Kit (REQ-SPECKIT-001 / REQ-SPECKIT-003)**: Code inventory + `FR-SEC-001` for admin access checker / security compiler pass / access subscriber; pagination Twig partial mapped.
- **Dependencies**: Direct `psr/clock` requirement for clock-injected services.

### Changed

- **DI (REQ-DI-001)**: `Psr\Clock\ClockInterface` injected into submission rate limiter, submission processor, and retention cleanup (tests use `MockClock`).
- **CI / PHPUnit (REQ-SF-005)**: `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0` in `phpunit.xml.dist` and CI test jobs.
- **GitHub About (REQ-DOCS-018)**: Topic `frankenphp` added.
- **Tooling**: `check-open-prs` resolves `owner/repo` from `origin` when `gh` cannot map the git remote.

## [1.0.10] - 2026-07-27

### Added

- **Tooling (REQ-REL-003 / REQ-MAKE-002)**: `make check-open-prs` (`.scripts/check-open-prs.sh`) is part of `release-check` and fails when unresolved open GitHub PRs remain.

### Changed

- **CI**: `actions/checkout` bumped from v6 to v7 (#9).
- **Docs**: `RELEASE.md` documents the open-PR gate before tagging.

## [1.0.9] - 2026-07-27

### Added

- **Flex recipe (REQ-RECIPE-001)**: Complete `.symfony/recipe` with `copy-from-recipe`, routes import, `post-install.txt`, and Flex section in `INSTALLATION.md`.
- **Admin Web UI (REQ-UI-001 / REQ-UI-002)**: `web_ui` layout/css/icon config, Twig globals, semantic `nowo-ui-*` hooks; `security.access_roles` + `ContactFormAccessCheckerInterface` + admin access subscriber; host firewall guidance.
- **Pagination (REQ-PERF-001)**: Admin form and submission lists paginated via `web_ui.list_page_size` (default `20`).

### Changed

- **Admin routes**: Path prefix from `admin_route_prefix` via `routes.yaml` (index URL ends with `/`).
- **Security posture**: Documented bundled admin auth; demo uses `allow_unauthenticated: true` (demo-only).
- **GitHub About (REQ-DOCS-018)**: Description, Packagist website, and topics set on the repository.
- **Coverage**: PHP line coverage badge refreshed to **99.45%**.

## [1.0.8] - 2026-07-24

### Added

- **Tooling (REQ-CS-005)**: `nowo-tech/phpstan-frankenphp` in `require-dev` with `ruleset-classic` + `ruleset-worker` in `phpstan.neon.dist`.
- **Documentation (REQ-DOCS-017)**: FrankenPHP Friendly Worker Mode banner and canonical claim in root `README.md` (`docs/images/frankenphp-friendly.png`).

### Changed

- **Static analysis**: Form/service PHPDoc generics for Symfony `AbstractType` / `FormInterface` / `FormBuilderInterface` (`TData`); removed prior `ignoreErrors` for missing generics.
- **Demo (Symfony 8)**: FrankenPHP image bumped to PHP **8.5**; `.env.example` comments per variable; `.gitignore` ignores `/.pnpm-store` (REQ-GITIGNORE-003); `make up` removes stray `.env.dev`; demo README rewritten for Contact Form (was Icon Selector copy-paste).
- **Documentation**: `DEMO-FRANKENPHP.md` PHP version table aligned to 8.5; README worker-mode wording matches `FRANKENPHP_MODE=worker`.

### Removed

- **Docs assets**: Unused Icon Selector screenshots (`docs/images/demo-grid.png`, `demo-tom-select.png`).

## [1.0.7] - 2026-07-22

### Added

- **Demo (Symfony 8)**: `FRANKENPHP_MODE` (`worker` default, or `classic`) via `.env` / Compose; dedicated `docker/entrypoint.sh` (`REQ-DEMO-010`).

### Changed

- **Documentation**: `DEMO-FRANKENPHP.md` documents classic vs worker switching and aligns with the demo default (worker).
- **Code style**: Applied pending Rector rules (`readonly` services, stricter type checks, test `self::` cleanup).
- **Static analysis**: Cleared PHPStan level 8 (options typing, Doctrine EM helpers in integration tests, and related assertions).
- **Dependencies (lock)**: Bumped `doctrine/doctrine-bundle` in the root and demo locks.

## [1.0.6] - 2026-07-22

### Fixed

- **Twig**: Public form render uses `@NowoContactFormBundle/...` (aligned with admin controllers and `TwigPathsPass` namespace).
- **TwigPathsPass**: Prepends `templates/bundles/NowoContactFormBundle` when present so app overrides win; resolves Twig loader aliases and falls back to `twig.loader.filesystem`.

### Changed

- **Dependencies (lock)**: Bumped `doctrine/dbal` and `friendsofphp/php-cs-fixer` in root and demo locks.

## [1.0.5] - 2026-07-20

### Fixed

- **Tests / doctrine-bundle 3.x**: Integration `TestKernel` no longer sets removed ORM options `auto_generate_proxy_classes` / `enable_lazy_ghost_objects` (breaks Symfony 7.4/8 + doctrine-bundle 3.x in CI).

## [1.0.4] - 2026-07-20

### Changed

- **Demos**: Removed `demo/symfony7`; FrankenPHP demo is Symfony 8 only (`demo/symfony8`, port 8021).
- **Makefile**: Compose commands use `docker compose` (Compose V2 plugin) instead of `docker-compose` (root and `demo/symfony8`).

### Fixed

- **CI (Symfony 8.x)**: Matrix installs `doctrine/doctrine-bundle` `^3.1` on Symfony 8 (2.x only supports Symfony 6/7; SF8 support starts at 3.1), updates `doctrine/*` with Symfony packages, and keeps security/csrf/var-exporter in `require-dev` via `composer require --dev`.
- **CI matrix**: Dropped Symfony 7.0 cells (bundle requires `^7.4 || ^8.0`); coverage job uses Symfony 7.4.
- **`composer.json`**: `symfony/security-core` allowlist includes `^8.0`.
- **Demo (Symfony 8)**: Migration `Version20250620130000` is a no-op — `select_options` is already created in the initial schema, so fresh `make up` no longer fails on duplicate column.
- **Demo Makefile**: Healthcheck wait loop no longer exits early; `release-verify` prefers `PORT` from `.env` over `.env.example`.

## [1.0.3] - 2026-07-20

### Added

- **Tests**: Unit coverage for `ContactFormSubmissionRateLimiter` (limit, interval reset, null cache, missing client IP).
- **Contributor Covenant** [`CODE_OF_CONDUCT.md`](../CODE_OF_CONDUCT.md).
- **REQ-GIT-001 tooling**: `.scripts/check-no-cursor-coauthor.sh`, `.scripts/strip-cursor-coauthor-from-history.sh`, `.githooks/commit-msg`, Cursor rule `.cursor/rules/01-git-commits.mdc`, and [`docs/GITHUB_CI.md`](GITHUB_CI.md).
- **CI**: `git-hygiene` job that audits full history for Cursor co-author trailers.
- **Makefile**: `check-no-cursor-coauthor`, `setup-hooks`, and `strip-cursor-coauthor-from-history`; `release-check` depends on the co-author check.

### Changed

- **Documentation**: `README`, `CONTRIBUTING`, and `RELEASE` document Code of Conduct, git hooks, and the post-tag co-author re-check before push.

## [1.0.2] - 2026-07-13

### Fixed

- **CI (PHP 8.4 / 8.5)**: CI matrix installs `symfony/var-exporter` (required by Doctrine ORM 3 lazy ghost proxies in integration tests).

### Changed

- **Documentation**: `CONFIGURATION.md` documents rate limiting, CSRF, and remaining bundle options; `SECURITY.md` and `RELEASE.md` aligned with host-app CSRF setup and `master` branch workflow.

## [1.0.1] - 2026-07-13

### Fixed

- **CI / Symfony 7.0**: Unit tests no longer fail when the Form component is bootstrapped without the CSRF extension; public forms rely on host `framework.form.csrf_protection` configuration instead of hardcoded builder options.
- **DI**: `ContactFormSubmissionRateLimiter` is registered only via the bundle extension (excluded from autodiscovery).
- **CI**: Matrix installs `symfony/security-bundle` and `symfony/security-csrf` for CSRF-enabled form tests.

## [1.0.0] - 2026-07-13

First stable release of **Contact Form Bundle** (`nowo-tech/contact-form-bundle`).

### Added

- **Admin CRUD** for contact forms, customizable fields (wizard on Symfony 8.1+), and submissions with translation tabs.
- **Public form** at `/contact/{slug}` with PRG flow, optional CSRF, rate limiting, and flash success message.
- **Field types**: text, email, phone (`ContactPhoneType`, optional `nowo-tech/phone-input-bundle`), textarea, select, rich text, and file upload (via `ContactFormFileUploadHandlerInterface`).
- **Multilingual** copy via `ContactFormTranslation` / `ContactFormFieldTranslation` entities.
- **GDPR**: consent checkbox, privacy policy link, IP anonymization (`IpAnonymizer`), per-form retention days, and `nowo:contact-form:cleanup-submissions` CLI.
- **Client association**: optional link to a host client entity (`ClientResolverInterface`, `SecurityClientResolver`) or anonymous submissions.
- **Notifications**: `ContactSubmissionNotifierInterface`, Symfony Mailer implementation, `ContactSubmissionCreatedEvent`, and null notifier fallback.
- **Services**: `ContactSubmissionProcessor`, `DynamicContactFormBuilder`, `ContactFormSubmissionValueNormalizer`, `ContactFormRichTextSanitizer`, `SubmissionRetentionCleanupService`.
- **Twig**: admin and public templates; `TwigPathsPass` so app overrides in `templates/bundles/NowoContactFormBundle/` take precedence.
- **Translations**: `NowoContactFormBundle` domain (en, es, de, fr, it, nl, pt).
- **Demos**: Symfony 7 and 8 FrankenPHP demos with seed command, composite notifiers, and HTTP smoke (`make -C demo release-verify`).
- **Tests**: PHPUnit unit and integration suites with **99.78%** line coverage; SQLite integration kernel with CSRF-ready security stack.
- **Tooling**: PHP-CS-Fixer, PHPStan, Rector, Scrutinizer config, `make release-check`, CI matrix (Symfony 7.4 / 8.0 / 8.1).
- **Documentation**: installation, configuration, usage, upgrading, security, FrankenPHP demos, spec-driven development overview.
- **Symfony Flex recipe** under `.symfony/recipe/nowo-tech/contact-form-bundle/1.0/`.

### Fixed

- **Documentation / GitHub**: `docs/SECURITY.md`, `.github/SECURITY.md`, issue templates, and `CODEOWNERS` aligned with ContactFormBundle (removed IconSelectorBundle copy-paste).
- **Demos**: `release-verify` runs `update-bundle`; Symfony 7 demo registers `DoctrineMigrationsBundle`; healthchecks accept HTTP 2xx/3xx.

### Changed

- **README**: badges, Symfony 7.4 | 8.0 | 8.1+ compatibility label, coverage badge.
- **`docs/DEMO-FRANKENPHP.md`**, **`docs/INSTALLATION.md`**, **`docs/USAGE.md`**: aligned with contact-form install, schema, and admin security guidance.
- **`.scrutinizer.yml`**: PHP/coverage checks only (no missing frontend test step); **`docs/RELEASE.md`** updated accordingly.
