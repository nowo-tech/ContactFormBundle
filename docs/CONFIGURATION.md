# Configuration

Extension alias: `nowo_contact_form`.

| Option | Default | Description |
| --- | --- | --- |
| `client_entity_class` | `null` | Optional FQCN of host client entity for linking submissions |
| `client_label_property` | `email` | Property or getter used as client label in admin |
| `client_user_accessor` | `null` | Method on authenticated user returning the client (e.g. `getClient`) |
| `ip_anonymization_salt` | `%kernel.secret%` | Salt for SHA-256 IP hashing (GDPR) |
| `default_retention_days` | `365` | Default retention for new forms (1 to 3650; a form may set 0 = keep forever) |
| `notifications.enabled` | `false` | Enable submission notifications |
| `notifications.service` | `null` | Custom `ContactSubmissionNotifierInterface` service id |
| `notifications.default_recipient` | `null` | Fallback recipient when form has no `notification_email` |
| `notifications.mailer.enabled` | `false` | Use symfony/mailer when installed |
| `notifications.mailer.from` | `noreply@example.com` | Mailer sender |
| `notifications.mailer.subject` | `New contact submission: {form}` | Mailer subject template |
| `web_ui.enabled` | `true` | When `false`, admin CRUD routes respond 404 even if the bundle routes are imported; when `true`, admin routes are guarded by the access checker |
| `web_ui.path_prefix` | `null` (= `/admin/contact-forms`) | URL prefix of the admin routes (lock it with the host `access_control`) |
| `web_ui.layout_template` | `null` | Layout the admin pages extend; `null` = `nowo_ui_kit.layouts.admin` (REQ-UI-003). A 1.x layout without the UiKit canonical blocks is wrapped in a deprecated bridge |
| `web_ui.public_layout_template` | `null` | Layout of the public contact form; `null` = `nowo_ui_kit.layouts.guest` |
| `web_ui.templates.<page>` | `{}` | Per-page template overrides (`form_index`, `form_form`, `field_index`, `field_form`, `submission_index`, `submission_show`, `public_form`); unknown ids fail the container compilation. See [Templates](TEMPLATES.md#per-page-overrides) |
| `web_ui.list_page_size` | `20` | Page size for admin form and submission lists |
| `admin_route_prefix` | `null` | **Deprecated since 1.6**, use `web_ui.path_prefix` (still applied when `path_prefix` is not set) |
| `web_ui.css_framework` | `null` | **Deprecated since 1.6**, set `nowo_ui_kit.css_framework` (copied there when UiKit has none) |
| `web_ui.icon_set` | `null` | **Deprecated since 1.6**, set `nowo_ui_kit.icon_set` (copied there when UiKit has none) |
| `security.access_roles` | `[ROLE_ADMIN]` | Roles allowed for admin CRUD (empty disables bundle-level role checks) |
| `security.access_checker` | `null` | Optional custom service id implementing `ContactFormAccessCheckerInterface` |
| `security.allow_unauthenticated` | `false` | **DEV/DEMO ONLY.** Skip SecurityBundle requirement and admin access subscriber. Never enable in production |
| `phone_prefixes` | ES/US/FR/UK/DE/PT defaults | Dialing prefixes for legacy phone fields (`code => label`) |
| `phone_input.value_format` | `CONCATENATED` | Passed to PhoneInputBundle when installed |
| `phone_input.default_country` | `ES` | Default country ISO for phone fields |
| `phone_input.country_prefix_selector` | `true` | Show prefix selector in PhoneInputBundle |
| `phone_input.show_flag` | `true` | Show flags in PhoneInputBundle selector |
| `file_upload.service` | `null` | Custom `ContactFormFileUploadHandlerInterface` service id (required for file fields) |
| `file_upload.max_size` | `5M` | Default max size for file fields (`File` constraint format: bytes, `500k`, `5M`, `2Mi`). `null` = no bundle limit |
| `file_upload.mime_types` | PDF, text, Word/ODT, JPEG/PNG/GIF/WebP | Default allowed MIME types for file fields (wildcards such as `image/*` allowed). `[]` = any type |
| `public_submission_rate_limit.enabled` | `true` | Enable per-IP rate limiting on public POST |
| `public_submission_rate_limit.limit` | `5` | Max submissions per interval |
| `public_submission_rate_limit.interval_seconds` | `60` | Rate-limit window in seconds |
| `public_submission_rate_limit.limiter` | `null` | Optional `symfony/rate-limiter` factory service id (atomic counting; its own limit / interval apply) |
| `public_field_max_length.text` | `255` | Max characters of text / email / URL values |
| `public_field_max_length.textarea` | `10000` | Max characters of textarea values |
| `doctrine.table_prefix` | `""` | Prefix prepended to entity table names (`nowo_contact_form`, …). Empty = no prefix |

## Table of contents

- [Doctrine table prefix](#doctrine-table-prefix)
- [CSRF (public forms)](#csrf-public-forms)
- [Admin Web UI (layout and CSS)](#admin-web-ui-layout-and-css)
- [Admin security](#admin-security)
- [Public submission rate limiting](#public-submission-rate-limiting)
- [File upload limits](#file-upload-limits)
- [Client linking (public submissions)](#client-linking-public-submissions)
- [GDPR retention cleanup](#gdpr-retention-cleanup)
- [FormKit profile (`contact_form`)](#formkit-profile-contact_form)
- [Notifications without symfony/mailer](#notifications-without-symfonymailer)
- [Supported locales](#supported-locales)

## Doctrine table prefix

Optional prefix for all ContactFormBundle entity tables (same pattern as BlogKit / CookieConsent):

```yaml
nowo_contact_form:
    doctrine:
        table_prefix: 'app_'   # yields app_nowo_contact_form, app_nowo_contact_submission, …
```

Leave empty (default) to keep the hardcoded table names from the entities. If you change the prefix after data exists, migrate/rename tables (or regenerate schema) accordingly.

## CSRF (public forms)

The bundle does not force CSRF options on dynamic forms. In the host Symfony application, enable form CSRF protection and install `symfony/security-csrf`:

```yaml
# config/packages/framework.yaml
framework:
    form:
        csrf_protection:
            enabled: true
```

See [Security](SECURITY.md) and [Upgrading](UPGRADING.md#101-2026-07-13).

## Admin Web UI (layout and CSS)

The admin pages and the public contact form follow the UiKit page contract (REQ-UI-003): they extend UiKit page
scaffolds (list, form, wizard, show) and the UiKit layouts, so the host themes them **once** for every Nowo bundle —
layouts, CSS stack, row-action display, theme slots and tokens all live under `nowo_ui_kit`. The bundle ships no layout
and loads no CSS framework. Page → scaffold map and data blocks: [Templates](TEMPLATES.md).

```yaml
# config/packages/nowo_ui_kit.yaml — once per application
nowo_ui_kit:
    css_framework: bootstrap5          # bootstrap5 | bootstrap4 | tailwind | foundation | tabler | custom | none
    icon_set: bootstrap-icons
    row_actions_display: icon          # icon | text | icon_text
    layouts:
        admin: 'layouts/admin.html.twig'   # host layout or bridge rendering the UiKit canonical blocks
        guest: 'layouts/guest.html.twig'   # public pages (the contact form)
```

```twig
{# templates/layouts/admin.html.twig — one-file bridge onto the project layout #}
{% extends 'base.html.twig' %}
{% use '@NowoUiKit/layout/_bridge.html.twig' %}
{% block title %}{{ block('nowo_ui_bridge_title') }}{% endblock %}
{% block stylesheets %}{{ parent() }}{{ block('nowo_ui_bridge_assets') }}{% endblock %}
{% block body %}{{ block('nowo_ui_bridge_body') }}{% endblock %}
{% block javascripts %}{{ parent() }}{{ include(nowo_ui_partial('scripts')) }}{{ block('nowo_ui_bridge_end') }}{% endblock %}
```

Choosing the CSS stack (no template copy needed — markup comes from UiKit theme slots):

| Stack | `nowo_ui_kit` | Form theme (`twig.form_themes`) |
| --- | --- | --- |
| Bootstrap 5 | `css_framework: bootstrap5`, `icon_set: bootstrap-icons` | `bootstrap_5_layout.html.twig` |
| Tailwind | `css_framework: tailwind`, `icon_set: svg_inline` | `tailwind_2_layout.html.twig` or your own |
| Foundation | `css_framework: foundation` | `foundation_6_layout.html.twig` |
| Own design system | `css_framework: custom` (semantic `nowo-ui-*` classes + `theme.classes` map) | your own theme |

Per-bundle exceptions go under `nowo_ui_kit.theme.bundles.NowoContactFormBundle` (classes, attributes, partials,
tokens). Bundle-specific settings stay here:

```yaml
nowo_contact_form:
    web_ui:
        path_prefix: '/admin/contact-forms'
        layout_template: null          # null = nowo_ui_kit.layouts.admin; or a layout of your own for these pages only
        public_layout_template: null   # null = nowo_ui_kit.layouts.guest
        list_page_size: 20
        # templates: { submission_show: 'admin/contact/submission.html.twig' }   # extends @!NowoContactFormBundle/...
```

The public form theme of `ContactPhoneType` (`@NowoContactFormBundle/form/contact_phone.html.twig`, applied by the page)
wraps the prefix select and the number in `.nowo-contact-form-phone` (`role="group"`); lay them out in your CSS (e.g.
`display: flex; gap: .5rem`).

## Admin security

```yaml
nowo_contact_form:
    security:
        access_roles: [ROLE_ADMIN]
        # access_checker: App\Security\ContactFormAccessChecker
        allow_unauthenticated: false
```

Also lock the path in the host firewall:

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin/contact-forms, roles: ROLE_ADMIN }   # web_ui.path_prefix
```

`allow_unauthenticated: true` is for demos/CI only. Production must keep it `false` and require `symfony/security-bundle`.

## Public submission rate limiting

When `public_submission_rate_limit.enabled` is `true` (default) and the app provides `cache.app` (FrameworkBundle always does), submission attempts — every POST, valid or not (since 1.6) — are limited per client IP and form slug. Exceeding the limit returns HTTP 429. Set `enabled: false` or `limit: 0` to disable.

The `cache.app` counter is not atomic under concurrent requests. For strict counting, configure a
`symfony/rate-limiter` limiter (with a lock store) and point the bundle at its factory:

```yaml
framework:
    rate_limiter:
        contact_form:
            policy: fixed_window
            limit: 5
            interval: '1 minute'
nowo_contact_form:
    public_submission_rate_limit:
        limiter: limiter.contact_form
```

## File upload limits

Every `file` field gets a Symfony `File` constraint. Defaults come from `file_upload`:

```yaml
nowo_contact_form:
    file_upload:
        service: App\ContactForm\UploadHandler
        max_size: 5M          # null = no bundle limit (PHP upload_max_filesize still applies)
        mime_types:           # [] = any type; detected from content, not from the extension
            - application/pdf
            - text/plain
            - application/msword
            - application/vnd.openxmlformats-officedocument.wordprocessingml.document
            - application/vnd.oasis.opendocument.text
            - image/jpeg
            - image/png
            - image/gif
            - image/webp
```

SVG and HTML are not allowed by default (they can carry scripts). A single field can override the defaults through its
`options` map (`ContactFormField::setOptions()`):

```php
$field->setOptions(['max_size' => '10M', 'mime_types' => ['application/pdf']]);
// ['max_size' => null, 'mime_types' => []] removes both limits for that field
```

## Client linking (public submissions)

When a visitor is authenticated and `client_entity_class` is set:

- If the security user **is** the client entity, the submission is linked automatically.
- If the user exposes the client via a method (e.g. `getClient()`), set `client_user_accessor: getClient`.

Anonymous visitors remain anonymous (`client_id` null).

## GDPR retention cleanup

Schedule the console command (e.g. daily cron):

```bash
php bin/console nowo:contact-form:cleanup-submissions
php bin/console nowo:contact-form:cleanup-submissions --dry-run
```

Each form's `retention_days` (0 to 3650) defines when its submissions are eligible for deletion; `0` keeps them forever (the cleanup skips the form).

## FormKit profile (`contact_form`)

Admin form types use `#[FormKitConfig('contact_form')]`. Public fields from
`DynamicContactFormBuilder` use the same profile with form name `public_contact`
(so `by_form.public_contact` overrides work). When the host has not defined `profiles.contact_form`, the bundle
prepends a framework-neutral seed (since 1.6): `defaults.attr.class: nowo-ui-input`, no row classes, and
`convention_requires_translation: true` (the `{form}.{field}.placeholder` / `.help` convention keys apply only where the
catalogue has them, so no raw keys reach the page). Framework classes come from the host form theme
(`twig.form_themes`). Override in the host:

```yaml
# config/packages/nowo_form_kit.yaml
nowo_form_kit:
    profiles:
        contact_form:
            defaults:
                attr: {}
                row_attr:
                    class: mb-5
```

## Notifications without symfony/mailer

Three integration options:

1. **Custom notifier service** — implement `ContactSubmissionNotifierInterface` and set `notifications.service`.
2. **Event subscriber** — listen to `ContactSubmissionCreatedEvent`.
3. **Mailer** — enable `notifications.mailer.enabled` when `symfony/mailer` is installed.

Per-form override: set `notification_email` on the contact form in admin (falls back to `notifications.default_recipient`).

## Supported locales

The bundle ships the `NowoContactFormBundle` translation domain in **English (`en`), Spanish (`es`), French (`fr`), German (`de`), Italian (`it`), Portuguese (`pt`) and Dutch (`nl`)** (`src/Resources/translations/NowoContactFormBundle.<locale>.yaml`, identical key sets checked by `make validate-translations`). Public forms use the `{_locale}` route segment; the translation tabs list `framework.enabled_locales` (`en` when none), and the interface language of the admin follows the session locale set by `nowo_contact_form_admin_switch_locale` (link it from your layout's locale switcher, see [Templates](TEMPLATES.md#admin-interface-language)). Override any message from the application's `translations/NowoContactFormBundle.<locale>.yaml`.

