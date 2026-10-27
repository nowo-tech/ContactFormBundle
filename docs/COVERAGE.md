# Coverage policy

## Table of contents

- [PHP line coverage gate](#php-line-coverage-gate)
- [Exclusions](#exclusions)
- [What the suites cover](#what-the-suites-cover)
- [How to refresh](#how-to-refresh)

## PHP line coverage gate

`make test-coverage`, `make coverage-check` / `composer coverage-check` and the CI **Code Coverage** job enforce
**100%** line coverage of `src/` (`REQ-TEST-003` / `REQ-TEST-006`): `.scripts/coverage-fail-under.sh` reads the
PHPUnit `Lines:` summary and exits non-zero below the gate.

Published README percentage must match the latest `coverage-output.txt` / CI artifact.

## Exclusions

None: `phpunit.xml.dist` includes the whole `src/` directory (no `<exclude>`), and production code carries no
`@codeCoverageIgnore`. Two invariants that cannot fail are written as `assert()` instead of an untestable branch:

| Where | Invariant |
| --- | --- |
| `ContactFormFieldAdminController::handleForm()` | the FormFlow data is a `ContactFormField` (`data_class`) |
| `ContactFormRichTextSanitizer::sanitize()` | `NOIMPLIED` parsing keeps the wrapper `<div>` as the first element |

## What the suites cover

| Suite | Scope |
| --- | --- |
| `tests/Unit` | services, entities, DI (configuration, extension, compiler passes), form types (field wizard steps as `inherit_data` children, as in the FormFlow), view-model factory, event subscribers, repositories (manager registry stubs) |
| `tests/Integration/Controller` | admin CRUD, the field wizard end to end, submissions, public form, worker mode — functional tests on `TestKernel` (SQLite) |
| `tests/Integration/Contract` | UiKit `PageContractTestCase` for every page (REQ-UI-003) and the deprecated 1.x layout bridge |
| `tests/Integration/Repository` | repository queries on SQLite |

## How to refresh

```bash
make test-coverage
# or
composer coverage-check
```
