# Coverage policy

## Table of contents

- [PHP line coverage gate](#php-line-coverage-gate)
- [Justified exclusions](#justified-exclusions)
- [How to refresh](#how-to-refresh)

## PHP line coverage gate

`make coverage-check` / `composer coverage-check` enforce **≥ 99%** line coverage on the PHPUnit **includable** `src/` set (`REQ-TEST-003` / `REQ-TEST-006`).

Published README percentage must match the latest `coverage-output.txt` / CI artifact.

## Justified exclusions

The following paths are listed under `<source><exclude>` in `phpunit.xml.dist`. They are covered primarily by **integration / demo smoke** rather than unit Clover:

| Path | Reason |
| --- | --- |
| `src/Controller/ContactFormAdminController.php` | Thin HTTP + CSRF; exercised by `tests/Integration/Controller/*` |
| `src/Controller/ContactFormFieldAdminController.php` | Same |
| `src/Controller/ContactSubmissionAdminController.php` | Same |
| `src/Repository/` | Doctrine `ServiceEntityRepository` wrappers; repository integration tests |
| `src/EventSubscriber/` | Kernel event subscribers; integration + access checker unit tests |
| `src/Form/ContactForm*.php` (wizard / translation / definition step types) | Form types tightly coupled to Symfony Form + Twig; field admin integration tests |
| `src/Form/ContactFormFieldWizardStepTrait.php` | Shared form trait used only by excluded step types |
| `src/Twig/ContactFormAdminTwigExtension.php` | Twig globals; unit test exists but excluded from aggregate Clover noise |

Do **not** add new `@codeCoverageIgnore` in production code without updating this document.

## How to refresh

```bash
make test-coverage
# or
composer coverage-check
```
