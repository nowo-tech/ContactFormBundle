# Feature Specification: ContactFormBundle baseline (100% code coverage)

**Feature Branch**: `001-baseline`  
**Status**: Active  

**Package**: `nowo-tech/contact-form-bundle`  
**Configuration root**: `nowo_contact_form`  
**Code inventory**: [`code-inventory.md`](code-inventory.md)

---

## Summary

Multilingual **dynamic contact forms**: admin CRUD for forms/fields with translation tabs, public submission with rate limiting and GDPR IP anonymization, phone/rich-text/file field support, notifications, retention cleanup CLI.

---

## User Scenarios

### US-01 — Admin form builder (P1)

**Given** admin routes enabled, **When** integrator creates a form via wizard steps, **Then** `ContactFormFieldFlowType` persists `ContactForm` + `ContactFormField` with translations.

### US-02 — Public submission (P1)

**Given** a published form slug, **When** visitor submits, **Then** `ContactSubmissionProcessor` validates, rate-limits, normalizes values, fires `ContactSubmissionCreatedEvent`, and notifies via `ContactSubmissionNotifierInterface`.

### US-03 — Dynamic field rendering (P1)

**Given** stored field definitions, **When** public page loads, **Then** `DynamicContactFormBuilder` builds Symfony form with `ContactPhoneType`, select sync, and sanitizer for rich text.

### US-04 — Retention & cleanup (P2)

**Given** retention policy in config, **When** `nowo:contact-form:cleanup-expired-submissions` runs, **Then** `SubmissionRetentionCleanupService` deletes expired submissions.

### US-05 — Multi-client routing (P2)

**Given** multi-tenant setup, **When** request arrives, **Then** `SecurityClientResolver` / `ClientLabelResolver` resolve client context for labels and access.

---

## Requirements

### Bundle & config

- **FR-BUNDLE-001**: `NowoContactFormBundle` alias `nowo_contact_form`.
- **FR-CFG-001**: `Configuration` — admin, public routes, retention, rate limit, notifications, phone widgets.
- **FR-CFG-002**: `NowoContactFormExtension`, `TwigPathsPass`.

### Entities & repositories

- **FR-ORM-001**: Forms, fields, translations, submissions, submission values.
- **FR-ORM-002**: Six repositories with scoped queries.

### Enums & events

- **FR-ENUM-001**: `ContactFieldType`, `ContactPhoneWidget`.
- **FR-EVT-001**: `ContactSubmissionCreatedEvent`, `ContactFormAdminLocaleSubscriber`.

### Controllers

- **FR-CTRL-001**: Admin CRUD for forms, fields, submissions; public + legacy public controllers.

### Forms

- **FR-FORM-001**: Form/field types, wizard steps, translation types, `ContactPhoneType`.
- **FR-FORM-002**: `ContactFormFieldWizardStepTrait` shared step logic.

### Services

- **FR-SVC-001**: Submission processor, dynamic builder, value normalizer, rate limiter, sanitizer.
- **FR-SVC-002**: Client resolver interface + security implementation, label resolver.
- **FR-SVC-003**: Phone prefix/options resolvers, availability, `ContactPhoneValue`.
- **FR-SVC-004**: Select options synchronizer, file upload handler interface + null impl.
- **FR-SVC-005**: `IpAnonymizer`, `SubmissionRetentionCleanupService`.

### Notifications

- **FR-NOT-001**: Notifier interface, mailer + null implementations, notification DTO.

### Phone

- **FR-PHONE-001**: `ContactFormFieldPhoneOptions` value object.

### CLI

- **FR-CLI-001**: `CleanupExpiredSubmissionsCommand`.

### Twig & views

- **FR-TWIG-001**: `ContactFormAdminTwigExtension`.
- **FR-TWIG-002**: Admin and public Twig templates.
- **FR-I18N-001**: Seven locale YAML files.

### DI

- **FR-DI-001**: `services.yaml`, routes, default package YAML.

---

## Success Criteria

- **SC-001**: **82/82** files mapped.
- **SC-002**: Config matches `docs/CONFIGURATION.md`.
- **SC-003**: QA/CI green.

---

## Explicit non-goals

- Built-in CAPTCHA (app extension).
- Demo trees as stable API.

---

## Validation

`composer qa`, PHPUnit, PHPStan, inventory audit.
