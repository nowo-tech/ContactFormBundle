# Upgrading

This document describes how to upgrade between versions of Contact Form Bundle.

## 1.0.5 (2026-07-20)

No breaking changes for application installs.

- **Application installs**: No action required. Fix is limited to the bundle’s integration test kernel.
- **Host apps on doctrine-bundle 3.x**: If you still set `auto_generate_proxy_classes` / `enable_lazy_ghost_objects`, remove them (they were removed in doctrine-bundle 3.0).

## 1.0.4 (2026-07-20)

No breaking changes for application installs.

- **Symfony 8 host apps**: Prefer `doctrine/doctrine-bundle` `^3.1` (2.x does not support Symfony 8). The bundle constraint remains `^2.13 || ^3.0` for Symfony 7.4.
- **Demos**: `demo/symfony7` was removed; use `demo/symfony8` only (`http://localhost:8021`).
- **Demo migrations**: If `make -C demo/symfony8 up` previously failed with duplicate `select_options`, pull 1.0.4 (or delete `demo/symfony8/var/data.db`) and run `up` again.
- **Contributors**: Root and demo Makefiles use `docker compose` (Compose V2 plugin) instead of standalone `docker-compose`.

## 1.0.3 (2026-07-20)

No breaking changes.

- **Application installs**: No action required. Changes are tests, documentation, and contributor/CI git hygiene (REQ-GIT-001).
- **Contributors**: Run `make setup-hooks` once per clone. Do not add Cursor co-author trailers to commits. See [GITHUB_CI.md](GITHUB_CI.md) and [Contributing](CONTRIBUTING.md).

## 1.0.2 (2026-07-13)

No breaking changes.

- **Integrators**: No action required. CI/test dependency (`symfony/var-exporter`) and documentation only.

## 1.0.1 (2026-07-13)

No breaking changes.

- **CSRF on public forms**: The bundle no longer sets `csrf_protection` / `csrf_field_name` on every dynamic form. Enable CSRF in your Symfony app (`framework.form.csrf_protection.enabled: true` and `symfony/security-csrf` installed). See [Security](SECURITY.md).

## 1.0.0 (2026-07-13)

First stable release. No upgrade steps when installing for the first time.

- **PHP**: 8.2 or higher (below 8.6).
- **Symfony**: 7.4 or 8.0+ (admin field wizard requires Symfony Form **8.1+**).
- **Doctrine ORM**: 2.17+ or 3.x with `doctrine/doctrine-bundle` ^2.13 or ^3.0.

### Fresh install checklist

1. `composer require nowo-tech/contact-form-bundle`
2. Register `Nowo\ContactFormBundle\NowoContactFormBundle` and import bundle routes (see [Installation](INSTALLATION.md)).
3. Apply schema (`doctrine:schema:update` or application migration; reference SQL in `demo/symfony8/migrations/`).
4. Copy `config/packages/nowo_contact_form.yaml` defaults from the vendor package or Flex recipe.
5. Secure admin routes in your application firewall (see [Usage](USAGE.md)).
6. Schedule `nowo:contact-form:cleanup-submissions` in production for GDPR retention.

### Optional integrations

- **symfony/mailer** — email notifications (`notifications.mailer.enabled`).
- **nowo-tech/phone-input-bundle** — rich phone fields with country selector.
- **symfony/security-bundle** — automatic client linking for authenticated users.

## Unreleased / 1.x

When breaking or notable changes ship in future 1.x releases, they will be documented here.
