# Contact Form Bundle — Demo (Symfony 8)

This demo runs with **FrankenPHP** (Caddy on port 80). Runtime mode is controlled by **`FRANKENPHP_MODE`** in `.env` (default **`worker`**). Use `classic` for per-request PHP / easier hot-reload. See [docs/DEMO-FRANKENPHP.md](../../docs/DEMO-FRANKENPHP.md).

## Quick start

```bash
make up
# Open http://localhost:8021 (or the PORT from .env)
```

`make up` copies `.env.example` → `.env` when missing, installs Composer dependencies, runs migrations, seeds demo forms, and prints `Demo started at: http://localhost:<PORT>`.

Language is switched via URL locale prefix (`en`, `es`).

## Demo URLs

Replace `{locale}` with `en` or `es`:

| Path | Description |
| --- | --- |
| `/` | Redirects to `/{locale}/` |
| `/{locale}/` | Home with links to seeded public forms and admin |
| `/{locale}/contact/contact` | Public general contact form |
| `/{locale}/contact/job-application` | Public job application form |
| `/{locale}/contact/partner-inquiry` | Public partner inquiry form |
| `/admin/contact-forms` | Admin CRUD (forms, fields, submissions) |

## Web Profiler toolbar

**Web Profiler** and **Nowo Twig Inspector** are enabled in `dev`. The toolbar appears when:

- `APP_ENV=dev` and `APP_DEBUG=1` (defaults in `.env.example`)
- Dependencies are installed (`make up` / `make install`)

If the toolbar is missing:

```bash
docker compose exec php php bin/console cache:clear --env=dev
```

Then reload. Open `/_profiler` for recent requests.

## Commands

- `make up` — Ensure `.env`, install deps, migrate, seed, start FrankenPHP
- `make down` — Stop containers
- `make install` — `composer install` in the running container
- `make shell` — Shell in the PHP container
- `make update-bundle` — Sync mounted path repository + clear cache
- `make test` — Demo smoke checks (`lint:yaml` + `about`)
