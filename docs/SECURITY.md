# Security — ContactFormBundle

This document describes the **attack surface**, **threats**, and **controls** for `nowo-tech/contact-form-bundle`. It is written in English per project standards.

## Scope

The bundle provides:

- **Public contact forms** at `/contact/{slug}` (POST with CSRF protection).
- **Admin CRUD** at `/admin/contact-forms/**` for form definitions, fields, and submissions.
- **Persistence** of submission field values, locale, optional client linkage, anonymized IP hash, and GDPR consent timestamp.
- **Optional notifications** via `ContactSubmissionNotifierInterface`, Symfony Mailer, or `ContactSubmissionCreatedEvent`.
- **Retention cleanup** via `nowo:contact-form:cleanup-submissions`.

The bundle ships a **default admin access checker** (`security.access_roles`, default `ROLE_ADMIN`) enforced on admin routes. The host application must still protect the admin path with a firewall / `access_control`. Set `security.allow_unauthenticated: true` only in demos.

## Attack surface

| Input | Source | Notes |
|-------|--------|-------|
| Dynamic field values | Public form POST | Stored as text; may contain PII (email, phone, free text). |
| GDPR consent checkbox | Public form | Required when `requireConsent` is enabled on the form. |
| Client IP | HTTP request | Stored as SHA-256 hash with configurable salt (not raw IP). |
| Authenticated user / client | Optional `SecurityClientResolver` | Links submission to host client entity when configured. |
| Admin CRUD payloads | Admin forms | Create/update form definitions, fields, translations. |
| Notification recipients | Form `notification_email` or config | Used by mailer/custom notifiers. |

## Threats and mitigations

### Unprotected admin routes

- **Risk**: Anyone can create, edit, or delete forms and view submissions if admin URLs are public.
- **Mitigation**: Bundle-level `ContactFormAccessCheckerInterface` (default roles from `security.access_roles`) plus host `access_control` on `admin_route_prefix` (default `/admin/contact-forms/**`). Keep `security.allow_unauthenticated: false` in production. See [`CONFIGURATION.md`](CONFIGURATION.md) and [`USAGE.md`](USAGE.md).

### Spam and abuse on public forms

- **Risk**: Automated submissions, flooding, or storage exhaustion.
- **Mitigation**: When CSRF is enabled in the host application (`framework.form.csrf_protection`), public forms are protected. Add rate limiting (`public_submission_rate_limit` in bundle config), honeypot, or CAPTCHA for abuse. Disable unused forms (`enabled: false`).

### Personal data (PII) storage

- **Risk**: Submissions may contain names, emails, phone numbers, and message content subject to GDPR/privacy law.
- **Mitigation**: Configure `retention_days` per form; schedule `nowo:contact-form:cleanup-submissions`. Document lawful basis and privacy policy URL (`privacyPolicyUrl` on forms). Restrict admin access.

### IP address processing

- **Risk**: Raw IPs are personal data in many jurisdictions.
- **Mitigation**: The bundle stores `ip_hash` (SHA-256 of IP + salt), not the raw address. Configure `ip_anonymization_salt` (defaults to `%kernel.secret%`).

### Cross-site request forgery (public forms)

- **Mitigation**: Enable CSRF for Symfony forms in the host application (`framework.form.csrf_protection` when using FrameworkBundle). See [Symfony CSRF documentation](https://symfony.com/doc/current/security/csrf.html#csrf-protection-in-forms).

### Cross-site scripting (Twig output)

- **Risk**: User-submitted values rendered in admin templates.
- **Mitigation**: Twig auto-escapes by default. Do not mark submission values as `|raw` in overrides unless sanitized.

### Notification content leakage

- **Risk**: Email or webhook notifications include full field values.
- **Mitigation**: Use custom notifiers with redaction; restrict mail transport and webhook endpoints.

### Dependency vulnerabilities

- **Mitigation**: Run `composer audit` before releases; keep Symfony and Doctrine updated.

## Logging and secrets

Do not log full submission bodies, raw IP addresses, or mail credentials. Keep `APP_SECRET` and `ip_anonymization_salt` out of version control.

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
