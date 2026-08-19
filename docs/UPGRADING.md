# Upgrading

This document describes how to upgrade between versions of Contact Form Bundle.

## Table of contents

- [Unreleased](#unreleased)
- [1.0.17 (2026-08-19)](#1017-2026-08-19)
- [1.0.16 (2026-08-19)](#1016-2026-08-19)
- [1.0.15 (Symfony 8 demos / Hot Reload 1.4)](#1015-symfony-8-demos--hot-reload-14)
- [1.0.14 (2026-08-04)](#1014-2026-08-04)
- [1.0.13 (2026-07-30)](#1013-2026-07-30)
- [1.0.12 (2026-07-29)](#1012-2026-07-29)
- [1.0.11 (2026-07-28)](#1011-2026-07-28)
- [1.0.10 (2026-07-27)](#1010-2026-07-27)
- [1.0.9 (2026-07-27)](#109-2026-07-27)
- [1.0.8 (2026-07-24)](#108-2026-07-24)
- [1.0.7 (2026-07-22)](#107-2026-07-22)
- [1.0.6 (2026-07-22)](#106-2026-07-22)
- [1.0.5 (2026-07-20)](#105-2026-07-20)
- [1.0.4 (2026-07-20)](#104-2026-07-20)
- [1.0.3 (2026-07-20)](#103-2026-07-20)
- [1.0.2 (2026-07-13)](#102-2026-07-13)
- [1.0.1 (2026-07-13)](#101-2026-07-13)
- [1.0.0 (2026-07-13)](#100-2026-07-13)
  - [Fresh install checklist](#fresh-install-checklist)
  - [Optional integrations](#optional-integrations)

## Unreleased

## 1.0.17 (2026-08-19)

Prefer this over 1.0.16 if the host pins `doctrine/orm` 3.6.8. No config changes; `doctrine.table_prefix` from 1.0.16 remains.

## 1.0.16 (2026-08-19)

Optional Doctrine table prefix. Backward compatible when left empty.

```yaml
nowo_contact_form:
    doctrine:
        table_prefix: ''   # default — keep nowo_contact_form, nowo_contact_submission, …
        # table_prefix: 'app_'  # → app_nowo_contact_form, …
```

If you set a non-empty prefix on an existing database, rename tables (or migrate) to match before deploying.

**Note:** 1.0.16 temporarily required `doctrine/orm <3.6.8`. Use **1.0.17+** with ORM 3.6.8.

## 1.0.15 (Symfony 8 demos / Hot Reload 1.4)

- **No application upgrade steps.** No application upgrade steps. **Demos only:** Hot Reload Bundle `^1.4` (FrankenPHP Mercure/`hot_reload`, `dev`/`test`).

## 1.0.14 (2026-08-04)

Notable dependency and admin UI stack updates. No schema changes.

### UiKitBundle (admin UI)

Admin UI now depends on **[UiKitBundle](https://github.com/nowo-tech/UiKitBundle)** (`nowo-tech/ui-kit-bundle` `^1.4`).

1. Require the package (pulled transitively once you update this bundle) and run `assets:install`.
2. Stylesheet package: `asset('css/nowo-ui.css', 'nowo_ui_kit')` via `admin/base.html.twig`.
3. Optional: set `nowo_ui_kit.css_framework` / `icon_set` in the host. If unset, ContactForm seeds those keys from `web_ui.css_framework` / `icon_set`.
4. Template overrides: extend `@NowoContactFormBundle/admin/base.html.twig` and use UiKit macros (`ui.flash`, `ui.btn`) instead of hard-coded Bootstrap alert/button classes where applicable.

### FormKitBundle (admin forms)

Ensure `nowo-tech/form-kit-bundle` ^2.0 is installed (pulled transitively) and `Nowo\FormKitBundle\NowoFormKitBundle` is registered. Form types use profile `contact_form` via `#[FormKitConfig]`; the bundle prepends that profile when the host has not defined it. Optional host YAML: `config/packages/nowo_form_kit.yaml`.

### Twig Extra Bundle (REQ-TWIG-004)

Hosts that render this bundle's Twig templates must install:

```bash
composer require twig/extra-bundle twig/string-extra
```

and enable `Twig\Extra\TwigExtraBundle\TwigExtraBundle`. Flex recipes usually register it automatically (also pulled as a direct dependency of this package).

### Clock

`symfony/clock` is now a direct dependency for the `NativeClock` fallback when the host has no `clock` service. No action if FrameworkBundle already provides `clock`.

### Twig-CS-Fixer (maintainers)

Package maintainers: `composer twig:lint` / `composer twig:fix` use `.twig-cs-fixer.php` over `src/` (and `templates/` when present).

## 1.0.13 (2026-07-30)

No schema changes. Backward compatible for typical `web_ui.layout_template` installs.

- **Application installs**: Prefer this release if you embed admin UI in a host layout. Bundle admin pages now extend `@NowoContactFormBundle/admin/base.html.twig`, which extends your `web_ui.layout_template` and calls `{{ parent() }}` for styles/scripts. Keep the host (or bridge) layout as a full HTML document without `parent()` in those blocks.
- **Template overrides**: If you override individual admin page templates, extend `@NowoContactFormBundle/admin/base.html.twig` (or an equivalent shell) instead of the layout global alone.
- **Otherwise**: Config keys and defaults are unchanged.

## 1.0.12 (2026-07-29)

No breaking changes for application installs.

- **Application installs**: No action required. Package API and configuration are unchanged.
- **Contributors / demo**: Makefiles detect Compose V2 first and fall back to V1. Soft `-include` of monorepo `update-deps` helpers means `make` works in a standalone clone without `bundles/.scripts/`. Prefer `docker compose` if both CLIs are available.

## 1.0.11 (2026-07-28)

No schema changes. Backward compatible for typical DI installs.

- **Application installs**: Prefer pulling this release so admin/time-sensitive services receive `Psr\Clock\ClockInterface` (usually auto-wired as `symfony/clock`). If you instantiate `ContactSubmissionProcessor`, `ContactFormSubmissionRateLimiter`, or `SubmissionRetentionCleanupService` manually, pass a clock implementation.
- **Contributors**: PHPUnit / CI fail on direct Symfony deprecations (`SYMFONY_DEPRECATIONS_HELPER=max[direct]=0`). Coverage exclusions are documented in [COVERAGE.md](COVERAGE.md).

## 1.0.10 (2026-07-27)

No breaking changes for application installs.

- **Application installs**: No action required. Package API and configuration are unchanged.
- **Contributors / maintainers**: `make release-check` now runs `check-open-prs` (REQ-REL-003). Resolve or hold open GitHub PRs before tagging. See [RELEASE.md](RELEASE.md).

## 1.0.9 (2026-07-27)

Additive configuration for admin Web UI security and layout (backward compatible defaults).

- **New config**: `web_ui.*` (`layout_template`, `css_framework`, `icon_set`, `list_page_size`) and `security.*` (`access_roles`, `access_checker`, `allow_unauthenticated`).
- **Admin access**: By default admin routes require `ROLE_ADMIN` via `ContactFormAccessCheckerInterface`. Hosts without `symfony/security-bundle` must set `security.allow_unauthenticated: true` (demo/dev only) or install SecurityBundle. Production hosts should keep `allow_unauthenticated: false` and add `access_control` for `admin_route_prefix`.
- **Admin lists**: Forms and submissions indexes are paginated (`web_ui.list_page_size`, default `20`).
- **Twig**: Admin pages extend `nowo_contact_form_layout_template`. Override `web_ui.layout_template` to use your app layout (or a one-file bridge).
- **Admin index URL**: Canonical path is `/admin/contact-forms/` (trailing slash). Requests without the slash may 301 to the slash form.
- **Flex recipe**: Documented under `.symfony/recipe`; see [INSTALLATION.md](INSTALLATION.md).
- **Otherwise**: No schema changes; existing form/field/submission data is unchanged.

## 1.0.8 (2026-07-24)

No breaking changes for application installs.

- **Application installs**: No action required. Package API and configuration are unchanged.
- **Contributors / CI**: PHPStan now loads FrankenPHP classic + worker rulesets (`nowo-tech/phpstan-frankenphp`). Run `composer update nowo-tech/phpstan-frankenphp` (or full `composer update`) in the bundle repo if you develop against this release.
- **Demo contributors**: Symfony 8 demo image is PHP **8.5** (`dunglas/frankenphp:1-php8.5-alpine`). Rebuild the demo image after pull (`make -C demo/symfony8 build` then `make up`). `FRANKENPHP_MODE` defaults remain as in 1.0.7.

## 1.0.7 (2026-07-22)

No breaking changes for application installs.

- **Application installs**: No action required. Changes are limited to the FrankenPHP demo and docs.
- **Demo contributors**: The demo defaults to `FRANKENPHP_MODE=worker`. Set `classic` in `demo/symfony8/.env` for per-request PHP / easier hot-reload, then recreate the container (`docker compose up -d` or `make up`). See [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md).

## 1.0.6 (2026-07-22)

Notable for apps that reference bundle Twig templates by logical name.

- **Twig namespace** — Prefer `@NowoContactFormBundle/...` (public form and admin already use this). If you still call `@NowoContactForm/...`, switch to `@NowoContactFormBundle/...`.
- **Application overrides** — Keep overrides under `templates/bundles/NowoContactFormBundle/...`; `TwigPathsPass` now prepends that path so they take precedence for the `NowoContactFormBundle` Twig namespace.
- **Otherwise**: No schema or configuration changes.

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
