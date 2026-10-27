# Usage

## Table of contents

- [Admin](#admin)
  - [Secure admin routes](#secure-admin-routes)
- [Public form](#public-form)
- [Custom notification (Slack, API, etc.)](#custom-notification-slack-api-etc)
- [Mailer notifications](#mailer-notifications)
- [Overrides](#overrides)

## Admin

- `/admin/contact-forms/` — list and CRUD form definitions (trailing slash)
- `/admin/contact-forms/{id}/fields` — manage customizable fields
- `/admin/contact-forms/{id}/submissions` — view and delete submissions

### Secure admin routes

Admin CRUD is protected by the bundle access checker (`security.access_roles`, default `ROLE_ADMIN`) **and** should be locked by the host firewall:

```yaml
# config/packages/nowo_contact_form.yaml
nowo_contact_form:
    security:
        access_roles: [ROLE_ADMIN]
        allow_unauthenticated: false

# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin/contact-forms, roles: ROLE_ADMIN }
```

The admin pages and the public form render on the UiKit page contract: point `nowo_ui_kit.layouts.admin` /
`.guest` at your project layout (or a one-file bridge) once and every page uses your chrome and CSS stack — no
template copy (see [Configuration](CONFIGURATION.md#admin-web-ui-layout-and-css) and [Templates](TEMPLATES.md)).
Deletes (forms, fields, submissions) go through the UiKit confirm dialog (POST + CSRF).

## Public form

Visitors submit at `/{_locale}/contact/{slug}` (`/contact/{slug}` redirects there). The page renders on the UiKit guest
layout (`web_ui.public_layout_template`, null = `nowo_ui_kit.layouts.guest`); the success message is a flash shown by
the layout. Authenticated users are linked to the configured client entity when applicable.

## Custom notification (Slack, API, etc.)

```php
use Nowo\ContactFormBundle\Notification\ContactSubmissionNotifierInterface;
use Nowo\ContactFormBundle\Notification\ContactSubmissionNotification;

final class SlackContactSubmissionNotifier implements ContactSubmissionNotifierInterface
{
    public function notify(ContactSubmissionNotification $notification): void
    {
        // Send to Slack or any channel
    }
}
```

```yaml
# config/services.yaml
services:
    App\Notification\SlackContactSubmissionNotifier: ~

# config/packages/nowo_contact_form.yaml
nowo_contact_form:
    notifications:
        enabled: true
        service: App\Notification\SlackContactSubmissionNotifier
```

Alternatively, subscribe to `Nowo\ContactFormBundle\Event\ContactSubmissionCreatedEvent`.

## Mailer notifications

```bash
composer require symfony/mailer
```

```yaml
nowo_contact_form:
    notifications:
        enabled: true
        default_recipient: admin@example.com
        mailer:
            enabled: true
            from: noreply@yourdomain.com
            subject: 'New message: {form}'
```

## Overrides

- Twig templates (logical name): `@NowoContactFormBundle/...`
- Twig overrides: `templates/bundles/NowoContactFormBundle/<subpath>` — an application file there **always wins**.
- **Freeze rule:** a full-file override hides every vendor change to that `<subpath>` until you delete or merge it.
  Prefer, in this order: `nowo_ui_kit` layouts / theme (slots, tokens, `theme.bundles.NowoContactFormBundle`) and
  translations; then `web_ui.templates.<page>` with a thin `{% extends '@!NowoContactFormBundle/...' %}` overriding one
  data block, or a single partial; full-file copies last.
- **Recover upstream:** delete (or rename aside) the override, `php bin/console cache:clear`, re-apply only surgical
  customisations; diff against `vendor/nowo-tech/contact-form-bundle/src/Resources/views/<subpath>` first.
- Overridable `<subpath>` list, procedure and the per-page data blocks: [Templates](TEMPLATES.md#overriding-templates-req-twig-001).
- Translations: `translations/NowoContactFormBundle.{locale}.yaml`
