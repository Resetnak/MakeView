# Makeview

A PHP dashboard for your local projects. It scans a directory of projects,
parses their **Makefile targets** (documented `target: ## desc` first), pulls
**services and URLs** out of `compose.yml` (including Traefik hostnames), finds
**links and credentials** in READMEs, shows **git branch + last activity** (read
straight from `.git` files, no git binary needed), and renders the READMEs
themselves. Click any command, URL, or credential to copy it.

> UI text is in Czech. No database, no framework, no asset pipeline — one PHP
> file, a handful of small modules, and two Composer packages.

![Dashboard (dark mode)](docs/dashboard-dark.png)

![Project detail (light mode)](docs/project-light.png)

## Install

### Docker (recommended)

```bash
git clone https://github.com/Resetnak/MakeView && cd MakeView
PERSONAL_DIR=~/projects docker compose up -d --build
# → http://localhost:9898
```

`PERSONAL_DIR` is the directory containing your projects (defaults to the
parent of the makeview checkout). It's mounted **read-only** — the app never
writes to your projects.

### Local PHP (no Docker)

Needs PHP 8.2+ and Composer.

```bash
composer install --no-dev
MAKEVIEW_DIR=~/projects php -S 127.0.0.1:9898 index.php
# → http://localhost:9898
```

`MAKEVIEW_DIR` points at your projects directory (defaults to `/projects`).

### Any PHP host

Drop `index.php`, `src/`, and `vendor/` into any PHP 8.2+ webroot and set the
`MAKEVIEW_DIR` env var. Note the app is meant for **your own machine**: it
happily displays everything it finds, including credentials written in your
READMEs, so don't point it at private projects on a public server.

## How it works

- A project is any subdirectory of `MAKEVIEW_DIR` with a `Makefile` or `README.md`.
- Documented targets (`build: ## Compile the thing`) get descriptions; bare targets are listed too.
- Dashboard sorts projects by last git activity (`.git/index` mtime); pins are stored in `localStorage`.
- READMEs render through Parsedown in safe mode; project selection is whitelisted (no path traversal).
- Services come from `compose.yml` (plus `compose.override.yml` when present).
  A Traefik ``Host(`app.localhost`)`` label wins over a published port, so you
  get the address you'd actually type into a browser.
- README links are paired with nearby credentials — from a markdown table, from
  `user:` / `heslo:` lines, or from `export KEY=value` inside a fenced block.
  Pairing never crosses a heading, and each entry shows the section it came from
  so a wrong guess is easy to spot.
- Passwords are masked on screen with a reveal toggle. **This is a
  screen-sharing convenience, not a security feature** — the values are in the
  page source, and anyone with the page has them. Same as before: run this on
  your own machine.
- `.env` files are never read, and `${VAR}` placeholders are shown unresolved.

## Development

```bash
make            # list the available targets
make install    # Composer dependencies
make test       # PHPUnit
```

Composer and PHPUnit run inside Docker through `bin/dev`, because the host PHP
usually lacks the dom/xml/mbstring extensions they need. `make up` starts the
dashboard itself on http://localhost:9898.

Parsers live in `src/` and take file **contents**, not paths — all filesystem
access is in `src/Project.php`. That is what keeps `tests/` free of fixtures on
disk beyond `tests/fixtures/`.

## License

MIT
