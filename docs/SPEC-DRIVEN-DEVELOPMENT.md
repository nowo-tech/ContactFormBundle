# Spec-driven development

In this repository, **spec-driven development** has three layers that stay in sync:

1. **GitHub Spec Kit baseline** — [`specs/001-baseline/`](../specs/001-baseline/) ([`spec.md`](../specs/001-baseline/spec.md), [`code-inventory.md`](../specs/001-baseline/code-inventory.md)), initialized with [GitHub Spec Kit](https://github.com/github/spec-kit) (`.specify/`, **Cursor Agent** skills in `.cursor/skills/speckit-*`). The inventory maps **100%** of production code in `src/`. **How to install, initialize, and use Spec Kit:** [`SPEC-KIT.md`](SPEC-KIT.md).
2. **Product behavior** — what **ContactFormBundle** guarantees to applications that integrate it (see [`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md), [`INSTALLATION.md`](INSTALLATION.md)). **PHPUnit** and **PHPStan** (and **Vitest** when applicable) enforce contracts in CI where applicable.
3. **Traceability anchors** — stable **`REQ-*`** identifiers in Makefiles and demos (when present) so changes to scripts, ports, and demo workflows stay discoverable from issues and PRs.

There is no separate executable spec language (for example Gherkin); Spec Kit specs, tests, and static analysis are the mechanical proof alongside this document.

---

## Table of contents

- [User stories](#user-stories)
- [Bundle functional scope](#bundle-functional-scope)
- [Validating the functional spec](#validating-the-functional-spec)
- [Requirement identifiers (`REQ-*`)](#requirement-identifiers-req-)
- [Suggested workflow for contributors](#suggested-workflow-for-contributors)
- [Relationship to Engram / external checklists](#relationship-to-engram-external-checklists)
- [GitHub Spec Kit (summary)](#github-spec-kit-summary)
- [See also](#see-also)

## User stories

| ID | Story |
| --- | --- |
| US-01 | **As an** integrator, **I want** to define contact forms with customizable fields **so that** I can collect the contact data my business needs. |
| US-02 | **As an** integrator, **I want** multilingual form copy **so that** visitors see labels and GDPR text in their locale. |
| US-03 | **As an** integrator, **I want** GDPR consent and IP anonymization **so that** submissions comply with data protection requirements. |
| US-04 | **As an** integrator, **I want** to link submissions to existing clients or accept anonymous contacts **so that** CRM workflows stay flexible. |
| US-05 | **As an** integrator, **I want** admin CRUD for forms, fields, and submissions **so that** I can manage contact data without custom code. |
| US-06 | **As an** integrator, **I want** retention cleanup and pluggable notifications **so that** GDPR lifecycle and alerts work without symfony/mailer only. |

---

## Bundle functional scope

**Goal:** provide configurable multilingual contact forms with GDPR consent, customizable fields, optional client association, admin CRUD, and pluggable submission notifications.

**In scope (Packagist API)**

| Area | Responsibility |
| --- | --- |
| Entities / Doctrine | `ContactForm`, fields, translations, submissions, values; ORM mappings registered by the bundle |
| Public form | `/contact/{slug}` with CSRF, PRG redirect, flash success message |
| Admin CRUD | `/admin/contact-forms/**` for forms, fields, submissions (no bundled auth — host must secure) |
| GDPR | Consent checkbox, privacy policy URL, IP hash storage, per-form `retention_days`, cleanup command |
| Client linking | Optional `client_entity_class` + `SecurityClientResolver` when `symfony/security-bundle` is installed |
| Notifications | `ContactSubmissionNotifierInterface`, optional Mailer, `ContactSubmissionCreatedEvent` |
| Overrides | Twig templates and translation domain `NowoContactFormBundle` |

**Explicit non-goals**

- Bundled authentication/authorization for admin routes
- Built-in CAPTCHA, rate limiting, or honeypot (host application concern)
- Symfony Flex recipe (manual install documented in [`INSTALLATION.md`](INSTALLATION.md) until a recipe is published)
- Composite/multi-notifier wiring via DI tags (use custom `notifications.service` or event subscribers)

**Demo** (`demo/symfony8`) illustrates FrankenPHP integration, notifications, and seed data; it is **not** part of the Packagist package API.

---

## Validating the functional spec

- Run **`composer qa`** or **`make qa`**: PHP-CS-Fixer check + PHPUnit.
- Run **`make release-check`** before releases: composer sync, style, Rector dry-run, PHPStan, coverage, demo HTTP smoke.
- New or changed behavior should add or adjust **tests** under `tests/` rather than relying on prose alone.
- Target high line coverage for PHP (see [`README.md`](../README.md) § Tests and coverage).

---

## Requirement identifiers (`REQ-*`)

| ID | Where | What it marks |
| --- | --- | --- |
| `REQ-MAKE-001` | Root [`Makefile`](../Makefile) | Docker-driven development workflow |
| `REQ-MAKE-008` | Root [`Makefile`](../Makefile) | `update-deps` via shared `.scripts/` |
| `REQ-DEMO-005` | [`demo/symfony8/Makefile`](../demo/symfony8/Makefile) | Canonical `make up` with `Demo started at:` |
| `REQ-DEMO-007` | Demo Makefile + [`demo/Makefile`](../demo/Makefile) | `update-bundle` syncs mounted bundle before release verify |

When you change scripted behavior, update the existing `REQ-*` comment or introduce a new ID and reference it from the PR.

---

## Suggested workflow for contributors

1. **Clarify behavior** in an issue or draft PR (functional spec + any Makefile/demo impact).
2. **Implement** with tests and static analysis.
3. **Anchor scripts and demos** when dev UX changes (`REQ-*` comments).
4. **Ship docs** when behavior or configuration changes: [`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md), [`CHANGELOG.md`](CHANGELOG.md), [`UPGRADING.md`](UPGRADING.md) when consumers must change code or config.
5. **Keep Spec Kit artifacts in sync** when production code under `src/` changes:
   - Update [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) and [`code-inventory.md`](../specs/001-baseline/code-inventory.md).
   - Follow the maintainer checklist in [`SPEC-KIT.md`](SPEC-KIT.md).
   - For **new features**, use Cursor Agent skills (`/speckit-specify`, `/speckit-plan`, `/speckit-tasks`) as documented in SPEC-KIT.

---

## Relationship to Engram / external checklists

[`ENGRAM.md`](ENGRAM.md) covers Nowo-wide documentation checklist items. This document ties together **what the bundle does**, **how we verify it**, and **local `REQ-*` habits**. Engram is for org-level compliance; this file is for product + traceability.

---

## GitHub Spec Kit (summary)

This repository uses [GitHub Spec Kit](https://github.com/github/spec-kit) with **Cursor Agent** (`cursor-agent` integration).

| Artifact | Path |
| --- | --- |
| **Operator manual** (install, init, usage) | [`SPEC-KIT.md`](SPEC-KIT.md) |
| Baseline spec | [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) |
| Code inventory (100%) | [`specs/001-baseline/code-inventory.md`](../specs/001-baseline/code-inventory.md) |
| Constitution | [`.specify/memory/constitution.md`](../.specify/memory/constitution.md) |
| Cursor Agent skills | [`.cursor/skills/`](../.cursor/skills/) (`speckit-*`) |

**Quick start (maintainers):**

```bash
# Install Specify CLI (once per machine) — see SPEC-KIT.md
specify init --here --force --integration cursor-agent --script sh
specify integration list   # Cursor → installed (default)
```

In Cursor Agent, start a new feature with `/speckit-specify <description>`. For day-to-day tooling details, skills reference, folder layout, and troubleshooting, read **[`SPEC-KIT.md`](SPEC-KIT.md)**.

---

## See also

- [`SPEC-KIT.md`](SPEC-KIT.md) — GitHub Spec Kit manual (install, structure, usage)
- [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md)
- [`USAGE.md`](USAGE.md)
- [`CONFIGURATION.md`](CONFIGURATION.md)
- [`CONTRIBUTING.md`](CONTRIBUTING.md)
- [`RELEASE.md`](RELEASE.md)
