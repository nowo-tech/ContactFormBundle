# Architecture

How the bundle is put together for hosts: the ports you call, the SPI you implement, the events you receive, the
persistence model, and how a standard Symfony application and a DDD / hexagonal one wire them (REQ-ARCH-001).
Configuration keys are in [CONFIGURATION.md](CONFIGURATION.md); examples in [USAGE.md](USAGE.md).

## Table of contents

- [Bounded context](#bounded-context)
- [Ports hosts call](#ports-hosts-call)
- [SPI hosts implement](#spi-hosts-implement)
- [Events](#events)
- [Persistence](#persistence)
- [Standard Symfony and DDD hosts](#standard-symfony-and-ddd-hosts)
  - [Standard Symfony host](#standard-symfony-host)
  - [DDD / hexagonal host](#ddd--hexagonal-host)
- [REQ-ARCH-001 status](#req-arch-001-status)
- [Deferred to 2.0](#deferred-to-20)

## Bounded context

The bundle owns six entities (`Entity\ContactForm`, `ContactFormTranslation`, `ContactFormField`,
`ContactFormFieldTranslation`, `ContactSubmission`, `ContactSubmissionValue`) and has no foreign key to host tables.
The client of a submission is referenced by id only: `client_id` (an integer column; only integer client ids in the
32-bit range are stored there, see UPGRADING "To 1.6.4") or `client_reference` (any id type, as a string, from a
`ClientReference` VO). No host entity extends, uses or implements anything of the bundle.

The entities ship with two equivalent mappings: attributes (the default) and XML
(`src/Resources/config/doctrine-xml/*.orm.xml`, since 1.9, `doctrine.mapping_type: xml`). Both describe the same
tables, columns, indexes, constraints and associations; `tests/Unit/Doctrine/XmlMappingParityTest.php` compares them
field by field and through a schema diff, with both naming strategies.

## Ports hosts call

Each port is an interface aliased in the container to the built-in service, so autowiring by interface works and you
may alias it to your own service or decorate it.

| Port | Built-in service | Notes |
|------|------------------|-------|
| `Service\ContactSubmissionRecorderInterface` (1.7) | `ContactSubmissionProcessor` | `record(formSlug, values, locale, ?ClientReference, ?consentGivenAt)`: plain values in, submission id out; no form, no request |
| `Service\SubmissionRetentionCleanerInterface` (1.8) | `SubmissionRetentionCleanupService` | retention cleanup for your own scheduler |

## SPI hosts implement

| SPI | Configuration | Without it |
|-----|---------------|------------|
| `Client\ClientReferenceResolverInterface` (1.7) | `client_reference_resolver` | `ObjectClientReferenceResolver`: the client object of `ClientResolverInterface` as a reference |
| `Notification\ContactSubmissionNotifierInterface` | alias the interface | `NullContactSubmissionNotifier`, or the mailer notifier when enabled |
| `Upload\ContactFormFileUploaderInterface` (1.8) | `file_upload.service` | file fields refuse uploads |
| `Security\ContactFormAccessCheckerInterface` | `security.access_checker` | role-based checker |
| `Service\ClientResolverInterface` | `client_entity_class` / `client_user_accessor` | security user (standard hosts; returns the client object) |
| `Service\ContactFormFileUploadHandlerInterface` | deprecated since 1.8 (takes `UploadedFile` and entities) | — |

All of them take scalars and bundle VOs (`ClientReference`, `ContactSubmissionNotification`, `UploadedContactFile`,
`ContactFormFileTarget`), except the deprecated upload handler. The form types (`DynamicContactFormBuilder`,
`Form\*`) and `Ui\ContactFormPageFactory` are UI services, not ports; templates are themable (REQ-TWIG-001).

## Events

| Event | When | Carries |
|-------|------|---------|
| `Event\ContactSubmissionCreatedEvent` | after the submission was flushed, before the notifier | ids and scalars (`getSubmissionId()`, `getFormSlug()`, `getLocale()`, `getClientReference()`, `getFieldValues()`, `getCreatedAt()`); `getSubmission()` (entity) for standard hosts |
| `Event\ContactSubmissionsDeletedEvent` (1.7) | after the admin delete and each flushed retention chunk | `getSubmissionIds()`, `getReason()` |

## Persistence

- Every read, write, flush and recovery reset of the bundle goes through `nowo_contact_form.entity_manager`: the
  manager named by `doctrine.entity_manager`, else the default entity manager.
- With `doctrine.entity_manager` set, the entities are mapped on that manager only (also with `auto_mapping` or a
  default metadata driver on the others), and the bundle never flushes, clears or resets your managers.
- Kept-alive workers (FrankenPHP, kernel reset off): the bundle clears its own manager at the request, message and
  command boundaries, never the host's default one; see [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md). The
  mapping type changes only which driver reads the metadata; it adds no runtime state.

## Standard Symfony and DDD hosts

### Standard Symfony host

Zero configuration: attribute mapping on the default entity manager, the security user as client, the admin UI and
the public form. Nothing changes in 1.9.

### DDD / hexagonal host

Opt-in, nothing of it is forced on standard hosts:

```yaml
doctrine:
    orm:
        default_entity_manager: default
        entity_managers:
            default: { connection: default, mappings: { App: { type: xml, dir: '%kernel.project_dir%/config/doctrine', prefix: 'App\Domain' } } }
            contact_form: { connection: contact_form }

nowo_contact_form:
    doctrine:
        entity_manager: contact_form   # 1.7: entities, flushes and resets on this manager only
        mapping_type: xml              # 1.9: the shipped XML mapping instead of the attributes
    client_reference_resolver: App\Infrastructure\ContactForm\ClientReferenceResolver
```

- `mapping_type: xml` registers `src/Resources/config/doctrine-xml` on `doctrine.entity_manager` (or the default
  manager). You may instead map that directory yourself (`type: xml`, `prefix: Nowo\ContactFormBundle\Entity`,
  `is_bundle: false`) on the manager you set in `doctrine.entity_manager`.
- Call `ContactSubmissionRecorderInterface::record()` from your handlers, listen to the id getters of the events, and
  implement the SPI above in your infrastructure layer.

`tests/Integration/EntityManager/DedicatedEntityManagerTest.php` boots both mappings (attributes and XML, default and
dedicated manager) and records through the port.

## REQ-ARCH-001 status

| Rule | Status | Where |
|------|--------|-------|
| 1. Interfaces + VOs | ✅ | ports and SPI above |
| 2. No forced base class / trait | ✅ | bundle-owned entities referenced by id; XML mapping since 1.9 |
| 3. No framework types in ports, events, VOs | ✅ since 1.8 | deprecated upload handler kept until 2.0 |
| 4. Events carry ids | ✅ since 1.7 | entity getter kept for standard hosts |
| 5. SPI documented | ✅ | this page |
| 6. Never flush the host EM | ⚠️ default until 2.0 | without `doctrine.entity_manager` the bundle's manager is the host default one |
| 7. Events after commit | ✅ | dispatched after the bundle's flush (see USAGE, LI-CF-L1, for a host transaction) |
| 8. Config through provider ports | ✅ | config tree and the SPI above; no InstanceSettings |

## Deferred to 2.0

Tracked in [UPGRADING.md](UPGRADING.md#to-190):

- **Rule 6 default.** Without `doctrine.entity_manager`, `record()`, the public form, the admin and the retention
  cleanup flush the default entity manager, which also commits the host's pending changes. A dedicated manager by
  default would move the tables to another manager (and connection) for every existing host, so 1.x keeps the default
  and offers `doctrine.entity_manager` (1.7). A "no flush" mode is not offered: submission ids are database
  identities, known only after the bundle's flush.
- **Removal of the deprecated APIs.** `ContactFormFileUploadHandlerInterface` (and its service alias).
