# Upgrading

This document describes how to upgrade between versions of Contact Form Bundle.

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
