# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.0.17] - 2026-08-19](#1017-2026-08-19)
- [[1.0.16] - 2026-08-19](#1016-2026-08-19)
- [[1.0.15] - 2026-08-18](#1015-2026-08-18)
- [[1.0.14] - 2026-08-04](#1014-2026-08-04)
  - [Added](#added)
  - [Changed](#changed)
- [[1.0.13] - 2026-07-30](#1013-2026-07-30)
  - [Added](#added)
  - [Changed](#changed)
- [[1.0.12] - 2026-07-29](#1012-2026-07-29)
  - [Changed](#changed)
- [[1.0.11] - 2026-07-28](#1011-2026-07-28)
  - [Added](#added)
  - [Changed](#changed)
- [[1.0.10] - 2026-07-27](#1010-2026-07-27)
  - [Added](#added)
  - [Changed](#changed)
- [[1.0.9] - 2026-07-27](#109-2026-07-27)
  - [Added](#added)
  - [Changed](#changed)
- [[1.0.8] - 2026-07-24](#108-2026-07-24)
  - [Added](#added)
  - [Changed](#changed)
  - [Removed](#removed)
- [[1.0.7] - 2026-07-22](#107-2026-07-22)
  - [Added](#added)
  - [Changed](#changed)
- [[1.0.6] - 2026-07-22](#106-2026-07-22)
  - [Fixed](#fixed)
  - [Changed](#changed)
- [[1.0.5] - 2026-07-20](#105-2026-07-20)
  - [Fixed](#fixed)
- [[1.0.4] - 2026-07-20](#104-2026-07-20)
  - [Changed](#changed)
  - [Fixed](#fixed)
- [[1.0.3] - 2026-07-20](#103-2026-07-20)
  - [Added](#added)
  - [Changed](#changed)
- [[1.0.2] - 2026-07-13](#102-2026-07-13)
  - [Fixed](#fixed)
  - [Changed](#changed)
- [[1.0.1] - 2026-07-13](#101-2026-07-13)
  - [Fixed](#fixed)
- [[1.0.0] - 2026-07-13](#100-2026-07-13)
  - [Added](#added)
  - [Fixed](#fixed)
  - [Changed](#changed)

## [Unreleased]

## [1.0.17] - 2026-08-19

### Fixed

- Allow `doctrine/orm` 3.6.8 again (removed the temporary `<3.6.8` constraint from 1.0.16).
- Integration TestKernel uses array cache adapters so SchemaTool does not hit `DoctrineDbalCacheAdapterSchemaListener` / DBAL `Schema::edit` on DBAL 4.4.

## [1.0.16] - 2026-08-19

### Added

- `nowo_contact_form.doctrine.table_prefix` — optional prefix for entity tables (`nowo_contact_form`, `nowo_contact_submission`, …), same pattern as BlogKit / CookieConsent. Empty (default) keeps hardcoded table names.

### Changed

- Temporarily constrained `doctrine/orm` below 3.6.8 in 1.0.16 CI; lifted in **1.0.17** (prefer upgrading to 1.0.17).

## [1.0.15] - 2026-08-18

### Changed

- **Demos:** pin `nowo-tech/hot-reload-bundle` to `^1.4` with FrankenPHP Mercure/`hot_reload` (`dev`/`test` only).
- **UiKit:** Admin templates use `ui.btn` / `ui.row_actions` macros with `nowo_contact_form_css_framework` instead of hard-coded Bootstrap button classes.

## [1.0.14] - 2026-08-04

### Added

- **REQ-TWIG-004:** require `twig/extra-bundle` + `twig/string-extra`; `make check-twig-extra` in `release-check`; demos register `TwigExtraBundle`.
- **Twig-CS-Fixer:** `vincentlanglet/twig-cs-fixer`, `.twig-cs-fixer.php`, `composer twig:lint` / `twig:fix`.

### Changed

- **REQ-UI-001-kit:** Requires **[UiKitBundle](https://github.com/nowo-tech/UiKitBundle)** (`nowo-tech/ui-kit-bundle` `^1.4`). Admin `base.html.twig` loads `asset('css/nowo-ui.css', 'nowo_ui_kit')` and imports `@NowoUiKitBundle/macros/ui.html.twig` (flashes via `ui.flash`, primary list toolbar via `ui.btn` on form index). Extension implements `PrependExtensionInterface` and seeds `nowo_ui_kit` from `web_ui.css_framework` / `icon_set` when the host has not configured UiKit.
- **FormKitBundle:** depend on [`nowo-tech/form-kit-bundle`](https://github.com/nowo-tech/FormKitBundle) ^2.0. Admin form types use `FormOptionsTrait` + profile `contact_form` (`#[FormKitConfig]`). Extension prepends that profile when missing; form types are tagged `form.type` so `FormOptionsMerger` is injected.
- **Dependencies:** require `symfony/clock` (`^7.4 || ^8.0`) for the `NativeClock` fallback when the host has no `clock` service (was only `psr/clock`).

## [1.0.13] - 2026-07-30

### Added

- **Admin Twig (REQ-UI-001)**: `admin/base.html.twig` page shell extends configurable `web_ui.layout_template` and stacks `nowo_ui_styles` / `nowo_ui_scripts` with `{{ parent() }}`.

### Changed

- **Admin Twig**: Form/field/submission templates extend `admin/base.html.twig` instead of the layout global directly; default `admin/layout.html.twig` remains a full HTML root (no `parent()`).
- **Docs**: README documentation links section reorder; Configuration / Usage / inventory updated for the base layout stack.
- **Dependencies (lock)**: Symfony 7.4.x patch bumps and `nowo-tech/phpstan-frankenphp` / `phpstan/phpstan` updates in `composer.lock`.

## [1.0.12] - 2026-07-29

### Changed

- **Makefiles (REQ-MAKE-010)**: Prefer Docker Compose V2 (`docker compose`) with fallback to `docker-compose` V1 in root, demo aggregate, and `demo/symfony8` Makefiles; demo uses absolute `docker` path to avoid shadowing by a local `docker/` directory.
- **Makefiles (REQ-MAKE-009)**: Monorepo `update-deps` helpers use soft `-include` so standalone clones and GitHub Actions checkouts no longer fail on missing `../.scripts/` paths.

## [1.0.11] - 2026-07-28

### Added

- **Docs (REQ-DOCS-005 / REQ-TEST-003)**: Table of contents on long docs; `docs/COVERAGE.md` documents PHPUnit coverage exclusions.
- **Spec Kit (REQ-SPECKIT-001 / REQ-SPECKIT-003)**: Code inventory + `FR-SEC-001` for admin access checker / security compiler pass / access subscriber; pagination Twig partial mapped.
- **Dependencies**: Direct `psr/clock` requirement for clock-injected services.

### Changed

- **DI (REQ-DI-001)**: `Psr\Clock\ClockInterface` injected into submission rate limiter, submission processor, and retention cleanup (tests use `MockClock`).
- **CI / PHPUnit (REQ-SF-005)**: `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0` in `phpunit.xml.dist` and CI test jobs.
- **GitHub About (REQ-DOCS-018)**: Topic `frankenphp` added.
- **Tooling**: `check-open-prs` resolves `owner/repo` from `origin` when `gh` cannot map the git remote.

## [1.0.10] - 2026-07-27

### Added

- **Tooling (REQ-REL-003 / REQ-MAKE-002)**: `make check-open-prs` (`.scripts/check-open-prs.sh`) is part of `release-check` and fails when unresolved open GitHub PRs remain.

### Changed

- **CI**: `actions/checkout` bumped from v6 to v7 (#9).
- **Docs**: `RELEASE.md` documents the open-PR gate before tagging.

## [1.0.9] - 2026-07-27

### Added

- **Flex recipe (REQ-RECIPE-001)**: Complete `.symfony/recipe` with `copy-from-recipe`, routes import, `post-install.txt`, and Flex section in `INSTALLATION.md`.
- **Admin Web UI (REQ-UI-001 / REQ-UI-002)**: `web_ui` layout/css/icon config, Twig globals, semantic `nowo-ui-*` hooks; `security.access_roles` + `ContactFormAccessCheckerInterface` + admin access subscriber; host firewall guidance.
- **Pagination (REQ-PERF-001)**: Admin form and submission lists paginated via `web_ui.list_page_size` (default `20`).

### Changed

- **Admin routes**: Path prefix from `admin_route_prefix` via `routes.yaml` (index URL ends with `/`).
- **Security posture**: Documented bundled admin auth; demo uses `allow_unauthenticated: true` (demo-only).
- **GitHub About (REQ-DOCS-018)**: Description, Packagist website, and topics set on the repository.
- **Coverage**: PHP line coverage badge refreshed to **99.45%**.

## [1.0.8] - 2026-07-24

### Added

- **Tooling (REQ-CS-005)**: `nowo-tech/phpstan-frankenphp` in `require-dev` with `ruleset-classic` + `ruleset-worker` in `phpstan.neon.dist`.
- **Documentation (REQ-DOCS-017)**: FrankenPHP Friendly Worker Mode banner and canonical claim in root `README.md` (`docs/images/frankenphp-friendly.png`).

### Changed

- **Static analysis**: Form/service PHPDoc generics for Symfony `AbstractType` / `FormInterface` / `FormBuilderInterface` (`TData`); removed prior `ignoreErrors` for missing generics.
- **Demo (Symfony 8)**: FrankenPHP image bumped to PHP **8.5**; `.env.example` comments per variable; `.gitignore` ignores `/.pnpm-store` (REQ-GITIGNORE-003); `make up` removes stray `.env.dev`; demo README rewritten for Contact Form (was Icon Selector copy-paste).
- **Documentation**: `DEMO-FRANKENPHP.md` PHP version table aligned to 8.5; README worker-mode wording matches `FRANKENPHP_MODE=worker`.

### Removed

- **Docs assets**: Unused Icon Selector screenshots (`docs/images/demo-grid.png`, `demo-tom-select.png`).

## [1.0.7] - 2026-07-22

### Added

- **Demo (Symfony 8)**: `FRANKENPHP_MODE` (`worker` default, or `classic`) via `.env` / Compose; dedicated `docker/entrypoint.sh` (`REQ-DEMO-010`).

### Changed

- **Documentation**: `DEMO-FRANKENPHP.md` documents classic vs worker switching and aligns with the demo default (worker).
- **Code style**: Applied pending Rector rules (`readonly` services, stricter type checks, test `self::` cleanup).
- **Static analysis**: Cleared PHPStan level 8 (options typing, Doctrine EM helpers in integration tests, and related assertions).
- **Dependencies (lock)**: Bumped `doctrine/doctrine-bundle` in the root and demo locks.

## [1.0.6] - 2026-07-22

### Fixed

- **Twig**: Public form render uses `@NowoContactFormBundle/...` (aligned with admin controllers and `TwigPathsPass` namespace).
- **TwigPathsPass**: Prepends `templates/bundles/NowoContactFormBundle` when present so app overrides win; resolves Twig loader aliases and falls back to `twig.loader.filesystem`.

### Changed

- **Dependencies (lock)**: Bumped `doctrine/dbal` and `friendsofphp/php-cs-fixer` in root and demo locks.

## [1.0.5] - 2026-07-20

### Fixed

- **Tests / doctrine-bundle 3.x**: Integration `TestKernel` no longer sets removed ORM options `auto_generate_proxy_classes` / `enable_lazy_ghost_objects` (breaks Symfony 7.4/8 + doctrine-bundle 3.x in CI).

## [1.0.4] - 2026-07-20

### Changed

- **Demos**: Removed `demo/symfony7`; FrankenPHP demo is Symfony 8 only (`demo/symfony8`, port 8021).
- **Makefile**: Compose commands use `docker compose` (Compose V2 plugin) instead of `docker-compose` (root and `demo/symfony8`).

### Fixed

- **CI (Symfony 8.x)**: Matrix installs `doctrine/doctrine-bundle` `^3.1` on Symfony 8 (2.x only supports Symfony 6/7; SF8 support starts at 3.1), updates `doctrine/*` with Symfony packages, and keeps security/csrf/var-exporter in `require-dev` via `composer require --dev`.
- **CI matrix**: Dropped Symfony 7.0 cells (bundle requires `^7.4 || ^8.0`); coverage job uses Symfony 7.4.
- **`composer.json`**: `symfony/security-core` allowlist includes `^8.0`.
- **Demo (Symfony 8)**: Migration `Version20250620130000` is a no-op — `select_options` is already created in the initial schema, so fresh `make up` no longer fails on duplicate column.
- **Demo Makefile**: Healthcheck wait loop no longer exits early; `release-verify` prefers `PORT` from `.env` over `.env.example`.

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
