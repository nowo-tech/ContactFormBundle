# Concurrency

How the bundle stays correct when visitors, admins and the retention command write at the same time
(nowo-tech REQ-RUNTIME-003). This page lists the write paths, the conflict answers and codes, the host options and the
residual risks.

## Table of contents

- [Rules the bundle follows](#rules-the-bundle-follows)
- [Write paths](#write-paths)
- [Conflict answers and codes](#conflict-answers-and-codes)
- [What is not a conflict](#what-is-not-a-conflict)
- [Host options](#host-options)
- [Retries](#retries)
- [Tests and CI](#tests-and-ci)
- [Residual risks](#residual-risks)

## Rules the bundle follows

- **One transaction per write unit.** Every write of the bundle is a single ORM `flush()`, which Doctrine runs in one
  transaction: the public submission (one submission and its values), each admin create or delete, and each chunk of the
  retention purge. No path writes with several bare flushes. Since 1.12 the admin **edit** of a form and of a field runs
  in one transaction of transaction-doctrine's runner (`DoctrineTransactionRunner::forConnection()`): the row version
  check (below), then the flush.
- **Your entity manager is never cleared or reset while it is open.** The bundle writes on the manager of its entities
  (`doctrine.entity_manager`, else the manager that maps them). A manager closed by a failed flush is replaced
  (`ContactFormEntityManagerResolver::resetClosed()`); an open one is never touched. Only a manager the bundle owns
  (`doctrine.entity_manager` that is not your default one) is cleared, at the request / message / command boundaries
  (`ContactFormEntityManagerClearSubscriber`).
- **Side effects after the commit.** `ContactSubmissionCreatedEvent` and the notification (mail) run after the
  submission's flush has committed; `ContactSubmissionsDeletedEvent` after each purge chunk has committed. A failure
  there is not swallowed.
- **No I/O under a lock.** The bundle holds no lock of its own; a flush holds row locks only while it writes.
- **FrankenPHP worker mode with kernel reset off.** Every service is stateless per request; the commit ledger of the
  conflict answer is a monotonic counter of the process, read by difference only.

## Write paths

| Write path | Transaction | Control | Conflict answer |
| --- | --- | --- | --- |
| Public submission (`ContactFormPublicController::show` POST, `ContactSubmissionRecorderInterface::record()` → `ContactSubmissionProcessor`) | One flush: one `INSERT` of the submission and its values | Insert only, no read-modify-write; the form row is only read (foreign key) | 409 `CONFLICT_001` / `CONFLICT_002` on the bundle's route; the request-free port (`record()`) rethrows the storage error to its caller |
| Admin form create / delete (`ContactFormAdminController`) | One flush | Create: unique `slug`; unique `(form_id, locale)` translation per locale. Delete by id | Lock conflict: 409 `CONFLICT_001` / `CONFLICT_002`. Unique key: 409 `CONTACT_FORM_DUPLICATE_KEY` (domain) |
| Admin form edit (`ContactFormAdminController::edit`, `ContactFormRepository::saveEdit()`) | One runner transaction: version check, flush (the form and its translations) | Optimistic `row_version` (compare-and-swap, below); unique `slug`; unique `(form_id, locale)` translation | Lock conflict: 409 `CONFLICT_001` / `CONFLICT_002`. Stale page: 409 `CONTACT_FORM_STALE_VERSION` (domain). Unique key: 409 `CONTACT_FORM_DUPLICATE_KEY` (domain) |
| Admin field wizard create / delete (`ContactFormFieldAdminController`) | One flush | Create: unique `(form_id, name)`; unique `(field_id, locale)` translation. Delete by id | Same as above |
| Admin field wizard edit (`ContactFormFieldAdminController::edit`, `ContactFormFieldRepository::saveEdit()`) | One runner transaction: version check, flush (the field and its translations) | Optimistic `row_version` (compare-and-swap, below), carried through the wizard steps; unique `(form_id, name)`; unique `(field_id, locale)` translation | Lock conflict: 409 `CONFLICT_001` / `CONFLICT_002`. Stale wizard: 409 `CONTACT_FORM_STALE_VERSION` (domain). Unique key: 409 `CONTACT_FORM_DUPLICATE_KEY` (domain) |
| Admin submission delete (`ContactSubmissionAdminController`) | One flush (values deleted by cascade) | Delete by id | Lock conflict: 409 `CONFLICT_001` / `CONFLICT_002` |
| Retention purge (`nowo:contact-form:cleanup-submissions`, `SubmissionRetentionCleanupService::cleanup()`) | One flush **per chunk** of 500 submissions | Delete by id; only rows past their retention; never the form | Console: exit code 1 and "A lock conflict … Run the command again"; the failed chunk is unchanged, the chunks before it stay deleted |

`flush()` in `src/`: the submission's single flush (`ContactSubmissionProcessor::store()`), the purge's flush per chunk,
the admin controllers' single flush for a create or a delete, and the `saveEdit()` flush of the two repositories (inside
their runner).

### Lost updates: the row version (1.12)

`ContactForm` and `ContactFormField` have an integer column `row_version` (default 1). The edit pages carry the version
of the page they show:

- the **form edit** has a hidden field `rowVersion` (unmapped);
- the **field wizard** has a hidden field `flowBaseVersion` on its first step, kept in the wizard's session copy of the
  field up to the last step (a property that is not persisted), so the check covers the whole wizard, from the page the
  admin first saw.

On save, inside the save's transaction and before the flush, `RowVersionGuard::advance()` runs one atomic
`UPDATE … SET row_version = row_version + 1 WHERE id = ? AND row_version = ?`:

- one row matched: the page was current, the row now holds the next version (and the row lock until the commit), and the
  flush writes the change;
- no row matched: another admin saved the row after the page was opened (or deleted it). `StaleVersionException` is
  thrown before anything is flushed; the controller drops the form's changes from the managed entities (`refresh()` of
  the row and of its managed translations: the manager is never cleared or reset, and a later flush of it writes nothing
  of the refused page) and re-renders the page with **409**, `X-Error-Code: CONTACT_FORM_STALE_VERSION`, the text the
  admin typed (form edit) and a "changed by someone else, reload" message. A stale wizard starts again on the stored field
  (its draft is discarded: the session copy would otherwise stay outdated). No `X-Conflict-Code` and no `Retry-After`: it
  is not a lock conflict, and repeating the same page can never succeed.

Two admins saving at the same moment: the second `UPDATE` waits for the first one's commit (a lock wait, bounded by the
database timeout like any other), then matches no row. Only a wait past the timeout is `CONFLICT_001`.

The check reads the database, never the identity map, so a manager that kept a loaded row across requests (worker mode
with the kernel reset off) cannot make a stale page look current. A missing or non-numeric token is stale (a custom
template that lists the fields one by one must render `form.rowVersion`; `form_rest()` does).

The version advances on every admin save, also when only a translation changed (the translation rows belong to the
save of their form or field). Adding or deleting a field does not advance the form's version: they are other rows.
Deleting a form or a field is not versioned (delete by id, idempotent).

## Conflict answers and codes

On the routes of the bundle (`ContactFormConflictListener`, controllers of the bundle only; your own routes keep your
error handling):

| Code | When | Status | `Retry-After` | Client should |
| --- | --- | --- | --- | --- |
| `CONFLICT_001` | Deadlock, lock wait timeout or serialization failure (MySQL 1213 / 1205, MariaDB 1020, PostgreSQL 40001 / 40P01 / 55P03, anywhere in the exception chain) and nothing committed since the request started | 409 (`conflict_response.status`) | Yes: `conflict_response.retry_after`, else 1 s | Retry |
| `CONFLICT_002` | The same after a commit earlier in the request (transaction-doctrine's request mark and ledger, `ConflictCode::forRequest()`, when its bundle is registered with `commit_detection`; otherwise the bundle's own ledger, fed by transaction-doctrine's commit-tracking middleware on the bundle's connection, see `conflict_response.commit_tracking`), a `ConcurrencyConflict` that committed, or a lost savepoint with no deadlock behind it (outcome unknown) | 409 (`conflict_response.status`) | No | Reload, do not repeat blindly |
| `CONTACT_FORM_STALE_VERSION` (`X-Error-Code`) | An admin save of a form or a field made on a page that another admin's save has outdated (1.12) | 409 (the page again with the message) | No | Reload the page and apply the change again |
| `CONTACT_FORM_DUPLICATE_KEY` (`X-Error-Code`) | An admin save refused by a unique key: two admins adding the same missing translation, or the same slug or field name, at once (or a slug / name that is already used) | 409 | No | Reload the page and apply the change again |

- The `CONFLICT_*` code is in the `X-Conflict-Code` header, which only ever holds `CONFLICT_001` or `CONFLICT_002`. The
  domain answer has no `X-Conflict-Code`.
- The pages are rendered by your error page: the answer is an `HttpException` with a fixed, client-safe message. The
  storage error stays the previous exception for your logs; no SQL, parameter or value reaches the client.
- The bundle has no JSON API; its answers are pages and redirects, so no JSON shape changed.
- A public submission that commits and then fails in a listener of `ContactSubmissionCreatedEvent` (your code) on a lock
  conflict answers `CONFLICT_002`: the submission is stored, so the visitor must not send it again.
- The codes are `Nowo\TransactionDoctrine\ConflictCode` values.

## What is not a conflict

- **Unique constraint violations** are not lock conflicts. On the admin routes they answer the domain 409 above. On the
  public form (the submission tables have no unique key) and in your code they stay a real error.
- **Lost connections** are never mapped: the outcome of the write is unknown and nothing tells it was a lock.
- Validation errors stay form errors (422).
- A **stale admin page** is not a conflict either (see the row version above): its own domain answer, never
  `CONFLICT_001` / `CONFLICT_002`.

## Host options

```yaml
nowo_contact_form:
    conflict_response:
        status: null        # 409 (default) or 503
        retry_after: null   # seconds for CONFLICT_001
        commit_tracking: bundle   # bundle (default), all or off
```

- Unset (null), the bundle follows `nowo_transaction_doctrine.conflict_response` when nowo-tech/transaction-doctrine's
  Symfony bundle is registered, so the whole application answers the same; otherwise 409 with `Retry-After: 1`.
- `status: 503` for proxies or monitors that treat 503 as retryable; the codes and the `Retry-After` rule stay the same.
- `commit_tracking` decides which commits make a later conflict `CONFLICT_002` when transaction-doctrine's
  `commit_detection` is off (or its bundle is not registered):
  - `bundle` (default): the bundle registers transaction-doctrine's `CommitTrackingMiddleware` on the connections of
    its entity managers only: the `doctrine.entity_manager` one when set; unset, your default manager's connection and
    the connection of every manager whose mapping covers `Nowo\ContactFormBundle\Entity` (the runtime resolver,
    `getManagerForClass()`, may pick any of them). Your other connections are not wrapped.
  - `all`: every Doctrine connection, so a commit of yours on another connection earlier in the request counts too.
  - `off`: no middleware; only the failure itself (and transaction-doctrine's request mark) decides.

  With transaction-doctrine's bundle registered and `commit_detection` on (its default), the bundle reuses that
  bundle's ledger and adds no middleware of its own, whatever this option says: no connection is wrapped twice. The
  bundle detects this by transaction-doctrine's `CommitLedger` service AND its tagged
  `nowo_transaction_doctrine.commit_tracking_middleware`; a `CommitLedger` service of your own, without that
  middleware, is ignored and the bundle keeps its own tracking.
- `doctrine.entity_manager`: give the bundle its own manager (see [CONFIGURATION](CONFIGURATION.md)) so a failed flush
  of the bundle never closes yours.
- Database settings: READ COMMITTED and a short `innodb_lock_wait_timeout` / `lock_timeout` (a few seconds) for HTTP;
  the wait is bounded by the database, not by the bundle.

## Retries

- HTTP writes make **one attempt**: the visitor or the admin retries after a `CONFLICT_001`. The bundle has no
  automatic replay, so no entity of an earlier attempt is reused.
- A retried public submission after `CONFLICT_001` stores exactly one submission (nothing of the failed one was
  committed). After `CONFLICT_002` the submission may be stored: the message asks to reload, not to send again.
- The purge is safe to run again: a failed chunk is unchanged and the next run collects the expired ids again.
- The bundle has no Messenger handlers.

## Tests and CI

- `tests/Integration/Database/ContactFormConcurrencyDatabaseTest.php` plays two connections against real servers,
  each session with a 2 s `innodb_lock_wait_timeout` / `lock_timeout`:
  - a public submission during an open purge chunk is stored at once; the purge commits whole; no orphan value;
  - a purge chunk that meets a locked submission rolls back whole, the command fails with "run it again", and the
    next run finishes;
  - a public submission that meets a locked form answers 409 + `Retry-After` + `CONFLICT_001` (503 with the host
    option); nothing is stored, the closed manager is replaced and the retry stores exactly one submission;
  - a lock conflict after a committed submission of the same request answers `CONFLICT_002` without `Retry-After`
    (commit counted by the real commit-tracking middleware);
  - `ContactFormCommitTrackingPassTest` (with DoctrineBundle's `MiddlewaresPass`): `bundle` wraps only the bundle's
    connection, `all` every connection, `off` none, and transaction-doctrine's ledger is reused with no middleware of the
    bundle when its `commit_detection` is on;
  - two admin edits of one form: the second meets the first's lock and answers `CONFLICT_001`, the row is the first
    edit whole, and a retry that reads the form again and changes one column keeps both changes;
  - two admins open the same form (1.12): the first save is whole (`row_version` 2), the second is refused as stale with
    nothing written, its connection and manager stay usable, and after a reload it saves on top of the first;
  - a simultaneous form edit and a simultaneous field edit (a second PHP process holding the row lock): the loser waits,
    then is refused as stale, and the stored row is the winner's whole (no lost update); a save that meets the winner's
    lock past the timeout is `CONFLICT_001` (409 + `Retry-After`), not a stale answer;
  - two admin edits adding the same missing translation keep one translation and answer the domain 409; the same
    failure on the public route is not mapped.
- `tests/Integration/Repository/RowVersionRepositoryTest.php` (SQLite): the guard advances once and refuses the same token
  twice, a missing token and a deleted row are stale, the form and field edits advance the version (a translation-only
  change too), a stale edit writes nothing and a later flush of the manager writes nothing of it.
- `tests/Integration/Controller/ContactFormStaleEditTest.php`: the form edit page and the field wizard carry the token; a
  stale form edit and a stale wizard answer 409 with `CONTACT_FORM_STALE_VERSION` and the message, store nothing, and
  save after a reload; a submission or a wizard without a valid token is refused; a new field needs no token.
- `tests/Unit/EventSubscriber/ContactFormConflictListenerTest.php` maps every case: `CONFLICT_001` for each vendor
  code, `CONFLICT_002` (own ledger, transaction-doctrine's mark, committed `ConcurrencyConflict`, lost savepoint),
  503, a runner's hint, the duplicate key on each admin controller, lost connections and other errors left alone,
  host routes untouched.
- The CI job **Real databases (MySQL 8.4, MariaDB 11.4, PostgreSQL 16)** runs the `database` group on DBAL 3 and 4 with
  `--fail-on-skipped` and is a required check of `master`. The **Identity key collations** jobs (MySQL / MariaDB) stay
  as they were (REQ-SEC-006). The **Dependency floor** job runs the suites with the lowest allowed
  nowo-tech/transaction-* versions and checks that the demo lock installs them.

```bash
make test-with-db   # starts the "databases" compose profile and runs the group
make down-db
```

## Residual risks

- **Nothing accepted for the admin edits of a form or a field** since 1.12: a stale page is refused (see the row version
  above). The version is per row: two admins editing the same form (or the same field) conflict, whatever the columns
  they changed. A host's own template that drops the hidden `rowVersion` field gets the stale answer on every save, never
  a silent overwrite.
- **The request-free port inside your transaction.** `ContactSubmissionRecorderInterface::record()` called while your
  connection is in a transaction flushes inside it; the created event and the notification then run before your
  commit, and a rollback of yours leaves a mail for a submission that is not stored. Call it outside your transaction,
  or send the notification from a Messenger message dispatched after your commit.
- **MySQL / MariaDB foreign-key reads.** A public submission reads the form row with a shared lock (InnoDB's foreign-key
  check), so it waits while an admin save or delete of that form is open; a long admin transaction of yours on the form
  answers the visitor `CONFLICT_001`. PostgreSQL's key-share lock does not wait for a plain update.
- **Commit detection covers the bundle's connection by default.** Without transaction-doctrine's `commit_detection`, a
  commit of yours on another Doctrine connection earlier in the request is counted only with `commit_tracking: all`, and
  a commit through another client (PDO, another library) is never counted.
- **A host closed manager.** Without `doctrine.entity_manager` a failed flush of the bundle closes your default
  manager; the bundle replaces it on its own routes and command (it was closed already), never an open one.
