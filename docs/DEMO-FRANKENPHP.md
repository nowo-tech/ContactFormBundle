# FrankenPHP demo

The repository includes an **optional Symfony 8 demo app** under `demo/symfony8` (FrankenPHP + Docker Compose). It is excluded from the Packagist package via `archive.exclude` in `composer.json`.

| Demo | PHP | Default HTTP port |
|------|-----|-------------------|
| `demo/symfony8` | 8.4 | 8021 |

See [`demo/README.md`](../demo/README.md) for quick start and aggregate `make` targets.

## Demo pages

The demo homepage links to:

- Public contact form (`/contact/{slug}`) after seeding
- Admin CRUD (`/admin/contact-forms`)
- Notification examples (composite notifier in demo app code)

Run `make -C demo/symfony8 up`. The Makefile copies `.env.example` → `.env`, starts FrankenPHP, runs `composer install`, migrations, and prints `Demo started at: http://localhost:<PORT>`.

## Worker mode

FrankenPHP **worker mode** is optional for production deployments. The demo runs in traditional mode for simplicity. For worker mode, see [FrankenPHP documentation](https://frankenphp.dev/docs/worker/).

## Root Docker vs demo Docker

The `Dockerfile` and `docker-compose.yml` at the **repository root** are for **developer QA** (`make up`, Composer, PHPUnit, PHPStan) — not for serving the demo.

## Conventions (Nowo bundles)

- Web Profiler + Twig Inspector in dev
- Path repository to the mounted bundle (`/var/contact-form-bundle`)
- `make up` prints `Demo started at: http://localhost:<PORT>`
- Composer DNS fallbacks in demo compose (`dns: 8.8.8.8, 8.8.4.4`) for Docker/WSL
- `make update-bundle` syncs the mounted bundle before release verification (`REQ-DEMO-007`)
