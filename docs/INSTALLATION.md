# Installation

## Table of contents

- [Requirements](#requirements)
- [Composer](#composer)
- [Enable the bundle](#enable-the-bundle)
  - [With Symfony Flex](#with-symfony-flex)
  - [Without Flex](#without-flex)
- [Database](#database)
- [Secure the admin UI](#secure-the-admin-ui)
- [GDPR cleanup](#gdpr-cleanup)

## Requirements

- **FormKitBundle** (`nowo-tech/form-kit-bundle` ^2.0) — dashboard/admin Symfony forms (`FormOptionsTrait`, profile `contact_form`). Register `NowoFormKitBundle` in `config/bundles.php` (Flex / demo). Optional host YAML: `config/packages/nowo_form_kit.yaml`.
- **UiKitBundle** (`nowo-tech/ui-kit-bundle` ^1.4) — admin Twig macros / `nowo-ui.css` asset package. Register `NowoUiKitBundle` and run `assets:install`.
- PHP `>=8.2` (<8.6). Symfony **8.0** and **8.1** require **PHP 8.4+**.
- Symfony **7.4**, **8.0**, or **8.1** (minimum tested minors).
- Doctrine ORM + `doctrine/doctrine-bundle`.
- `symfony/clock` (direct dependency; FrameworkBundle `clock` service preferred when present).
- `twig/extra-bundle` + `twig/string-extra` (REQ-TWIG-004; see below).
- `symfony/security-bundle` recommended for admin CRUD protection (see [Security](SECURITY.md)).

## Composer

```bash
composer require nowo-tech/contact-form-bundle
```

## Enable the bundle

### With Symfony Flex

The maintained Flex recipe lives under [`.symfony/recipe`](../.symfony/recipe) in this repository. When available via Flex / recipes-contrib it:

- registers `Nowo\ContactFormBundle\NowoContactFormBundle`
- copies `config/packages/nowo_contact_form.yaml`
- imports routes via `config/routes/nowo_contact_form.yaml`

Adjust configuration as needed (see [Configuration](CONFIGURATION.md)). Until the recipe is published upstream, use the **Without Flex** steps or copy files from `.symfony/recipe/nowo-tech/contact-form-bundle/1.0/`.

### Without Flex

1. Register the bundle in `config/bundles.php`:

```php
Nowo\ContactFormBundle\NowoContactFormBundle::class => ['all' => true],
```

2. Import routes in `config/routes.yaml` (or a dedicated file):

```yaml
nowo_contact_form:
    resource: '@NowoContactFormBundle/Resources/config/routes.yaml'
```

3. Copy defaults from `vendor/nowo-tech/contact-form-bundle/src/Resources/config/packages/nowo_contact_form.yaml` to `config/packages/nowo_contact_form.yaml`.

## Database

Ensure Doctrine is configured; bundle entities are auto-mapped. Create the schema:

- `php bin/console doctrine:schema:update --force`, **or**
- add a migration in your application (see reference SQL in `demo/symfony8/migrations/Version20250619100000.php`).

Tables created: `nowo_contact_form`, `nowo_contact_form_translation`, `nowo_contact_form_field`, `nowo_contact_form_field_translation`, `nowo_contact_submission`, `nowo_contact_submission_value`.

## Secure the admin UI

Protect the admin path prefix (default `/admin/contact-forms`) in the host firewall:

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin/contact-forms, roles: ROLE_ADMIN }
```

Keep `nowo_contact_form.security.allow_unauthenticated: false` in production. See [Configuration](CONFIGURATION.md) and [Usage](USAGE.md).

## GDPR cleanup

Schedule in production:

```bash
php bin/console nowo:contact-form:cleanup-submissions
```

See [`CONFIGURATION.md`](CONFIGURATION.md).

## Twig Extra Bundle (REQ-TWIG-004)

This package ships Twig templates. Host applications **must** install and enable Twig Extra:

```bash
composer require twig/extra-bundle twig/string-extra
```

Register `Twig\Extra\TwigExtraBundle\TwigExtraBundle` in `config/bundles.php` (Flex usually does this). Demos already include the same stack. The package `release-check` runs `make check-twig-extra` to guard this contract.
