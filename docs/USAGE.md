# Usage

## Admin

- `/admin/contact-forms` — list and CRUD form definitions
- `/admin/contact-forms/{id}/fields` — manage customizable fields
- `/admin/contact-forms/{id}/submissions` — view and delete submissions

### Secure admin routes

The bundle does **not** enforce authentication. In production, restrict admin URLs in your firewall, for example:

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin/contact-forms, roles: ROLE_ADMIN }
```

Or protect controllers with `#[IsGranted('ROLE_ADMIN')]` in a decorating layer in your application.

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
