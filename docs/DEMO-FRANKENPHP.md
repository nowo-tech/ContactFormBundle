# FrankenPHP demo

**REQ-DEMO-001:** FrankenPHP demos must install **Nowo Twig Inspector** and **Nowo Hot Reload** together (`nowo-tech/twig-inspector-bundle` + `nowo-tech/hot-reload-bundle` in `require-dev`). Caddyfile: Mercure + `hot_reload` (and `worker { file …; watch }` in worker mode). Do not enable Hot Reload in production.

The repository includes an **optional Symfony 8 demo app** under `demo/symfony8` (FrankenPHP + Docker Compose). It is excluded from the Packagist package via `archive.exclude` in `composer.json`.

| Demo | PHP | Default HTTP port |
|------|-----|-------------------|
| `demo/symfony8` | 8.5 | 8021 |

See [`demo/README.md`](../demo/README.md) for quick start and aggregate `make` targets.

## Demo pages

The demo homepage links to:

- Public contact form (`/contact/{slug}`) after seeding
- Admin CRUD (`/admin/contact-forms`)
- Notification examples (composite notifier in demo app code)

Run `make -C demo/symfony8 up`. The Makefile copies `.env.example` → `.env`, starts FrankenPHP, runs `composer install`, migrations, and prints `Demo started at: http://localhost:<PORT>`.

## Worker mode / `FRANKENPHP_MODE`

The demo defaults to FrankenPHP **worker** mode (`FRANKENPHP_MODE=worker`). Switch to classic (per-request PHP, hot-reload friendly) via `.env` / `.env.example` — not a Dockerfile `ENV`:

| Value | Behaviour |
| --- | --- |
| **`worker`** (default) | Keep the worker Caddyfile (`php_server { worker ... }`) |
| **`classic`** | Entrypoint copies `Caddyfile.dev` (plain `php_server`) |

Compose passes `FRANKENPHP_MODE=${FRANKENPHP_MODE:-worker}` into the PHP service. After changing `.env`, run `docker compose up -d` (or `make up`) so the container is **recreated** — a plain `restart` does not reload env. No image rebuild is required. See [FrankenPHP worker docs](https://frankenphp.dev/docs/worker/).

## Root Docker vs demo Docker

The `Dockerfile` and `docker-compose.yml` at the **repository root** are for **developer QA** (`make up`, Composer, PHPUnit, PHPStan) — not for serving the demo.

## Conventions (Nowo bundles)

- Web Profiler + Twig Inspector in dev
- Path repository to the mounted bundle (`/var/contact-form-bundle`)
- `make up` prints `Demo started at: http://localhost:<PORT>`
- Composer DNS fallbacks in demo compose (`dns: 8.8.8.8, 8.8.4.4`) for Docker/WSL
- `make update-bundle` syncs the mounted bundle before release verification (`REQ-DEMO-007`)
- Runtime mode via `FRANKENPHP_MODE` and `docker/entrypoint.sh` (`REQ-DEMO-010`)
