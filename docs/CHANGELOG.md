# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

## [1.0.3] - 2026-07-20

### Added

- **Tests**: Unit coverage for `ContactFormSubmissionRateLimiter` (limit, interval reset, null cache, missing client IP).
- **Contributor Covenant** [`CODE_OF_CONDUCT.md`](../CODE_OF_CONDUCT.md).
- **REQ-GIT-001 tooling**: `.scripts/check-no-cursor-coauthor.sh`, `.scripts/strip-cursor-coauthor-from-history.sh`, `.githooks/commit-msg`, Cursor rule `.cursor/rules/01-git-commits.mdc`, and [`docs/GITHUB_CI.md`](GITHUB_CI.md).
- **CI**: `git-hygiene` job that audits full history for Cursor co-author trailers.
- **Makefile**: `check-no-cursor-coauthor`, `setup-hooks`, and `strip-cursor-coauthor-from-history`; `release-check` depends on the co-author check.

### Changed

- **Documentation**: `README`, `CONTRIBUTING`, and `RELEASE` document Code of Conduct, git hooks, and the post-tag co-author re-check before push.

## [1.0.2] - 2026-07-13

### Fixed

- **CI (PHP 8.4 / 8.5)**: CI matrix installs `symfony/var-exporter` (required by Doctrine ORM 3 lazy ghost proxies in integration tests).

### Changed

- **Documentation**: `CONFIGURATION.md` documents rate limiting, CSRF, and remaining bundle options; `SECURITY.md` and `RELEASE.md` aligned with host-app CSRF setup and `master` branch workflow.

## [1.0.1] - 2026-07-13

### Fixed

- **CI / Symfony 7.0**: Unit tests no longer fail when the Form component is bootstrapped without the CSRF extension; public forms rely on host `framework.form.csrf_protection` configuration instead of hardcoded builder options.
- **DI**: `ContactFormSubmissionRateLimiter` is registered only via the bundle extension (excluded from autodiscovery).
- **CI**: Matrix installs `symfony/security-bundle` and `symfony/security-csrf` for CSRF-enabled form tests.

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
