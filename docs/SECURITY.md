# Security — ContactFormBundle

This document describes the **attack surface**, **threats**, and **controls** for `nowo-tech/contact-form-bundle`. It is written in English per project standards.

## Table of contents

- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threats and mitigations](#threats-and-mitigations)
  - [Unprotected admin routes](#unprotected-admin-routes)
  - [Open redirect (admin locale switch)](#open-redirect-admin-locale-switch)
  - [Admin CSRF](#admin-csrf)
  - [Spam and abuse on public forms](#spam-and-abuse-on-public-forms)
  - [Personal data (PII) storage](#personal-data-pii-storage)
  - [IP address processing](#ip-address-processing)
  - [Cross-site request forgery (public forms)](#cross-site-request-forgery-public-forms)
  - [Cross-site scripting (Twig output)](#cross-site-scripting-twig-output)
  - [Field wizard state](#field-wizard-state)
  - [File uploads](#file-uploads)
  - [Notification content leakage](#notification-content-leakage)
  - [Dependency vulnerabilities](#dependency-vulnerabilities)
- [Content Security Policy (CSP)](#content-security-policy-csp)
- [Logging and secrets](#logging-and-secrets)
- [Cryptography](#cryptography)
- [Reporting](#reporting)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [AI security audit](#ai-security-audit)

## Scope

The bundle provides:

- **Public contact forms** at `/{_locale}/contact/{slug}` (GET / POST, form CSRF per the host
  `framework.form.csrf_protection`; the legacy `GET /contact/{slug}` redirects there).
- **Admin UI** under `web_ui.path_prefix` (default `/admin/contact-forms`): contact forms, fields (multi-step wizard
  over the Symfony FormFlow, state in the session), submissions, deletes behind the UiKit confirm dialog (POST +
  CSRF), and the interface-language switch `GET {prefix}/locale/{locale}`.
- **Persistence** of submission field values, locale, optional client linkage, anonymized IP hash, and GDPR consent
  timestamp.
- **Optional notifications** via `ContactSubmissionNotifierInterface`, Symfony Mailer, or `ContactSubmissionCreatedEvent`.
- **Retention cleanup** via `nowo:contact-form:cleanup-submissions`.

The bundle ships a **default admin access checker** (`security.access_roles`, default `ROLE_ADMIN`) enforced on every
admin controller. The host adds its own `access_control` line for the admin prefix (the Flex post-install message shows
it; the recipe no longer ships a second `access_control` file, which Symfony refuses next to the host one).

## Attack surface

| Input | Source | Notes |
|-------|--------|-------|
| Dynamic field values | Public form POST | Stored as text; may contain PII. Length-capped (`public_field_max_length`: text / email / URL 255, textarea 10 000, phone 32). |
| GDPR consent checkbox | Public form | Required when `requireConsent` is enabled on the form. |
| Client IP | HTTP request | Stored as SHA-256 hash with configurable salt (not raw IP); rate-limit key. |
| Authenticated user / client | Optional `SecurityClientResolver` | Links submission to host client entity when configured. |
| Admin CRUD payloads | Admin forms | Form definitions (name / slug / privacy URL / retention / notification email validated), fields (name pattern, reserved names), translations, consent label HTML (sanitized). |
| `redirect` query / `Referer` | Admin locale switch | Only same-origin paths inside the admin prefix are followed. |
| Field wizard session | Admin session | Serialized `ContactFormField` per form / field key (`nowo_contact_form.field_flow.*`); applied to the managed entity on finish. |
| Uploaded files | Public form `file` fields | `File` constraint (default max `5M`, document/image MIME allow-list; SVG/HTML excluded) before the host `file_upload.service`. |
| Notification recipients | Form `notification_email` or config | Validated e-mail address. |

## Threats and mitigations

### Unprotected admin routes

- **Risk**: Anyone can create, edit, or delete forms and view submissions if admin URLs are public.
- **Mitigation**: `ContactFormAdminAccessSubscriber` guards every request whose controller is one of the admin
  controllers (since 1.6 by controller class, so a host route `name_prefix`, fragments and sub-requests are covered),
  using `ContactFormAccessCheckerInterface` (`security.access_roles`). An empty role list denies everyone, and the
  container refuses to compile with an empty list and the default checker (configuration mistake). Add a host
  `access_control` line for the admin prefix as defence in depth. `web_ui.enabled: false` turns the admin routes into
  404s. `security.allow_unauthenticated` (dev / demo only) is refused in the `prod` environment and in any non-debug
  kernel whose environment does not start with `test`.

### Open redirect (admin locale switch)

- **Risk**: `{prefix}/locale/{locale}?redirect=…` redirected to any value starting with `/` but not `//`, so
  `/%5Cevil.example`, `/%09/evil.example` or `%5C%5Cevil.example` reached another host (browsers normalise `\` and drop
  tabs) — CWE-601, 1.0.0 to 1.5.1.
- **Mitigation (1.6.0)**: `Security\SafeRelativePath` — a single leading `/`, no backslash, whitespace or control
  character anywhere, and the path must stay inside `web_ui.path_prefix`; the same rule applies to the same-host
  `Referer` fallback and to relative links in consent labels; `.` / `..` segments (also `%2e`-encoded) are refused. The switch stays a `GET` (it only stores the interface
  language in the session and never leaves the admin).

### Admin CSRF

- **Mitigation**: admin forms use the Symfony form CSRF extension; deletes post a CSRF-only form checked with
  `isCsrfTokenValid()` (fails closed). Since 1.6 the container refuses to compile when the admin UI is enabled without
  `framework.csrf_protection` / `framework.form.csrf_protection` (`symfony/security-csrf` is a dependency).

### Spam and abuse on public forms

- **Risk**: Automated submissions, flooding, or storage exhaustion.
- **Mitigation**: Per-IP and per-form rate limiting (`public_submission_rate_limit`, HTTP 429) counts **every** POST
  (valid or not, since 1.6). The default counter lives in `cache.app` and is not atomic under concurrency: set
  `public_submission_rate_limit.limiter` to a `symfony/rate-limiter` factory (with a lock store) for strict counting.
  Values are length-capped. Add a honeypot or CAPTCHA (FormKit / host) for bots; disable unused forms
  (`enabled: false`).

### Personal data (PII) storage

- **Risk**: Submissions may contain names, emails, phone numbers, and message content subject to GDPR/privacy law.
- **Mitigation**: `retention_days` per form (0 = keep forever, 1 to 3650 days; negative values are rejected and ignored
  by the cleanup), schedule `nowo:contact-form:cleanup-submissions`. Restrict admin access.

### IP address processing

- **Risk**: Raw IPs are personal data in many jurisdictions.
- **Mitigation**: The bundle stores `ip_hash` (SHA-256 of IP + salt), not the raw address. Configure
  `ip_anonymization_salt` (defaults to `%kernel.secret%`).

### Cross-site request forgery (public forms)

- **Mitigation**: Enable CSRF for Symfony forms in the host application (`framework.form.csrf_protection`). See
  [Symfony CSRF documentation](https://symfony.com/doc/current/security/csrf.html#csrf-protection-in-forms).

### Cross-site scripting (Twig output)

- **Risk**: User-submitted values rendered in admin templates; admin-entered copy rendered on the public page.
- **Mitigation**: Twig auto-escapes by default. Do not mark submission values as `|raw` in overrides.
- **Consent labels** (rendered with `label_html`) go through an allow-list sanitizer: unknown elements unwrapped,
  attributes allow-listed, links limited to `http(s):`, `mailto:` and safe relative paths; comments, processing
  instructions and CDATA are dropped (1.6).
- **Privacy policy URL**: `http(s)` only (validated in the admin since 1.6); a legacy `javascript:` / `data:` value is
  not rendered as a link.

### Field wizard state

- The wizard keeps the field being edited in the session (FormFlow `SessionDataStorage`) between steps and applies it
  to the managed entity on finish; the hidden definition values are re-validated (`ContactFieldType::tryFrom`, name
  pattern `^[a-z][a-z0-9_]*$`, reserved `gdpr_consent` / `_token` — checked on the definition step and again on the
  hidden name of every later step), so a tampered step is a validation error.

### File uploads

- File fields are refused in the wizard unless `file_upload.service` is configured (the null handler throws, so every submission with a file would fail).
- Your handler must never build paths from `UploadedFile::getClientOriginalName()`; generate the name
  (`guessExtension()`) and store outside the public web root.

### Notification content leakage

- **Risk**: Email or webhook notifications include full field values.
- **Mitigation**: Use custom notifiers with redaction; restrict mail transport and webhook endpoints.

### Dependency vulnerabilities

- **Mitigation**: Run `composer audit` before releases; keep Symfony and Doctrine updated.

## Content Security Policy (CSP)

The bundle templates contain no inline `<script>` / `<style>` and no inline event handlers (`onclick`, `onsubmit`, …)
or `confirm()`: pages render on the UiKit page contract, whose scripts are external files. If you add inline blocks in
overrides, give them `nonce="…"` from the request attribute **`csp_nonce`**.
`tests/Unit/Templates/InlineBlocksDeclareNonceTest.php` and the page contract test enforce this.

## Logging and secrets

Do not log full submission bodies, raw IP addresses, or mail credentials. Keep `APP_SECRET` and `ip_anonymization_salt`
out of version control.

## Cryptography

IP anonymization uses SHA-256 with a server-side salt. No other custom cryptography is implemented in the bundle.

## Reporting

See the repository [`.github/SECURITY.md`](../.github/SECURITY.md) for coordinated disclosure contacts.

## Release security checklist (12.4.1)

Before each tagged release, maintainers confirm (tick in the release PR or tag notes):

| Item | Confirm |
|------|--------|
| `docs/SECURITY.md` and `.github/SECURITY.md` reviewed | ☐ |
| `.env` / secrets not committed (`.gitignore` baseline) | ☐ |
| No secrets in recipes or sample configs | ☐ |
| Admin routes documented as host responsibility | ☐ |
| `composer audit` clean or exceptions documented | ☐ |
| No sensitive submission data in logs | ☐ |
| Retention command documented for production cron | ☐ |
| Rate limiting / spam mitigation considered for public endpoints | ☐ |
| REQ-SEC-004 AI audit Pass (good) recorded (2026-10-27, Appendix AP) | ☑ |

## AI security audit

| Field | Value |
| --- | --- |
| Grade | **Pass (good)** |
| Risk | **Low** |
| Date | 2026-10-27 (re-audit of master c4f1bf0: Pass (conditional) / Medium → fixes PR #48 verified, released in 1.6.0) |
| Method | Static review + PHP 8.5 / libxml 2.13 PoCs: WHATWG redirect resolution, HTML5 re-parse of sanitized labels, `Url` constraint |
| Fixed in 1.6.0 | Open redirect CWE-601 in the admin locale switch (1.0.0–1.5.1) via `SafeRelativePath` + admin prefix (also dot segments); `http(s)`-only privacy URL; controller-class admin guard; `retentionDays` 0–3650 (0 = keep forever); value length caps + every POST rate-limited; sanitizer drops comments / PIs / CDATA; field names validated on every wizard step; upload service required for file fields; CSRF / empty roles / `allow_unauthenticated` outside dev compile errors; recipe `access_control` → post-install note |
| Residual | Host `access_control` + a dedicated admin role; `trusted_proxies` (the rate limit keys on the client IP); honeypot / CAPTCHA for bots; upload handlers must generate file names |

Recorded in `BUNDLES_SECURITY_ANALYSIS.md` (Appendix AP).
