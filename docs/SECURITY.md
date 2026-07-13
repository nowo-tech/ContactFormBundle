# Security — ContactFormBundle

This document describes the **attack surface**, **threats**, and **controls** for `nowo-tech/contact-form-bundle`. It is written in English per project standards.

## Scope

The bundle provides:

- **Public contact forms** at `/contact/{slug}` (POST with CSRF protection).
- **Admin CRUD** at `/admin/contact-forms/**` for form definitions, fields, and submissions.
- **Persistence** of submission field values, locale, optional client linkage, anonymized IP hash, and GDPR consent timestamp.
- **Optional notifications** via `ContactSubmissionNotifierInterface`, Symfony Mailer, or `ContactSubmissionCreatedEvent`.
- **Retention cleanup** via `nowo:contact-form:cleanup-submissions`.

The bundle does **not** ship authentication or authorization for admin routes; the host application must protect them.

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
- **Mitigation**: Restrict `/admin/contact-forms/**` in the host firewall (roles, VPN, IP allowlist). See [`USAGE.md`](USAGE.md).

### Spam and abuse on public forms

- **Risk**: Automated submissions, flooding, or storage exhaustion.
- **Mitigation**: CSRF is enabled on public forms. Add rate limiting, honeypot, or CAPTCHA in the host application. Consider disabling forms (`enabled: false`) when not needed.

### Personal data (PII) storage

- **Risk**: Submissions may contain names, emails, phone numbers, and message content subject to GDPR/privacy law.
- **Mitigation**: Configure `retention_days` per form; schedule `nowo:contact-form:cleanup-submissions`. Document lawful basis and privacy policy URL (`privacyPolicyUrl` on forms). Restrict admin access.

### IP address processing

- **Risk**: Raw IPs are personal data in many jurisdictions.
- **Mitigation**: The bundle stores `ip_hash` (SHA-256 of IP + salt), not the raw address. Configure `ip_anonymization_salt` (defaults to `%kernel.secret%`).

### Cross-site request forgery (public forms)

- **Mitigation**: `DynamicContactFormBuilder` enables `csrf_protection` on public forms. Ensure CSRF is enabled in the host framework config.

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
