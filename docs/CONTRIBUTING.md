# Contributing

Contributions are welcome. Please open an issue or a pull request on [GitHub](https://github.com/nowo-tech/ContactFormBundle).

- Follow the code style (PHP-CS-Fixer, see root `.php-cs-fixer.dist.php`).
- Run `make cs-check`, `make rector-dry`, `make phpstan`, `make igor`, and `make test` (or `make release-check`) before submitting.

## Table of contents

- [Code of Conduct](#code-of-conduct)
- [Cursor Agent](#cursor-agent)
- [Git hooks (REQ-GIT-001)](#git-hooks-req-git-001)
- [Demo e2e and screenshots (REQ-DEMO-013)](#demo-e2e-and-screenshots-req-demo-013)

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](../CODE_OF_CONDUCT.md). By participating, you are expected to uphold it. Please report unacceptable behavior to **hectorfranco@nowo.tech**.

## Cursor Agent

Contributors using Cursor Agent on this repository should enable these user-level settings (REQ-IDE-006):

1. **Submit with Ctrl + Enter** (macOS: **⌘ + Enter**) — Cursor Settings → Agents / Chat → Submit with Ctrl + Enter. Enter inserts a newline; Ctrl/⌘+Enter submits the prompt.
2. **Queue follow-ups while the agent is running** — in your user `settings.json` (Linux `~/.config/Cursor/User/settings.json`, Windows `%APPDATA%\Cursor\User\settings.json`, macOS `~/Library/Application Support/Cursor/User/settings.json`):

```json
{
  "cursor.composer.queueMessageDefaultBehavior": "queue"
}
```

Do not use `"steer"` as the default: it interrupts the running agent turn as soon as the follow-up is sent.

## Git hooks (REQ-GIT-001)

Do **not** add `Co-authored-by: Cursor` or `cursoragent@cursor.com` trailers, nor `Co-Authored-By: Claude …` / `noreply@anthropic.com` ones, to commit messages.

```bash
make setup-hooks
make check-no-cursor-coauthor
```

`make setup-hooks` installs `.githooks/commit-msg` (or sets `core.hooksPath` to `.githooks`). Run it once per clone before your first commit.
If CI fails because trailers are already on the remote, see [GITHUB_CI.md](GITHUB_CI.md) (REQ-GIT-001) and run `make strip-cursor-coauthor-from-history` before `git push --force-with-lease`.

## Demo e2e and screenshots (REQ-DEMO-013)

Playwright lives in `demo/symfony8/e2e` and runs in the official Playwright Docker image against the running demo
(`PORT` from `demo/symfony8/.env`):

```bash
make test-e2e            # = make -C demo/symfony8 test-e2e: public form + admin pages (lists, confirm dialog, wizard, detail)
make demo-screenshots    # reseeds the demo inbox, captures PNGs, converts them to WebP (sharp) in docs/images/demo/
```

`make release-check` runs `test-e2e` after the demo release check. Screenshots keep the full demo frame (navbar, page,
Symfony WebProfiler toolbar); look at them before committing.

