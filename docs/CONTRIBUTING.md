# Contributing

Contributions are welcome. Please open an issue or a pull request on [GitHub](https://github.com/nowo-tech/ContactFormBundle).

- Follow the code style (PHP-CS-Fixer, see root `.php-cs-fixer.dist.php`).
- Run `make cs-check`, `make rector-dry`, `make phpstan`, and `make test` (or `make release-check`) before submitting.

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](../CODE_OF_CONDUCT.md). By participating, you are expected to uphold it. Please report unacceptable behavior to **hectorfranco@nowo.tech**.

## Git hooks (REQ-GIT-001)

Do **not** add `Co-authored-by: Cursor` or `cursoragent@cursor.com` trailers to commit messages.

```bash
make setup-hooks
make check-no-cursor-coauthor
```

`make setup-hooks` installs `.githooks/commit-msg` (or sets `core.hooksPath` to `.githooks`). Run it once per clone before your first commit.
If CI fails because trailers are already on the remote, see [GITHUB_CI.md](GITHUB_CI.md) (REQ-GIT-001) and run `make strip-cursor-coauthor-from-history` before `git push --force-with-lease`.
