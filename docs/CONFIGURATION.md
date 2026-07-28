# Configuration

Extension alias: `nowo_contact_form`.

| Option | Default | Description |
| --- | --- | --- |
| `client_entity_class` | `null` | Optional FQCN of host client entity for linking submissions |
| `client_label_property` | `email` | Property or getter used as client label in admin |
| `client_user_accessor` | `null` | Method on authenticated user returning the client (e.g. `getClient`) |
| `ip_anonymization_salt` | `%kernel.secret%` | Salt for SHA-256 IP hashing (GDPR) |
| `default_retention_days` | `365` | Default retention for new forms |
| `notifications.enabled` | `false` | Enable submission notifications |
| `notifications.service` | `null` | Custom `ContactSubmissionNotifierInterface` service id |
| `notifications.default_recipient` | `null` | Fallback recipient when form has no `notification_email` |
| `notifications.mailer.enabled` | `false` | Use symfony/mailer when installed |
| `notifications.mailer.from` | `noreply@example.com` | Mailer sender |
| `notifications.mailer.subject` | `New contact submission: {form}` | Mailer subject template |
| `admin_route_prefix` | `/admin/contact-forms` | URL prefix for bundle admin CRUD routes (host `access_control`) |
| `web_ui.enabled` | `true` | Registers admin access enforcement when security is configured |
| `web_ui.layout_template` | `@NowoContactFormBundle/admin/layout.html.twig` | Twig layout extended by admin pages (global `nowo_contact_form_layout_template`). Set to your app layout or a one-file bridge |
| `web_ui.css_framework` | `bootstrap5` | Host CSS stack hint: `bootstrap5`, `bootstrap4`, `bootstrap`, `tailwind`, `foundation`, `tabler`, `custom`, `none` |
| `web_ui.icon_set` | `bootstrap-icons` | Icon hint: `bootstrap-icons`, `tabler-icons`, `ux_icon`, `svg_inline`, `none` |
| `web_ui.list_page_size` | `20` | Page size for admin form and submission lists |
| `security.access_roles` | `[ROLE_ADMIN]` | Roles allowed for admin CRUD (empty disables bundle-level role checks) |
| `security.access_checker` | `null` | Optional custom service id implementing `ContactFormAccessCheckerInterface` |
| `security.allow_unauthenticated` | `false` | **DEV/DEMO ONLY.** Skip SecurityBundle requirement and admin access subscriber. Never enable in production |
| `phone_prefixes` | ES/US/FR/UK/DE/PT defaults | Dialing prefixes for legacy phone fields (`code => label`) |
| `phone_input.value_format` | `CONCATENATED` | Passed to PhoneInputBundle when installed |
| `phone_input.default_country` | `ES` | Default country ISO for phone fields |
| `phone_input.country_prefix_selector` | `true` | Show prefix selector in PhoneInputBundle |
| `phone_input.show_flag` | `true` | Show flags in PhoneInputBundle selector |
| `file_upload.service` | `null` | Custom `ContactFormFileUploadHandlerInterface` service id (required for file fields) |
| `public_submission_rate_limit.enabled` | `true` | Enable per-IP rate limiting on public POST |
| `public_submission_rate_limit.limit` | `5` | Max submissions per interval |
| `public_submission_rate_limit.interval_seconds` | `60` | Rate-limit window in seconds |

## Table of contents

- [CSRF (public forms)](#csrf-public-forms)
- [Admin Web UI (layout and CSS)](#admin-web-ui-layout-and-css)
- [Admin security](#admin-security)
- [Public submission rate limiting](#public-submission-rate-limiting)
- [Client linking (public submissions)](#client-linking-public-submissions)
- [GDPR retention cleanup](#gdpr-retention-cleanup)
- [Notifications without symfony/mailer](#notifications-without-symfonymailer)

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

Admin pages extend `web_ui.layout_template` (Twig global `nowo_contact_form_layout_template`). Prefer pointing that at your project layout (or a thin bridge that maps `nowo_ui_content` into your `body` block) instead of copying list/form templates.

```yaml
nowo_contact_form:
    web_ui:
        layout_template: 'base.html.twig'
        css_framework: bootstrap5
        list_page_size: 20
```

When using the project layout, load host CSS/JS in that layout. Bundle pages stack extras with `{{ parent() }}` in `stylesheets` / `javascripts` when they add assets. Semantic hooks use `nowo-ui-*` classes.

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
        - { path: ^/admin/contact-forms, roles: ROLE_ADMIN }
```

`allow_unauthenticated: true` is for demos/CI only. Production must keep it `false` and require `symfony/security-bundle`.

## Public submission rate limiting

When `public_submission_rate_limit.enabled` is `true` and the app provides `cache.app`, submissions are limited per client IP and form slug. Exceeding the limit returns HTTP 429. Set `enabled: false` or `limit: 0` to disable.

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

Each form's `retention_days` defines when its submissions are eligible for deletion.

## Notifications without symfony/mailer

Three integration options:

1. **Custom notifier service** — implement `ContactSubmissionNotifierInterface` and set `notifications.service`.
2. **Event subscriber** — listen to `ContactSubmissionCreatedEvent`.
3. **Mailer** — enable `notifications.mailer.enabled` when `symfony/mailer` is installed.

Per-form override: set `notification_email` on the contact form in admin (falls back to `notifications.default_recipient`).
