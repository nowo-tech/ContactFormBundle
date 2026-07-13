# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

## [1.0.0] - 2026-07-13

First stable release of **Contact Form Bundle** (`nowo-tech/contact-form-bundle`).

### Added

- **Admin CRUD** for contact forms, customizable fields (wizard on Symfony 8.1+), and submissions with translation tabs.
- **Public form** at `/contact/{slug}` with PRG flow, optional CSRF, rate limiting, and flash success message.
- **Field types**: text, email, phone (`ContactPhoneType`, optional `nowo-tech/phone-input-bundle`), textarea, select, rich text, and file upload (via `ContactFormFileUploadHandlerInterface`).
- **Multilingual** copy via `ContactFormTranslation` / `ContactFormFieldTranslation` entities.
- **GDPR**: consent checkbox, privacy policy link, IP anonymization (`IpAnonymizer`), per-form retention days, and `nowo:contact-form:cleanup-submissions` CLI.
- **Client association**: optional link to a host client entity (`ClientResolverInterface`, `SecurityClientResolver`) or anonymous submissions.
- **Notifications**: `ContactSubmissionNotifierInterface`, Symfony Mailer implementation, `ContactSubmissionCreatedEvent`, and null notifier fallback.
- **Services**: `ContactSubmissionProcessor`, `DynamicContactFormBuilder`, `ContactFormSubmissionValueNormalizer`, `ContactFormRichTextSanitizer`, `SubmissionRetentionCleanupService`.
- **Twig**: admin and public templates; `TwigPathsPass` so app overrides in `templates/bundles/NowoContactFormBundle/` take precedence.
- **Translations**: `NowoContactFormBundle` domain (en, es, de, fr, it, nl, pt).
- **Demos**: Symfony 7 and 8 FrankenPHP demos with seed command, composite notifiers, and HTTP smoke (`make -C demo release-verify`).
- **Tests**: PHPUnit unit and integration suites with **99.78%** line coverage; SQLite integration kernel with CSRF-ready security stack.
- **Tooling**: PHP-CS-Fixer, PHPStan, Rector, Scrutinizer config, `make release-check`, CI matrix (Symfony 7.4 / 8.0 / 8.1).
- **Documentation**: installation, configuration, usage, upgrading, security, FrankenPHP demos, spec-driven development overview.
- **Symfony Flex recipe** under `.symfony/recipe/nowo-tech/contact-form-bundle/1.0/`.

### Fixed

- **Documentation / GitHub**: `docs/SECURITY.md`, `.github/SECURITY.md`, issue templates, and `CODEOWNERS` aligned with ContactFormBundle (removed IconSelectorBundle copy-paste).
- **Demos**: `release-verify` runs `update-bundle`; Symfony 7 demo registers `DoctrineMigrationsBundle`; healthchecks accept HTTP 2xx/3xx.

### Changed

- **README**: badges, Symfony 7.4 | 8.0 | 8.1+ compatibility label, coverage badge.
- **`docs/DEMO-FRANKENPHP.md`**, **`docs/INSTALLATION.md`**, **`docs/USAGE.md`**: aligned with contact-form install, schema, and admin security guidance.
- **`.scrutinizer.yml`**: PHP/coverage checks only (no missing frontend test step); **`docs/RELEASE.md`** updated accordingly.
