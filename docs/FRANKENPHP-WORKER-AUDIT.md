# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/contact-form-bundle` (`symfony-bundle`) |
| Audited revision | `v1.0.22` (post–worker remediation) |
| Audit date | 2026-09-24 |
| Method | Manual review of every file under `src/` (services, controllers, subscribers, Twig extension, form types, repositories, command, DI extension, compiler passes, `Resources/config`) |
| **Verdict** | ✅ **Viable under scenario B** — bundle services are stateless; a closed EntityManager is reset on bundle routes; public submissions are detached after processing; public reads refresh forms/fields/translations; repositories always resolve the current manager after `resetManager()` |
| Remediation (2026-09-23 / 2026-09-24) | W-01 resolved (`ContactFormEntityManagerResolver`, `ContactFormEntityManagerSubscriber`, `ContactSubmissionProcessor`, `HINT_REFRESH` on public reads, `WorkerSafeServiceEntityRepository`); W-02 accepted. Regression tests: `ContactFormWorkerModeTest` (consecutive requests with `services_resetter` skipped) plus unit tests for resolver, subscriber, processor, and worker-safe repositories |

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests, so every shared service, static property and PHP global survives from one request to the next. Two scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` (or implementing `ResetInterface`) are reset between requests.
- **B — no reset at all:** nothing is reset; any per-request state kept in a service leaks into the next request.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | All bundle services are `readonly` or have no properties; config comes from container parameters |
| Static properties / `static` locals | ✅ | Only pure static helpers (`ContactPhoneValue`, `ContactFormFieldPhoneOptions::fromField()`, enum `values()`); no static properties |
| `ResetInterface` / `kernel.reset` coverage | ✅ N/A | No bundle service needs a reset |
| Request / user / locale captured in services | ✅ | `Request`, token and locale are read per call (`SecurityClientResolver::resolve()`, controllers) |
| Superglobals, `$_ENV`, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None used. `LocaleSwitcher::setLocale()` (which calls `\Locale::setDefault()`) is used, but Symfony restores it on every request (see Info notes) |
| Doctrine / EntityManager | ✅ | Closed manager reset on bundle routes and after a failed submission flush; submissions detached; public reads use `HINT_REFRESH`; repositories re-resolve the manager after reset (W-01). Clearing unrelated application entities between requests stays the application's responsibility |
| Output, headers, `exit`, shutdown functions | ✅ | None; responses and flashes go through Symfony |
| Resources (files, sockets, cURL) held open | ✅ | None opened by the bundle; file uploads are delegated to a host service |
| Memory growth across requests | ✅ | Public submissions are detached; forms/fields stay managed but are bounded by the number of rows and refreshed on read (W-01) |
| Blocking I/O and timeouts | ⚠️ Low (accepted) | Notification e-mail is sent synchronously through Symfony Mailer unless routed to Messenger (W-02) |
| Third-party static state | ✅ | `libxml_use_internal_errors()` is restored after use; FormKit trait state is scoped with `try/finally` |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker.neon` included in `phpstan.neon.dist` |

## Services reviewed

| Service | Shared | Mutable state | Scenario A | Scenario B |
|---------|--------|---------------|------------|------------|
| `Service\ContactSubmissionProcessor` | yes | none (`final readonly`); persists + flushes via the manager from `ContactFormEntityManagerResolver`, detaches the submission | ✅ | ✅ |
| `Service\ContactFormEntityManagerResolver` (new) | yes | none (`final readonly`, `ManagerRegistry` only) | ✅ | ✅ |
| `EventSubscriber\ContactFormEntityManagerSubscriber` (new; `kernel.request` 31 main request only, `kernel.exception` 0; bundle routes only) | yes | none | ✅ | ✅ |
| `Service\DynamicContactFormBuilder` | yes | none (`final readonly`) | ✅ | ✅ |
| `Service\ContactFormSubmissionRateLimiter` | yes | none (`final readonly`); state lives in `cache.app` keyed by form slug + client IP | ✅ | ✅ |
| `Service\SecurityClientResolver` | yes | none; reads `TokenStorage` inside `resolve()` | ✅ | ✅ |
| `Service\SubmissionRetentionCleanupService` | yes | none; flushes via EM (used by the CLI command only) | ✅ | ✅ (CLI) |
| `Service\ContactFormRichTextSanitizer` | yes | none; new `DOMDocument` per call, libxml error mode restored | ✅ | ✅ |
| `Service\IpAnonymizer`, `ClientLabelResolver`, `ContactPhonePrefixResolver`, `ContactPhoneInputOptionsResolver`, `ContactPhoneInputAvailability`, `ContactFormSubmissionValueNormalizer`, `ContactFormFieldSelectOptionsSynchronizer`, `NullContactFormFileUploadHandler` | yes | none (immutable config only) | ✅ | ✅ |
| `Notification\MailerContactSubmissionNotifier` / `NullContactSubmissionNotifier` | yes | none | ✅ | ✅ (W-02 is blocking I/O only) |
| `Security\ConfigurableContactFormAccessChecker` / `AllowAllContactFormAccessChecker` | yes | none; `isGranted()` evaluated per call | ✅ | ✅ |
| `EventSubscriber\ContactFormAdminAccessSubscriber` | yes | none | ✅ | ✅ |
| `EventSubscriber\ContactFormAdminLocaleSubscriber` | yes | none; calls `LocaleSwitcher::setLocale()` | ✅ | ✅ (see Info) |
| `Twig\ContactFormAdminTwigExtension` | yes | none; globals are constant strings from config | ✅ | ✅ |
| `DependencyInjection\TablePrefixListener` (Doctrine `loadClassMetadata`) | yes | none (`readonly` prefix) | ✅ | ✅ |
| 5 controllers (`ContactFormAdminController`, `ContactFormFieldAdminController`, `ContactFormPublicController`, `ContactFormPublicLegacyController`, `ContactSubmissionAdminController`) | yes (public) | none; only `readonly` injected services | ✅ | ✅ (closed EM reset by `ContactFormEntityManagerSubscriber`) |
| 6 repositories (`WorkerSafeServiceEntityRepository`) | yes | none added by the bundle; always use `ManagerRegistry::getManagerForClass()` (not the ORM 3 proxy cache); public reads (`findOneEnabledBySlug()`, `findByFormOrdered()`) use `HINT_REFRESH` with fetch-joined translations | ✅ | ✅ |
| 11 form types | yes | stateless, except FormKit `FormOptionsTrait` fields (bound builder restored in `finally`, profile name memoized from a class attribute) | ✅ | ✅ |
| `Command\CleanupExpiredSubmissionsCommand` | CLI only | none | N/A | N/A |

Entities, `ContactSubmissionNotification`, `ContactSubmissionCreatedEvent` and `ContactFormFieldPhoneOptions` are created per call and never stored in a service property.

## Findings

### W-01 — Doctrine EntityManager state is only cleaned by DoctrineBundle's reset (Medium)

- **Where:** `src/Service/ContactSubmissionProcessor.php:82-83` (`persist()` + `flush()` on every public submission), `src/Service/SubmissionRetentionCleanupService.php:57-61`, and the admin controllers (`src/Controller/ContactFormAdminController.php:115-116,134-137`, `src/Controller/ContactFormFieldAdminController.php:121-122,159-165`, `src/Controller/ContactSubmissionAdminController.php:84-85`).
- **Worker impact:** the bundle holds no entity in its own services, so under **A** DoctrineBundle's `doctrine` registry reset clears the identity map and replaces a closed EntityManager. Under **B**:
  - if a `flush()` throws (for example a DB error during a submission or an admin save), the EntityManager is closed and every later request served by that worker fails with "EntityManager is closed";
  - the identity map is never cleared, so every `ContactSubmission` (with its values, which are personal data) and every loaded `ContactForm` / `ContactFormField` stays in memory; memory grows with each submission and forms/fields/translations edited by another worker are served stale from the identity map (the SQL filter on `enabled` still applies, labels and field options do not refresh).
- **Recommendation:** keep `services_resetter` enabled (scenario A). If the app must run under B, clear the EntityManager at the end of each request (`kernel.terminate` listener calling `ManagerRegistry::resetManager()` when closed, `clear()` otherwise) and set a `max_requests` limit on the worker.
- **Status:** Resolved —
  - closed manager: new `src/Service/ContactFormEntityManagerResolver.php` resets the manager mapping `ContactForm` when it is closed; new `src/EventSubscriber/ContactFormEntityManagerSubscriber.php` calls it on `kernel.request` (main request, bundle routes `nowo_contact_form_*`, priority 31 right after the router) and on `kernel.exception` for bundle routes, which covers every admin controller flush; `ContactSubmissionProcessor` also resets it after a failed flush before rethrowing (works outside bundle routes);
  - identity map growth: `ContactSubmissionProcessor` detaches the submission and its values in a `finally` block after the event and notifier;
  - stale reads: `ContactFormRepository::findOneEnabledBySlug()` and `ContactFormFieldRepository::findByFormOrdered()` fetch-join translations and use `Query::HINT_REFRESH`, so the public page and the submission processor read current titles, labels and options.
  - stale repository manager after reset: all bundle repositories extend `WorkerSafeServiceEntityRepository`, which builds a fresh `EntityRepository` from the current registry manager on every call so ORM 3's `ServiceEntityRepositoryProxy` cache cannot keep a closed EntityManager;
  - Residual (accepted): the bundle never calls `clear()` on the application's manager. Admin pages keep the forms/fields they load managed (bounded by table size); under scenario B clearing application entities between requests remains the application's responsibility. The retention cleanup runs from the CLI.

### W-02 — Submission e-mail is sent synchronously (Low)

- **Where:** `src/Notification/MailerContactSubmissionNotifier.php:55` (`$this->mailer->send($email)`), called from `src/Service/ContactSubmissionProcessor.php:87-89` inside the public request.
- **Worker impact:** with a synchronous transport, a slow or unreachable SMTP server blocks the worker thread for the transport timeout (Symfony Mailer uses `default_socket_timeout`, 60 s by default). No state leaks, but a limited worker pool can be exhausted. An exception from the mailer also turns a successful (already flushed) submission into an error response.
- **Recommendation:** route `Symfony\Component\Mailer\Messenger\SendEmailMessage` to an async Messenger transport, or set an explicit timeout on the mailer DSN. Cap waiting requests with FrankenPHP `max_wait_time`.
- **Status:** Accepted — blocking I/O, not a state leak; the timeout belongs to the mailer DSN and async delivery to Messenger routing, both application configuration. The submission is still detached when the notifier throws.

No other findings.

Info notes:

- `src/EventSubscriber/ContactFormAdminLocaleSubscriber.php:63` and `src/Controller/ContactFormAdminController.php:77` call `LocaleSwitcher::setLocale()`, which changes process-wide state (`\Locale::setDefault()`, translator locale, router `_locale`). This does not leak: `translation.locale_switcher` is tagged `kernel.locale_aware`, so Symfony's `LocaleAwareListener` sets it back to the request locale on every `kernel.request` and to the default locale on `kernel.finish_request`, independently of `services_resetter`. It also has its own `kernel.reset` tag.
- `src/Service/ContactFormRichTextSanitizer.php:44-52` saves and restores `libxml_use_internal_errors()`, so libxml global error mode is not changed for later requests.
- The rate limiter stores counters in `cache.app`, not in the service. If `cache.app` is an in-memory adapter (`cache.adapter.array`), counters would be per worker thread and would grow in memory; use a shared adapter (filesystem, Redis) in production.
- `src/Entity/ContactSubmission.php:55` sets `createdAt` with `new DateTimeImmutable()` in the entity constructor. This runs per entity, not per service, so it is correct in worker mode (hygiene only: it bypasses the injected clock).
- The demo (`demo/symfony8/docker/frankenphp/Caddyfile`) runs FrankenPHP with a `worker` block.

## Usage recommendations in worker mode

- Scenario B is supported by the bundle since the 2026-09-23 remediation (W-01). Keeping `services_resetter` enabled is still recommended because the application's own Doctrine state is framework-owned.
- Send notification e-mails through an async Messenger transport (W-02).
- Services the host plugs in (`file_upload.service`, `notifications.service`, `security.access_checker`, custom `ClientResolverInterface`) run inside the worker: keep them stateless or implement `ResetInterface`. Do not cache the current user, request or uploaded file in their properties.
- Do not extend the controllers or `DynamicContactFormBuilder` with properties that store the current form, locale or client.
- Use a shared cache pool for `cache.app` so the public submission rate limit works across worker threads.
- If memory grows in production, set `max_requests` on the FrankenPHP worker (or `FRANKENPHP_LOOP_MAX` with the Symfony runtime).

## Re-audit triggers

Re-run this audit when a change adds: properties to any service, controller or subscriber; a cache of forms/fields in PHP memory; a Doctrine listener that buffers entities; direct use of `$_SERVER` / `$_ENV` / `\Locale::setDefault()` outside `LocaleSwitcher`; or a new notifier/HTTP integration.
