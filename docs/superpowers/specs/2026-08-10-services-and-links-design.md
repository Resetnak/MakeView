# Makeview: services and links from compose.yml and README.md

Design document. 2026-08-10.

## Problem

Makeview currently reads only Makefiles and renders READMEs as prose. The
information a developer actually needs to start working on a project — the URL
of the local dev instance, the Argo CD address, the admin credentials — is
present in `compose.yml` and `README.md` but is not surfaced. It has to be
found by reading, and credentials in particular are scattered across README
prose in inconsistent formats.

## Goal

Extract two new kinds of information per project and present them as dedicated
sections in the project detail view:

1. **Services** — from `compose.yml`: what runs, at what URL, with what credentials.
2. **Links from README** — URLs found in the README, paired with any credentials
   stated near them.

The two are kept separate because compose ports and README credentials often
describe different things, and merging them would produce false pairings.

## Non-goals

- Reading `.env` files or resolving `${VAR}` interpolation. Makeview is a
  read-only viewer; `.env` is typically gitignored and sensitive.
- Executing anything, writing anything, or contacting any network host.
- Parsing compose files outside the project root.
- Treating credential masking as a security boundary. It is a screen-sharing
  convenience only.

## Architecture

The project moves from a single `index.php` to a small module layout. Composer
is introduced, which also replaces the manually vendored `Parsedown.php`.

```
MakeView/
  index.php              routing + view (~380 lines)
  composer.json          require: symfony/yaml, erusev/parsedown
                         require-dev: phpunit/phpunit
  src/
    Project.php          project scan, metadata, file autodetection
    Make.php             parse_targets (moved out of index.php)
    Compose.php          compose + override merge; ports/env/traefik -> Service[]
    Readme.php           link extraction + credential heuristics -> Link[]
    Value/Service.php    readonly DTO: name, url, credentials[], source
    Value/Link.php       readonly DTO: label, url, credentials[], context, confidence
    Value/Credential.php readonly DTO: kind, label, value, isPlaceholder
  tests/
    ComposeTest.php
    ReadmeTest.php
    MakeTest.php
    fixtures/            sample compose.yml and README.md variants
```

Parsers are pure functions: string in, DTO out. They perform no file I/O — the
caller reads the file and passes the contents. This is what makes them testable
without mocking, and it keeps all filesystem access in one place.

`index.php` after the refactor contains only `require vendor/autoload.php`,
routing, and the HTML view.

`Dockerfile` gains a `composer install --no-dev` step and drops the `ADD` of
`Parsedown.php`. `.gitignore` drops the `Parsedown.php` entry and gains
`/vendor/`.

## Compose parsing

### File discovery and merge

Base file, first match wins: `compose.yml`, `compose.yaml`,
`docker-compose.yml`, `docker-compose.yaml`.

Override file, first match wins: `compose.override.yml`, `compose.override.yaml`,
`docker-compose.override.yml`, `docker-compose.override.yaml`.

Only the project root is searched. The override file is where dev ports and
Traefik labels usually live, so ignoring it would show the production variant.

Merge rules:

- Recursive deep merge; the override wins on scalars and map keys.
- Sequences (`ports`, `labels`, `volumes`) are **replaced wholesale**, not
  concatenated. This matches how `docker compose` treats `ports`.
- `environment` accepts both forms (a `KEY: value` map and a `KEY=value`
  sequence). Normalize to a map **before** merging, otherwise an override in
  the other form would not override anything.

### URL derivation, Traefik first

If the service has a label `traefik.http.routers.<name>.rule`, extract every
`` Host(`...`) `` value with a regex. Each host becomes a URL.

- Scheme is `https` when the router has a `.tls` label or its `entrypoints`
  value contains `secure`, `https`, or `websecure`; otherwise `http`.
- A `` PathPrefix(`/x`) `` in the same rule is appended to the path **only when
  the rule contains exactly one** `Host()`. With multiple hosts the mapping is
  ambiguous, so the path is dropped.
- No expression parser is built. Complex rules
  (`` Host(`a`) && PathPrefix(`/b`) || Host(`c`) ``) degrade to hosts without
  paths, which is still usable.

Result form: `https://argo.localhost` — the address as typed into a browser, not
`localhost:port`.

### URL derivation, port fallback

Used when the service has no Traefik host. Accepted syntaxes:

- `"8080:80"`
- `"127.0.0.1:8080:80"`
- long form `{published: 8080, target: 80}`

The published port becomes `http://localhost:<published>`.

If the **target** port is a well-known non-HTTP service port
(5432, 3306, 6379, 27017, 9200, 5672, 11211), no URL is produced. Such a service
still appears in the list carrying its credentials, since those are useful for a
database client.

### Credentials from `environment`

Key patterns: `*_USER`, `*_USERNAME`, `*_PASSWORD`, `*_PASS`,
`*_ROOT_PASSWORD`, `*_TOKEN`, `*_API_KEY`, `*_SECRET`.

Kind is derived from the key: keys matching `USER`/`USERNAME` become `user`,
keys matching `TOKEN`/`API_KEY` become `token`, the rest become `password`.

A value of the form `${VAR}` or `${VAR:-default}` is kept **verbatim** and
flagged as a placeholder. It is a substitution marker, not a secret, so it is
displayed unmasked and without a copy button. `env_file` is never read.

The key-pattern vocabulary lives in one place and is shared with the README
parser — one definition, two consumers.

### Output

`Service[]`, each: `name`, `?url`, `Credential[]`, `source: 'compose'`, and a
`urlSource` of `traefik` or `port` for the UI badge.

## README parsing

The fragile part. The strategy is: split into sections, extract within each
section independently, pair by proximity, never pair across a section boundary.

### Step 1 — segmentation

Split the README on ATX headings (`#` through `######`). Each section carries
its heading text as `context`. Fenced code blocks stay inside their section, but
markdown links are **never** taken from inside a fence — a URL in a code block
is an example, not an access point.

### Step 2 — section priority

A section whose heading matches
`přístup|credential|login|heslo|účt|access|dev|local|služb|service|url|odkaz|argo|admin`
is treated as high-priority. Extraction still runs everywhere; priority only
affects ordering in the UI, not whether a candidate is collected.

### Step 3 — extractors, in order of reliability

**a) Table.** A markdown table whose header row contains both a URL/service-like
column and a user- or password-like column. Each body row becomes one `Link`
with complete credentials. This is the most reliable pattern and supplies the
pairing for free. Confidence: `table`.

**b) Definition lines.** `user: admin`, `heslo: xyz`, `Username — admin`,
`**Password:** xyz`, `ARGOCD_PASSWORD=xyz`. The key matches a Czech and English
vocabulary: `user|uživatel|jméno|login|username|heslo|password|pass|token|api.?key`.
The value is the remainder of the line after the separator (`:`, `=`, `—`, `-`),
stripped of markdown emphasis and backticks. Confidence: `definition`.

**c) Fenced env exports.** Inside a fenced block, lines of the form
`export KEY=value` or `KEY=value` where KEY matches the shared credential key
patterns. Confidence: `env`.

**d) Links.** Markdown `[label](url)` and bare `https?://…`. The label comes
from the markdown text; for a bare URL the label is the hostname.

### Step 4 — pairing by proximity

A credential is attached to the **nearest preceding link within the same
section**. If no link precedes it in that section, the credential attaches to
the section itself, producing a `Link` with no URL whose `context` is the
heading. Pairing never crosses a section boundary — this is what prevents a
production password from landing on a dev link. Confidence for
proximity-derived pairs: `proximity`.

### Step 5 — noise filters

Discarded outright:

- values longer than 200 characters
- values containing whitespace **and** longer than 40 characters (a sentence,
  not a secret)

Kept but flagged as placeholders (displayed unmasked, no copy button, italic):
`<…>`, `xxx`, `TODO`, `changeme`, `your-password`, `***`, and similar. These are
instructions to the reader, not data.

### Output

`Link[]`, each: `label`, `?url`, `Credential[]`, `context` (section heading),
`confidence` (`table` | `definition` | `env` | `proximity`).

### Known limitation

Step 4 is a heuristic and will mis-pair on loosely structured READMEs. This is
mitigated, not solved, by always displaying `context` in the UI so the user can
see which section a credential came from and spot a wrong pairing. Without that,
a silent mis-pairing would be untraceable.

## UI

Two new sections in the project detail view, placed between "Make příkazy" and
"README". Styling follows the existing vocabulary (`.sect` headings, `.cmds`
table, `.badge`).

### Services

A `.cmds`-style table. Columns: service name / URL / credentials.

```
## SLUŽBY
argocd     https://argo.localhost  [traefik] ⧉    admin ⧉   ••••••• 👁 ⧉
postgres   —                                      app   ⧉   ••••••• 👁 ⧉
web        http://localhost:8080   [localhost] ⧉
```

A service with no URL shows an em dash; it still earns its row through its
credentials. The badge distinguishes a Traefik-derived URL from a port-derived
one, so the origin of the address is visible.

### Links from README

Row-based, because the structure is looser.

```
## ODKAZY Z README
Argo CD                                                    ## Přístupy
https://argo.example.dev  ⧉      admin ⧉      ••••••• 👁 ⧉
```

The label is bold; `context` sits to the right in muted `.rmeta` styling. That
context line is the traceability element described above.

### Credential widget

Reuses the existing `.cmd` copy mechanics.

- `user`: plain text, click to copy.
- `password` / `token`: `•••••••` — a fixed seven dots, never the real length —
  plus an eye toggle and a copy button.
- The eye reveals one credential at a time; clicking elsewhere or toggling again
  re-masks it.
- Placeholder values render italic and muted, unmasked, with no copy button.

The value lives in a `data-val` attribute, base64-encoded so it does not shout
from view-source. This is explicitly **not** a security control — anyone with
devtools has the value. Masking addresses screen sharing and nothing more.

JavaScript decodes with `atob` and inserts exclusively via `textContent`, never
`innerHTML`.

### Empty states

If no compose file exists, or nothing was extracted from it, the Services
section is not rendered at all — no empty box. The same applies to README links.
A project with neither looks exactly as it does today.

### Catalog

The catalog `.rmeta` line is left unchanged. Adding a service count would
require parsing every project's YAML on the home page, which is not worth the
cost (see Performance).

## Error handling

Makeview is a viewer: one broken project must never take down the page. Each
parser is wrapped so that failure yields an empty result rather than a thrown
exception.

- Invalid YAML (`Symfony\Component\Yaml\Exception\ParseException`) → empty
  service list, and the UI shows `.empty` text: "compose.yml se nepodařilo
  načíst".
- Unreadable file (permissions) → same treatment.
- README parsing cannot throw; regexes over a string either match or do not.

No `@` suppression anywhere, and no silent swallowing: if something is present
and could not be read, the user sees that fact.

## Security

- Every new value passes through `h()` — labels, URLs, credentials, contexts.
  The input is an untrusted third-party file.
- URLs are additionally scheme-validated: only `http` and `https` render as
  `<a href>`. Without this, a `javascript:` URL in a markdown link is an XSS
  vector.
- `data-val` is decoded with `atob` and written with `textContent` only.
- Path traversal: new files are read by composing `ROOT . '/' . $name` where
  `$name` has already passed the existing project whitelist. The attack surface
  is unchanged.
- Reading is strictly read-only. No `env_file`, no `.env`, no execution.

## Performance

The home page already parses every project's Makefile to count targets. Adding
YAML parsing for every project would be noticeably more expensive.

Mitigation: the home page parses compose only for the featured project. The
catalog rows carry no service count. In the project detail view only one project
is parsed, where the cost is negligible.

## Testing

PHPUnit, target 80%+ coverage. Parsers are pure functions, so tests need no
mocking. TDD order: tests first from real-world fixtures, then implementation.

**ComposeTest**
- base + override merge: scalar, map, and sequence (`ports` replaced, not merged)
- `environment` in both forms, including override across forms
- all three port syntaxes
- Traefik `Host()`: simple, with `&&`, with `||`
- https detection via `.tls` and via entrypoint name
- well-known DB target port yields no URL
- `${VAR}` retained verbatim and flagged as placeholder

**ReadmeTest**
- each extractor in isolation: table, definition lines (Czech and English),
  fenced env export, links
- pairing within a section
- **no pairing across a section boundary** — the key test
- placeholder filters
- URLs inside code fences are ignored

**MakeTest**
- regression coverage for the relocated `parse_targets`, so the refactor is
  provably behavior-preserving

`tests/fixtures/` holds real samples: a README with a credentials table, a
README with loose structure, a README containing both production and dev
sections (the pairing trap), a compose file with Traefik, and a base+override
pair.

## Documentation

`README.md` gains a short section describing the two new views and stating
plainly that credential masking is a screen-sharing convenience, not a security
boundary. The install instructions change because Parsedown is no longer fetched
by hand — `composer install` replaces it.
