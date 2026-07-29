# Contact Form Bundle

[![CI](https://github.com/nowo-tech/ContactFormBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/ContactFormBundle/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/contact-form-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/contact-form-bundle) [![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/contact-form-bundle.svg)](https://packagist.org/packages/nowo-tech/contact-form-bundle) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net) [![Symfony](https://img.shields.io/badge/Symfony-7.4%20%7C%208.0%20%7C%208.1%2B-000000?logo=symfony)](https://symfony.com) [![GitHub stars](https://img.shields.io/github/stars/nowo-tech/contact-form-bundle.svg?style=social&label=Star)](https://github.com/nowo-tech/ContactFormBundle) [![Coverage](https://img.shields.io/badge/Coverage-99.45%25-brightgreen)](#tests-and-coverage)

> ⭐ **Found this useful?** [Install from Packagist](https://packagist.org/packages/nowo-tech/contact-form-bundle) · Give it a **star** on [GitHub](https://github.com/nowo-tech/ContactFormBundle) so more developers can find it.

**Contact Form Bundle** — configurable multilingual contact forms with GDPR consent, customizable fields (email, phone, text, textarea, select), optional client association, anonymous submissions, and admin CRUD. For Symfony 7.4+ and 8 · PHP 8.2+.

![FrankenPHP Friendly Worker Mode](docs/images/frankenphp-friendly.png)

This bundle is **FrankenPHP worker mode friendly**.

## Features

- ✅ **Admin CRUD** for form definitions, customizable fields, and submissions
- ✅ **Multilingual** copy via translation entities (title, labels, GDPR text, success message)
- ✅ **Customizable fields**: text, email, phone, textarea, select (options per field)
- ✅ **GDPR**: required consent checkbox, privacy policy link, IP anonymization, retention days
- ✅ **Client association**: optional link to an existing host client entity or anonymous submissions
- ✅ **Public form** at `/contact/{slug}` with PRG flow and flash success message
- ✅ **Override** Twig templates and translations from your application
- ✅ **Pluggable notifications** (custom notifier, Symfony Mailer, or `ContactSubmissionCreatedEvent`)
- ✅ **GDPR retention cleanup** via `nowo:contact-form:cleanup-submissions`

## Installation

```bash
composer require nowo-tech/contact-form-bundle
```

Register in `config/bundles.php` if Flex recipe is not applied:

```php
Nowo\ContactFormBundle\NowoContactFormBundle::class => ['all' => true],
```

Import routes in `config/routes.yaml`:

```yaml
nowo_contact_form:
    resource: '@NowoContactFormBundle/Resources/config/routes.yaml'
```

Run Doctrine migrations after mapping entities (bundle registers ORM mappings automatically).

## Requirements

- PHP 8.2+
- Symfony 7.4+ or 8.0+
- Doctrine ORM 2.17+ or 3.x

## Demo

```bash
make -C demo/symfony8 up
```

Default URL: `http://localhost:8021` (see demo `.env.example` for `PORT`).

**FrankenPHP worker mode:** default is `FRANKENPHP_MODE=worker` (see `.env.example`). Set `classic` for per-request PHP / easier hot-reload, then recreate the container (`docker compose up -d`). Details: [docs/DEMO-FRANKENPHP.md](docs/DEMO-FRANKENPHP.md).

## Development

```bash
make up
make test
make qa
make release-check
```

## Documentation

- [GitHub Actions CI requirements](docs/GITHUB_CI.md)
- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Release](docs/RELEASE.md)
- [Security](docs/SECURITY.md)
- [Coverage policy](docs/COVERAGE.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)
- [GitHub Spec Kit](docs/SPEC-KIT.md)

### Additional documentation

- [Demo FrankenPHP](docs/DEMO-FRANKENPHP.md) (when using the FrankenPHP demo)

## Tests and coverage

| Language | Coverage |
| --- | --- |
| PHP | **99.45%** line coverage on the includable `src/` set (run `make test-coverage` to refresh). See [`docs/COVERAGE.md`](docs/COVERAGE.md). |
| TS/JS | N/A |
| Python | N/A |

## License & author

MIT · [Nowo.tech](https://nowo.tech) · Héctor Franco Aceituno
