# Contact Form Bundle — Demo

Symfony 8 demo with Doctrine (SQLite), migrations, seeded contact forms, and **notification examples without symfony/mailer**.

## Quick start

```bash
make -C demo/symfony8 up   # http://localhost:8021
```

Or via the aggregate Makefile:

```bash
make -C demo up DEMO=symfony8
```

Each `make up` runs:

1. `composer install`
2. `doctrine:migrations:migrate`
3. `assets:install` (Phone Input Bundle CSS/flags)
4. `app:seed-contact-demo` (creates three forms: `contact`, `job-application`, `partner-inquiry`)

## Demo contact forms

| Slug | Purpose | Field types |
| --- | --- | --- |
| `contact` | General support | email, **phone (Symfony prefix selector)**, text, select, textarea + GDPR consent |
| `job-application` | HR application | text, email, **phone (Phone Input Bundle)**, url, number, date, select, textarea, file, checkbox + consent |
| `partner-inquiry` | B2B leads | text, email, url, number, select, textarea, file, checkbox (no form-level consent) |

Together they showcase every supported `ContactFieldType`. Open the demo home page or use the **Public forms** nav dropdown.

### Phone field widgets

Phone fields can use either widget in the admin field wizard (`phone_prefixes` step):

| Widget | Form type | Demo form |
| --- | --- | --- |
| `symfony` | Built-in `ContactPhoneType` with configurable prefixes | `contact` |
| `phone_input` | `nowo-tech/phone-input-bundle` (`PhoneType`) with flags and country search | `job-application` |

The demo installs `nowo-tech/phone-input-bundle` from Packagist (`^1.1`). Configure defaults in `config/packages/nowo_phone_input.yaml` and bundle-level options in `config/packages/nowo_contact_form.yaml` under `phone_input`.

Field `options` JSON examples:

```php
// Symfony widget
['widget' => 'symfony', 'prefixes' => ['+34', '+1', '+44']]

// Phone Input Bundle
['widget' => 'phone_input', 'default_country' => 'ES', 'allowed_countries' => ['ES', 'US', 'GB']]
```

Legacy phone fields that store only a prefix list (e.g. `['+34', '+1']`) still default to the Symfony widget.

## Notification examples

Configured in `config/packages/nowo_contact_form.yaml`:

| Class | Pattern |
| --- | --- |
| `LoggingContactSubmissionNotifier` | PSR-3 logger |
| `FileContactSubmissionNotifier` | Append JSON lines to `var/log/contact-submissions-demo.log` |
| `WebhookContactSubmissionNotifier` | Simulated webhook payload in `var/log/contact-submissions-webhook-demo.log` |
| `CompositeContactSubmissionNotifier` | Chains all three (registered as `notifications.service`) |
| `ContactSubmissionDemoSubscriber` | Alternative via `ContactSubmissionCreatedEvent` |

Submit any public form, then check the log files and application logs.

### Email (optional)

See `config/packages/nowo_contact_form_mailer.yaml.example` — requires `symfony/mailer` and `notifications.mailer.enabled: true`.

## Commands

```bash
make -C demo/symfony8 shell
php bin/console app:seed-contact-demo
php bin/console nowo:contact-form:cleanup-submissions --dry-run
```
