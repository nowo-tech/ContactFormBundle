# Usage

## Table of contents

- [Admin](#admin)
  - [Secure admin routes](#secure-admin-routes)
- [Public form](#public-form)
- [File uploads](#file-uploads)
- [Custom notification (Slack, API, etc.)](#custom-notification-slack-api-etc)
- [Mailer notifications](#mailer-notifications)
- [Overrides](#overrides)
- [Events](#events)
- [Using it from a DDD / hexagonal host](#using-it-from-a-ddd--hexagonal-host)
  - [Standard Symfony host (default)](#standard-symfony-host-default)
  - [DDD / hexagonal host (opt-in)](#ddd--hexagonal-host-opt-in)

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

## File uploads

`file` fields need a host service that stores the validated file. Since 1.8 implement
`Nowo\ContactFormBundle\Upload\ContactFormFileUploaderInterface` (framework-free: no `UploadedFile`, no entity):

```php
use Nowo\ContactFormBundle\Upload\ContactFormFileTarget;
use Nowo\ContactFormBundle\Upload\ContactFormFileUploaderInterface;
use Nowo\ContactFormBundle\Upload\UploadedContactFile;

final class ContactAttachmentUploader implements ContactFormFileUploaderInterface
{
    public function __construct(private string $directory) {}

    public function store(UploadedContactFile $file, ContactFormFileTarget $target): string
    {
        // Never build the name from $file->getClientName() (untrusted).
        $name = bin2hex(random_bytes(16)) . '.bin';
        $file->moveTo($this->directory . '/' . $target->formSlug, $name);   // or read $file->openStream()

        return $target->formSlug . '/' . $name;   // stored as the submission value
    }
}
```

```yaml
nowo_contact_form:
    file_upload:
        service: App\ContactForm\ContactAttachmentUploader
```

- `UploadedContactFile` gives `openStream()`, `moveTo($directory, $name)` (only the last path segment of `$name` is
  used; for a real upload it is `UploadedFile::move()`), `getClientName()` (untrusted), `getClientExtension()`,
  `getSize()` and `getMimeType()` — the type **detected on the server** from the content, the one the MIME allowlist
  checked. It keeps its values outside its properties, so dumps, `var_export()` and `(array)` casts show no temporary
  path or client name; it cannot be cloned or serialized.
- `ContactFormFileTarget` carries `formId`, `formSlug`, `fieldId`, `fieldName`, `locale` and the field's `fieldOptions`.
- Validation is unchanged and runs before your service: `file_upload.max_size` / `mime_types` and the per-field
  `max_size` / `mime_types` options ([CONFIGURATION](CONFIGURATION.md#file-upload-limits)). A rejected file never
  reaches it.
- You may also alias or decorate `ContactFormFileUploaderInterface` in your services: the bundle uses your service.
- `ContactFormFileUploadHandlerInterface::upload(UploadedFile, ContactForm, ContactFormField)` is **deprecated** since
  1.8 (removed in 2.0); a service that implements only it keeps working (see [UPGRADING](UPGRADING.md#to-180)).

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

Since 1.8 the notification carries scalars for everything a notifier needs: `getSubmissionId()`, `getLocale()`,
`getClientReference()`, `getClientLabel()`, `isAnonymous()`, `getCreatedAt()`, `getFormName()`, `getFormSlug()`,
`getFieldValues()`, `getNotificationRecipient()`. `getSubmission()` (the entity) is deprecated and removed in 2.0.

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

## Events

| Event | When | Carries |
|-------|------|---------|
| `ContactSubmissionCreatedEvent` | After a submission was flushed (public form or recorder), before the notifier | `getSubmission()` (entity, detached after the dispatch); since 1.7 `getSubmissionId()`, `getFormSlug()`, `getLocale()`, `getClientReference()`, `getFieldValues()`, `getCreatedAt()` |
| `ContactSubmissionsDeletedEvent` (1.7) | After the admin deleted a submission, and after each flushed chunk of the retention cleanup | `getSubmissionIds()`, `getReason()` (`admin` / `retention`) |

## Using it from a DDD / hexagonal host

The bundle is its own bounded context: six bundle-owned entities, no foreign key to host tables. The host's client is
referenced by id only (`client_reference`, any id type). Both kinds of host are supported.

### Standard Symfony host (default)

The entities live on the default entity manager; the client of the public form is your security user (or the entity
`client_user_accessor` returns, `client_entity_class`); listeners may use `getSubmission()`. A file upload service may
still implement the deprecated `ContactFormFileUploadHandlerInterface` until 2.0; new code implements
`ContactFormFileUploaderInterface` ([File uploads](#file-uploads)).

### DDD / hexagonal host (opt-in)

```yaml
doctrine:
    orm:
        default_entity_manager: default
        entity_managers:
            default: { connection: default, mappings: { App: { type: xml, dir: '%kernel.project_dir%/config/doctrine', prefix: 'App\\Domain' } } }
            contact_form: { connection: contact_form }

nowo_contact_form:
    doctrine:
        entity_manager: contact_form       # 1.7: entities, flushes and resets on this manager only
    client_reference_resolver: App\Infrastructure\ContactForm\ClientReferenceResolver   # returns ClientReference(id, label)
```

```php
use Nowo\ContactFormBundle\Client\ClientReference;
use Nowo\ContactFormBundle\Service\ContactSubmissionRecorderInterface;

final class SendContactMessageHandler
{
    public function __construct(private ContactSubmissionRecorderInterface $recorder) {}

    public function __invoke(SendContactMessage $command): void
    {
        $submissionId = $this->recorder->record(
            formSlug: 'support',
            values: ['email' => $command->email, 'message' => $command->message],
            locale: $command->locale,
            client: new ClientReference((string) $command->customerId, $command->customerName),
            consentGivenAt: $command->consentGivenAt,
        );
    }
}
```

- The bundle never flushes, clears or resets your manager: submissions, admin edits and the retention cleanup write to
  `contact_form` only, and a closed `contact_form` manager is recovered on the next request or write (FrankenPHP worker
  mode, no services resetter needed). The entities are not mapped on your managers even with `auto_mapping: true`.
- `record()` takes plain values (no Symfony form, no request) and returns the submission id; it flushes the bundle's
  manager and dispatches `ContactSubmissionCreatedEvent` after that flush. Listen to the id getters and translate them
  into your own events / outbox rows.
- **Standard host and your own transaction (LI-CF-L1).** Without `doctrine.entity_manager`, the bundle's manager *is*
  your default entity manager: `record()` flushes it (your pending changes too), and the created event and the
  notification (e-mail) run right after that flush — **before** your transaction commits. If your transaction then
  rolls back, the submission is gone but the event and the e-mail already went out. When you call `record()` inside
  your own transaction, either use a dedicated manager (`doctrine.entity_manager`, its own connection) or call it
  after your commit.
- `client_reference` compares exactly: look submissions up with `client_reference = :id` (the id exactly as given; on
  MySQL / MariaDB the column has a binary, no-pad collation, see UPGRADING).
- A "host controls the transaction" mode (no flush) is not offered: submission ids are database identities, known only
  after the bundle's flush. With a dedicated manager the bundle's transaction never includes your writes.
- The retention cleanup (`nowo:contact-form:cleanup-submissions`) dispatches `ContactSubmissionsDeletedEvent` per chunk so you can
  erase your own copies. Since 1.8 it is also the `SubmissionRetentionCleanerInterface` port, for your own scheduler.
- **Ports without framework types (1.8).** File uploads go through `ContactFormFileUploaderInterface` with bundle VOs
  (`UploadedContactFile`, `ContactFormFileTarget`); notifiers read ids and scalars from `ContactSubmissionNotification`.
  Your adapter maps them to your own domain port:

```php
final class ContactFormAttachmentAdapter implements ContactFormFileUploaderInterface
{
    public function __construct(private AttachmentVault $vault) {}   // your domain port

    public function store(UploadedContactFile $file, ContactFormFileTarget $target): string
    {
        $stream = $file->openStream();
        try {
            return $this->vault->keep($target->formSlug, UploadedContactFile::lastSegment($file->getClientName()), $stream);
        } finally {
            fclose($stream);
        }
    }
}
```
