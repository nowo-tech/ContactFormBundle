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
