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

Point `web_ui.layout_template` at your project layout to embed admin pages in host chrome (see [Configuration](CONFIGURATION.md)).

## Public form

Visitors submit at `/contact/{slug}`. Authenticated users are linked to the configured client entity when applicable.

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
- Twig overrides: `templates/bundles/NowoContactFormBundle/`
- Translations: `translations/NowoContactFormBundle.{locale}.yaml`
