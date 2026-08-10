# Services and Links Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Surface dev URLs and credentials in Makeview by parsing `compose.yml` and `README.md`, shown as two new sections in the project detail view.

**Architecture:** Move parsing out of `index.php` into `src/` modules loaded by Composer's autoloader. Parsers are pure functions — the caller reads the file, the parser takes a string and returns readonly DTOs. All filesystem access stays in `index.php`. Two independent extraction paths (compose, README) feed two independent UI sections; they are never merged.

**Tech Stack:** PHP 8.2+, `symfony/yaml`, `erusev/parsedown`, PHPUnit 11. No framework, no database, no build step.

**Spec:** `docs/superpowers/specs/2026-08-10-services-and-links-design.md`

## Global Constraints

- PHP 8.2+ minimum. Local dev runs PHP 8.4.24; the Docker image is `php:8.3-cli-alpine`. Code must run on all three.
- `declare(strict_types=1);` at the top of every file in `src/` and `tests/`.
- PSR-12 formatting. Namespace root is `Makeview\`, mapped to `src/`.
- All DTOs are `final readonly class` with promoted constructor properties. Never mutate a DTO — build a new one.
- Parsers perform **zero** filesystem I/O. They accept string contents. The caller reads files.
- Every value originating from a project file is escaped with `h()` before it reaches HTML.
- Only `http` and `https` URLs render as `<a href>`. Any other scheme renders as plain text.
- Never read `.env` or `env_file`. Never resolve `${VAR}` interpolation — keep it verbatim.
- UI text is Czech, matching the existing interface. Code, comments, and commit messages are English.
- Credential masking is a screen-sharing convenience, never described in code or docs as a security boundary.
- Commit after every task. Conventional commit format (`feat:`, `refactor:`, `test:`, `docs:`, `chore:`).

---

## File Structure

| File | Responsibility |
|---|---|
| `composer.json` | Dependencies, autoload map, test script |
| `src/Value/Credential.php` | DTO: one credential (kind, label, value, isPlaceholder) |
| `src/Value/Service.php` | DTO: one compose service (name, url, urlSource, credentials) |
| `src/Value/Link.php` | DTO: one README link (label, url, context, confidence, credentials) |
| `src/CredentialKeys.php` | Shared credential key vocabulary + placeholder detection |
| `src/Make.php` | `parse_targets`, relocated verbatim from `index.php` |
| `src/Compose.php` | YAML merge, ports/env/Traefik extraction |
| `src/Readme.php` | Section split, four extractors, proximity pairing |
| `src/Project.php` | Filesystem: project scan, metadata, compose file discovery |
| `index.php` | Routing + HTML view only |
| `tests/*Test.php` | One test class per parser |
| `tests/fixtures/` | Real-world compose and README samples |

Tasks 1-4 build bottom-up (no dependencies), Tasks 5-6 are the two parsers, Task 7 wires the view, Task 8 ships the container and docs.

---

## Task 1: Composer setup and autoloading

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml`
- Modify: `.gitignore`
- Delete: `Parsedown.php` reference in `.gitignore`

**Interfaces:**
- Consumes: nothing
- Produces: `vendor/autoload.php` mapping `Makeview\` → `src/`; the `composer test` script; PHPUnit configured to discover `tests/`

- [ ] **Step 1: Create `composer.json`**

```json
{
    "name": "resetnak/makeview",
    "description": "A single-file-ish PHP dashboard for your local projects.",
    "type": "project",
    "license": "MIT",
    "require": {
        "php": ">=8.2",
        "erusev/parsedown": "^1.7",
        "symfony/yaml": "^6.4"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": {
            "Makeview\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Makeview\\Tests\\": "tests/"
        }
    },
    "scripts": {
        "test": "phpunit",
        "test:coverage": "phpunit --coverage-text"
    },
    "config": {
        "sort-packages": true
    }
}
```

`symfony/yaml` is pinned to `^6.4` because it is the current LTS and supports PHP 8.2 — `^7.0` requires PHP 8.2 too but drops support sooner. Either works; 6.4 is the conservative choice.

- [ ] **Step 2: Create `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         failOnWarning="true"
         failOnRisky="true">
    <testsuites>
        <testsuite name="Makeview">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 3: Install dependencies**

Run: `composer install`
Expected: `vendor/` created, `composer.lock` written, no errors. Composer may print `Deprecation Notice: Constant E_STRICT is deprecated` on PHP 8.4 — that is composer itself, not this project, and is harmless.

- [ ] **Step 4: Update `.gitignore`**

Replace the Parsedown block. The file should read:

```gitignore
# Composer dependencies
/vendor/

# Internal working docs & tooling — not part of the public app
.impeccable/
CLAUDE.md
DESIGN.md
PRODUCT.md

# OS noise
.DS_Store
```

Note `composer.lock` is deliberately **not** ignored — it is committed so the Docker build is reproducible.

- [ ] **Step 5: Remove the stale downloaded Parsedown, if present**

Run: `rm -f Parsedown.php`
Expected: no output. The file was gitignored, so this touches nothing tracked.

- [ ] **Step 6: Verify autoloading works**

Run: `php -r 'require "vendor/autoload.php"; echo class_exists("Symfony\\Component\\Yaml\\Yaml") ? "yaml ok" : "yaml MISSING"; echo PHP_EOL; echo class_exists("Parsedown") ? "parsedown ok" : "parsedown MISSING"; echo PHP_EOL;'`
Expected:
```
yaml ok
parsedown ok
```

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock phpunit.xml .gitignore
git commit -m "chore: introduce composer with symfony/yaml, parsedown, phpunit"
```

---

## Task 2: Credential value object and shared key vocabulary

**Files:**
- Create: `src/Value/Credential.php`
- Create: `src/CredentialKeys.php`
- Create: `tests/CredentialKeysTest.php`

**Interfaces:**
- Consumes: Task 1 autoloader
- Produces:
  - `Makeview\Value\Credential` — `final readonly class` with public properties `string $kind` (one of `user`, `password`, `token`), `string $label`, `string $value`, `bool $isPlaceholder`; static factory `Credential::fromKey(string $key, string $value): self`
  - `Makeview\CredentialKeys::matches(string $key): bool`
  - `Makeview\CredentialKeys::kindFor(string $key): string`
  - `Makeview\CredentialKeys::isPlaceholder(string $value): bool`
  - `Makeview\CredentialKeys::isNoise(string $value): bool`

- [ ] **Step 1: Write the failing test**

Create `tests/CredentialKeysTest.php`:

```php
<?php

declare(strict_types=1);

namespace Makeview\Tests;

use Makeview\CredentialKeys;
use Makeview\Value\Credential;
use PHPUnit\Framework\TestCase;

final class CredentialKeysTest extends TestCase
{
    public function testMatchesCommonCredentialKeys(): void
    {
        self::assertTrue(CredentialKeys::matches('POSTGRES_PASSWORD'));
        self::assertTrue(CredentialKeys::matches('MYSQL_ROOT_PASSWORD'));
        self::assertTrue(CredentialKeys::matches('ARGOCD_USER'));
        self::assertTrue(CredentialKeys::matches('APP_USERNAME'));
        self::assertTrue(CredentialKeys::matches('GITHUB_TOKEN'));
        self::assertTrue(CredentialKeys::matches('STRIPE_API_KEY'));
        self::assertTrue(CredentialKeys::matches('SESSION_SECRET'));
        self::assertTrue(CredentialKeys::matches('DB_PASS'));
    }

    public function testDoesNotMatchUnrelatedKeys(): void
    {
        self::assertFalse(CredentialKeys::matches('NODE_ENV'));
        self::assertFalse(CredentialKeys::matches('PORT'));
        self::assertFalse(CredentialKeys::matches('POSTGRES_DB'));
        self::assertFalse(CredentialKeys::matches('TZ'));
    }

    public function testMatchingIsCaseInsensitive(): void
    {
        self::assertTrue(CredentialKeys::matches('postgres_password'));
        self::assertTrue(CredentialKeys::matches('Db_User'));
    }

    public function testKindForDistinguishesUserPasswordAndToken(): void
    {
        self::assertSame('user', CredentialKeys::kindFor('POSTGRES_USER'));
        self::assertSame('user', CredentialKeys::kindFor('APP_USERNAME'));
        self::assertSame('token', CredentialKeys::kindFor('GITHUB_TOKEN'));
        self::assertSame('token', CredentialKeys::kindFor('STRIPE_API_KEY'));
        self::assertSame('password', CredentialKeys::kindFor('POSTGRES_PASSWORD'));
        self::assertSame('password', CredentialKeys::kindFor('DB_PASS'));
        self::assertSame('password', CredentialKeys::kindFor('SESSION_SECRET'));
    }

    public function testUserWinsOverPasswordWhenKeyContainsBoth(): void
    {
        // A key like USER_PASSWORD is a password, not a user — password must win here.
        self::assertSame('password', CredentialKeys::kindFor('USER_PASSWORD'));
    }

    public function testDetectsPlaceholderValues(): void
    {
        self::assertTrue(CredentialKeys::isPlaceholder('${DB_PASSWORD}'));
        self::assertTrue(CredentialKeys::isPlaceholder('${DB_PASSWORD:-secret}'));
        self::assertTrue(CredentialKeys::isPlaceholder('<your-password>'));
        self::assertTrue(CredentialKeys::isPlaceholder('changeme'));
        self::assertTrue(CredentialKeys::isPlaceholder('CHANGEME'));
        self::assertTrue(CredentialKeys::isPlaceholder('your-password'));
        self::assertTrue(CredentialKeys::isPlaceholder('xxx'));
        self::assertTrue(CredentialKeys::isPlaceholder('TODO'));
        self::assertTrue(CredentialKeys::isPlaceholder('***'));
        self::assertTrue(CredentialKeys::isPlaceholder('...'));
    }

    public function testRealValuesAreNotPlaceholders(): void
    {
        self::assertFalse(CredentialKeys::isPlaceholder('admin'));
        self::assertFalse(CredentialKeys::isPlaceholder('s3cr3t-p4ss'));
        self::assertFalse(CredentialKeys::isPlaceholder('hunter2'));
    }

    public function testDetectsNoiseValues(): void
    {
        self::assertTrue(CredentialKeys::isNoise(str_repeat('a', 201)));
        // whitespace and over 40 chars: a sentence, not a secret
        self::assertTrue(CredentialKeys::isNoise('run the command below to generate a token'));
        self::assertTrue(CredentialKeys::isNoise(''));
    }

    public function testShortValuesWithSpacesAreNotNoise(): void
    {
        // Some passwords legitimately contain a space and stay short.
        self::assertFalse(CredentialKeys::isNoise('correct horse battery'));
        self::assertFalse(CredentialKeys::isNoise('admin'));
    }

    public function testCredentialFromKeyBuildsCorrectDto(): void
    {
        $c = Credential::fromKey('POSTGRES_PASSWORD', 'hunter2');

        self::assertSame('password', $c->kind);
        self::assertSame('POSTGRES_PASSWORD', $c->label);
        self::assertSame('hunter2', $c->value);
        self::assertFalse($c->isPlaceholder);
    }

    public function testCredentialFromKeyFlagsPlaceholder(): void
    {
        $c = Credential::fromKey('POSTGRES_PASSWORD', '${DB_PASSWORD}');

        self::assertTrue($c->isPlaceholder);
        self::assertSame('${DB_PASSWORD}', $c->value);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter CredentialKeysTest`
Expected: FAIL with `Error: Class "Makeview\CredentialKeys" not found`

- [ ] **Step 3: Write `src/Value/Credential.php`**

```php
<?php

declare(strict_types=1);

namespace Makeview\Value;

use Makeview\CredentialKeys;

/** One credential extracted from a compose file or a README. */
final readonly class Credential
{
    /**
     * @param string $kind One of: user, password, token.
     * @param string $label Human-facing name, e.g. POSTGRES_PASSWORD or "heslo".
     * @param string $isPlaceholder True when the value is a substitution marker
     *                              or an instruction to the reader, not a secret.
     */
    public function __construct(
        public string $kind,
        public string $label,
        public string $value,
        public bool $isPlaceholder,
    ) {
    }

    /** Build from an environment-variable-style key, deriving kind from the key. */
    public static function fromKey(string $key, string $value): self
    {
        return new self(
            CredentialKeys::kindFor($key),
            $key,
            $value,
            CredentialKeys::isPlaceholder($value),
        );
    }
}
```

- [ ] **Step 4: Write `src/CredentialKeys.php`**

```php
<?php

declare(strict_types=1);

namespace Makeview;

/**
 * Shared credential vocabulary. Compose env keys and README definition lines
 * both run through here, so the two parsers cannot drift apart.
 */
final class CredentialKeys
{
    /** Longest value we will ever treat as a credential. */
    private const MAX_LENGTH = 200;

    /** Above this length, whitespace means it is a sentence rather than a secret. */
    private const MAX_LENGTH_WITH_SPACES = 40;

    private const USER_PATTERN = '/(^|_)(USER|USERNAME|LOGIN)($|_)/i';
    private const TOKEN_PATTERN = '/(^|_)(TOKEN|API_?KEY)($|_)/i';
    private const PASSWORD_PATTERN = '/(^|_)(PASSWORD|PASSWD|PASS|SECRET)($|_)/i';

    private const PLACEHOLDERS = [
        'changeme', 'change-me', 'change_me', 'todo', 'tbd', 'xxx', 'xxxx',
        'your-password', 'your_password', 'yourpassword', 'password',
        'your-token', 'your_token', 'secret', '***', '****', '...', '…', '-',
    ];

    public static function matches(string $key): bool
    {
        return self::isUser($key) || self::isToken($key) || self::isPassword($key);
    }

    /** Returns user, token, or password. Password wins over user when both match. */
    public static function kindFor(string $key): string
    {
        // USER_PASSWORD contains both; the secret nature dominates.
        if (self::isPassword($key)) {
            return 'password';
        }
        if (self::isToken($key)) {
            return 'token';
        }
        if (self::isUser($key)) {
            return 'user';
        }

        return 'password';
    }

    /** A substitution marker or an instruction to the reader — never a real secret. */
    public static function isPlaceholder(string $value): bool
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return true;
        }

        // ${VAR} and ${VAR:-default} are compose interpolation, left unresolved on purpose.
        if (preg_match('/^\$\{[^}]*\}$/', $trimmed) === 1) {
            return true;
        }

        // <your-password>, <TOKEN>
        if (preg_match('/^<[^>]*>$/', $trimmed) === 1) {
            return true;
        }

        return in_array(mb_strtolower($trimmed), self::PLACEHOLDERS, true);
    }

    /** Too long, or clearly prose rather than a credential. */
    public static function isNoise(string $value): bool
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return true;
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            return true;
        }

        return mb_strlen($trimmed) > self::MAX_LENGTH_WITH_SPACES
            && preg_match('/\s/', $trimmed) === 1;
    }

    private static function isUser(string $key): bool
    {
        return preg_match(self::USER_PATTERN, $key) === 1;
    }

    private static function isToken(string $key): bool
    {
        return preg_match(self::TOKEN_PATTERN, $key) === 1;
    }

    private static function isPassword(string $key): bool
    {
        return preg_match(self::PASSWORD_PATTERN, $key) === 1;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `composer test -- --filter CredentialKeysTest`
Expected: PASS, 11 tests.

If `testMatchesCommonCredentialKeys` fails on `DB_PASS`, check that `PASSWORD_PATTERN` places `PASS` after `PASSWORD` and `PASSWD` in the alternation — regex alternation is first-match, so a shorter alternative listed first would swallow the longer one.

- [ ] **Step 6: Commit**

```bash
git add src/Value/Credential.php src/CredentialKeys.php tests/CredentialKeysTest.php
git commit -m "feat: add credential value object and shared key vocabulary"
```

---

## Task 3: Service and Link value objects

**Files:**
- Create: `src/Value/Service.php`
- Create: `src/Value/Link.php`

**Interfaces:**
- Consumes: `Makeview\Value\Credential` from Task 2
- Produces:
  - `Makeview\Value\Service` — properties `string $name`, `?string $url`, `?string $urlSource` (`traefik` or `port`), `Credential[] $credentials`
  - `Makeview\Value\Link` — properties `string $label`, `?string $url`, `string $context`, `string $confidence` (`table`, `definition`, `env`, `proximity`), `Credential[] $credentials`; method `withCredentials(array $credentials): self` returning a new instance

These are plain data holders with no logic worth testing in isolation; Tasks 5 and 6 exercise them thoroughly. Writing tests that assert a constructor assigns its arguments would be busywork.

- [ ] **Step 1: Write `src/Value/Service.php`**

```php
<?php

declare(strict_types=1);

namespace Makeview\Value;

/** One service from a compose file. */
final readonly class Service
{
    /**
     * @param ?string $url Absolute http(s) URL, or null for a service with no
     *                     browser-reachable address (a database, for instance).
     * @param ?string $urlSource Where the URL came from: traefik or port. Null when $url is null.
     * @param Credential[] $credentials
     */
    public function __construct(
        public string $name,
        public ?string $url,
        public ?string $urlSource,
        public array $credentials,
    ) {
    }
}
```

- [ ] **Step 2: Write `src/Value/Link.php`**

```php
<?php

declare(strict_types=1);

namespace Makeview\Value;

/** One link (or credential group) extracted from a README. */
final readonly class Link
{
    /**
     * @param string $context The README heading this was found under; shown in the
     *                        UI so a wrong proximity pairing stays traceable.
     * @param string $confidence One of: table, definition, env, proximity.
     * @param Credential[] $credentials
     */
    public function __construct(
        public string $label,
        public ?string $url,
        public string $context,
        public string $confidence,
        public array $credentials,
    ) {
    }

    /** @param Credential[] $credentials */
    public function withCredentials(array $credentials): self
    {
        return new self($this->label, $this->url, $this->context, $this->confidence, $credentials);
    }
}
```

- [ ] **Step 3: Verify the classes load**

Run: `php -r 'require "vendor/autoload.php"; $s = new Makeview\Value\Service("web", "http://localhost:8080", "port", []); $l = new Makeview\Value\Link("Argo", null, "## Access", "proximity", []); echo $s->name . " " . $l->label . PHP_EOL;'`
Expected: `web Argo`

- [ ] **Step 4: Commit**

```bash
git add src/Value/Service.php src/Value/Link.php
git commit -m "feat: add service and link value objects"
```

---

## Task 4: Relocate Make parsing

**Files:**
- Create: `src/Make.php`
- Create: `tests/MakeTest.php`
- Modify: `index.php` (remove `parse_targets`, lines 31-53)

**Interfaces:**
- Consumes: Task 1 autoloader
- Produces: `Makeview\Make::parseTargets(string $contents): array` returning `['documented' => [['target' => string, 'desc' => string], ...], 'bare' => [string, ...]]`

The signature changes from the old `parse_targets(string $file)` — it now takes **contents, not a path**, matching the pure-function rule in Global Constraints. `index.php` becomes responsible for reading the file.

- [ ] **Step 1: Write the failing test**

Create `tests/MakeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Makeview\Tests;

use Makeview\Make;
use PHPUnit\Framework\TestCase;

final class MakeTest extends TestCase
{
    public function testExtractsDocumentedTargets(): void
    {
        $makefile = <<<'MAKE'
        build: ## Compile the thing
        test: ## Run the suite
        MAKE;

        $result = Make::parseTargets($makefile);

        self::assertSame(
            [
                ['target' => 'build', 'desc' => 'Compile the thing'],
                ['target' => 'test', 'desc' => 'Run the suite'],
            ],
            $result['documented'],
        );
    }

    public function testExtractsDocumentedTargetsWithDependencies(): void
    {
        $result = Make::parseTargets('deploy: build test ## Ship it');

        self::assertSame([['target' => 'deploy', 'desc' => 'Ship it']], $result['documented']);
    }

    public function testExtractsBareTargets(): void
    {
        $makefile = <<<'MAKE'
        build: ## Compile
        clean:
        	rm -rf dist
        install:
        MAKE;

        $result = Make::parseTargets($makefile);

        self::assertSame(['clean', 'install'], $result['bare']);
    }

    public function testBareListExcludesDocumentedTargets(): void
    {
        $makefile = <<<'MAKE'
        build: ## Compile
        build:
        MAKE;

        $result = Make::parseTargets($makefile);

        self::assertSame([], $result['bare']);
    }

    public function testIgnoresVariableAssignments(): void
    {
        $makefile = <<<'MAKE'
        CC := gcc
        FLAGS ?= -O2
        EXTRA += -g
        build:
        MAKE;

        $result = Make::parseTargets($makefile);

        self::assertSame(['build'], $result['bare']);
    }

    public function testIgnoresSpecialTargets(): void
    {
        $makefile = <<<'MAKE'
        .PHONY: build test
        .DEFAULT_GOAL := build
        build:
        MAKE;

        $result = Make::parseTargets($makefile);

        self::assertSame(['build'], $result['bare']);
    }

    public function testDeduplicatesRepeatedBareTargets(): void
    {
        $makefile = <<<'MAKE'
        build:
        build:
        MAKE;

        $result = Make::parseTargets($makefile);

        self::assertSame(['build'], $result['bare']);
    }

    public function testHandlesEmptyInput(): void
    {
        $result = Make::parseTargets('');

        self::assertSame(['documented' => [], 'bare' => []], $result);
    }

    public function testHandlesCrlfLineEndings(): void
    {
        $result = Make::parseTargets("build: ## Compile\r\ntest:\r\n");

        self::assertSame([['target' => 'build', 'desc' => 'Compile']], $result['documented']);
        self::assertSame(['test'], $result['bare']);
    }
}
```

`testHandlesCrlfLineEndings` is new behavior, not a pure relocation — the old code used `file()` with `FILE_IGNORE_NEW_LINES`, which leaves a trailing `\r` on CRLF files and would break the description capture. Splitting on `/\R/` fixes it.

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter MakeTest`
Expected: FAIL with `Error: Class "Makeview\Make" not found`

- [ ] **Step 3: Write `src/Make.php`**

```php
<?php

declare(strict_types=1);

namespace Makeview;

/** Makefile target extraction. */
final class Make
{
    /**
     * Parse make targets. Documented targets look like `target: ## description`.
     *
     * @return array{documented: list<array{target: string, desc: string}>, bare: list<string>}
     */
    public static function parseTargets(string $contents): array
    {
        $documented = [];
        $bare = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            // documented: name: [deps] ## description
            if (preg_match('/^([a-zA-Z0-9][a-zA-Z0-9_.\/-]*)\s*:[^=].*?##\s*(.+)$/', $line, $m) === 1) {
                $documented[] = ['target' => $m[1], 'desc' => trim($m[2])];
                continue;
            }

            // bare target: name: (not :=, ?=, +=; not .PHONY and friends)
            if (preg_match('/^([a-zA-Z0-9][a-zA-Z0-9_.\/-]*)\s*:([^=]|$)/', $line, $m) === 1
                && $m[1][0] !== '.'
            ) {
                $bare[$m[1]] = true; // key dedupes
            }
        }

        foreach ($documented as $d) {
            unset($bare[$d['target']]);
        }

        return ['documented' => $documented, 'bare' => array_keys($bare)];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter MakeTest`
Expected: PASS, 9 tests.

- [ ] **Step 5: Remove `parse_targets` from `index.php`**

Delete the whole block at `index.php:31-53` (the docblock plus the `parse_targets` function). Do not yet change the call sites — Task 7 rewrites `index.php` wholesale, and leaving the calls broken for one task is fine because nothing runs the page in between.

Add at the very top of `index.php`, immediately after the opening `<?php` and the existing header comment:

```php
require_once __DIR__ . '/vendor/autoload.php';

use Makeview\Make;
```

Then update the three existing call sites to read the file themselves:
- `index.php:316` — `parse_targets($p['makefile'])['documented']` becomes `Make::parseTargets((string)file_get_contents($p['makefile']))['documented']`
- `index.php:325` — same transformation
- `index.php:372` — `parse_targets($p['makefile'])` becomes `Make::parseTargets((string)file_get_contents($p['makefile']))`

- [ ] **Step 6: Verify the page still renders**

Run: `MAKEVIEW_DIR=$(dirname $(pwd)) php -S 127.0.0.1:8112 index.php > /tmp/makeview-check.log 2>&1 & sleep 2; curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8112/; kill %1`
Expected: `200`

If it prints `500`, read `/tmp/makeview-check.log` for the PHP fatal — the most likely cause is a missed `parse_targets` call site.

- [ ] **Step 7: Commit**

```bash
git add src/Make.php tests/MakeTest.php index.php
git commit -m "refactor: move make target parsing into Makeview\\Make"
```

---

## Task 5: Compose parser

**Files:**
- Create: `src/Compose.php`
- Create: `tests/ComposeTest.php`
- Create: `tests/fixtures/compose-traefik.yml`
- Create: `tests/fixtures/compose-base.yml`
- Create: `tests/fixtures/compose-override.yml`

**Interfaces:**
- Consumes: `Makeview\Value\Service`, `Makeview\Value\Credential`, `Makeview\CredentialKeys`
- Produces:
  - `Makeview\Compose::BASE_FILENAMES` — `list<string>` of base compose filenames in priority order
  - `Makeview\Compose::OVERRIDE_FILENAMES` — `list<string>` of override filenames in priority order
  - `Makeview\Compose::parse(string $baseYaml, string $overrideYaml = ''): array` returning `Service[]`. Throws nothing: on invalid YAML it throws `Symfony\Component\Yaml\Exception\ParseException`, which Task 7 catches.

- [ ] **Step 1: Write the fixtures**

`tests/fixtures/compose-base.yml`:

```yaml
services:
  web:
    image: nginx
    ports:
      - "8080:80"
    environment:
      APP_ENV: production
  db:
    image: postgres:16
    ports:
      - "5432:5432"
    environment:
      POSTGRES_USER: app
      POSTGRES_PASSWORD: prod-secret
      POSTGRES_DB: appdb
```

`tests/fixtures/compose-override.yml`:

```yaml
services:
  web:
    ports:
      - "3000:80"
    environment:
      - APP_ENV=development
      - APP_DEBUG=1
  db:
    environment:
      POSTGRES_PASSWORD: dev-secret
```

`tests/fixtures/compose-traefik.yml`:

```yaml
services:
  argocd:
    image: argoproj/argocd
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.argocd.rule=Host(`argo.localhost`)"
      - "traefik.http.routers.argocd.entrypoints=websecure"
      - "traefik.http.routers.argocd.tls=true"
    environment:
      ARGOCD_ADMIN_USER: admin
      ARGOCD_ADMIN_PASSWORD: argo-pass
  api:
    image: myapi
    ports:
      - "9000:9000"
    labels:
      traefik.http.routers.api.rule: "Host(`api.localhost`) && PathPrefix(`/v1`)"
      traefik.http.routers.api.entrypoints: "web"
```

Note `api` has **both** a Traefik host and a published port — it proves Traefik wins.

- [ ] **Step 2: Write the failing test**

Create `tests/ComposeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Makeview\Tests;

use Makeview\Compose;
use Makeview\Value\Service;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Exception\ParseException;

final class ComposeTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/' . $name);
    }

    /** @param Service[] $services */
    private function byName(array $services, string $name): Service
    {
        foreach ($services as $service) {
            if ($service->name === $name) {
                return $service;
            }
        }

        self::fail("Service {$name} not found");
    }

    // ---- URLs from ports ----

    public function testShortPortSyntaxProducesLocalhostUrl(): void
    {
        $services = Compose::parse("services:\n  web:\n    ports:\n      - \"8080:80\"\n");

        self::assertSame('http://localhost:8080', $this->byName($services, 'web')->url);
        self::assertSame('port', $this->byName($services, 'web')->urlSource);
    }

    public function testHostBoundPortSyntaxProducesLocalhostUrl(): void
    {
        $services = Compose::parse("services:\n  web:\n    ports:\n      - \"127.0.0.1:8111:8080\"\n");

        self::assertSame('http://localhost:8111', $this->byName($services, 'web')->url);
    }

    public function testLongPortSyntaxProducesLocalhostUrl(): void
    {
        $yaml = <<<'YAML'
        services:
          web:
            ports:
              - published: 8080
                target: 80
        YAML;

        $services = Compose::parse($yaml);

        self::assertSame('http://localhost:8080', $this->byName($services, 'web')->url);
    }

    public function testWellKnownDatabasePortProducesNoUrl(): void
    {
        $yaml = <<<'YAML'
        services:
          db:
            ports:
              - "5432:5432"
          cache:
            ports:
              - "6379:6379"
        YAML;

        $services = Compose::parse($yaml);

        self::assertNull($this->byName($services, 'db')->url);
        self::assertNull($this->byName($services, 'db')->urlSource);
        self::assertNull($this->byName($services, 'cache')->url);
    }

    // ---- URLs from Traefik ----

    public function testTraefikHostLabelProducesUrl(): void
    {
        $services = Compose::parse($this->fixture('compose-traefik.yml'));

        self::assertSame('https://argo.localhost', $this->byName($services, 'argocd')->url);
        self::assertSame('traefik', $this->byName($services, 'argocd')->urlSource);
    }

    public function testTraefikWinsOverPublishedPort(): void
    {
        $services = Compose::parse($this->fixture('compose-traefik.yml'));

        self::assertSame('http://api.localhost/v1', $this->byName($services, 'api')->url);
        self::assertSame('traefik', $this->byName($services, 'api')->urlSource);
    }

    public function testTlsLabelSelectsHttpsScheme(): void
    {
        $yaml = <<<'YAML'
        services:
          app:
            labels:
              - "traefik.http.routers.app.rule=Host(`app.localhost`)"
              - "traefik.http.routers.app.tls=true"
        YAML;

        $services = Compose::parse($yaml);

        self::assertSame('https://app.localhost', $this->byName($services, 'app')->url);
    }

    public function testWebsecureEntrypointSelectsHttpsScheme(): void
    {
        $yaml = <<<'YAML'
        services:
          app:
            labels:
              - "traefik.http.routers.app.rule=Host(`app.localhost`)"
              - "traefik.http.routers.app.entrypoints=websecure"
        YAML;

        self::assertSame('https://app.localhost', $this->byName(Compose::parse($yaml), 'app')->url);
    }

    public function testPlainEntrypointSelectsHttpScheme(): void
    {
        $yaml = <<<'YAML'
        services:
          app:
            labels:
              - "traefik.http.routers.app.rule=Host(`app.localhost`)"
              - "traefik.http.routers.app.entrypoints=web"
        YAML;

        self::assertSame('http://app.localhost', $this->byName(Compose::parse($yaml), 'app')->url);
    }

    public function testPathPrefixIsAppendedWhenRuleHasExactlyOneHost(): void
    {
        $yaml = <<<'YAML'
        services:
          app:
            labels:
              - "traefik.http.routers.app.rule=Host(`app.localhost`) && PathPrefix(`/admin`)"
        YAML;

        self::assertSame('http://app.localhost/admin', $this->byName(Compose::parse($yaml), 'app')->url);
    }

    public function testPathPrefixIsDroppedWhenRuleHasMultipleHosts(): void
    {
        $yaml = <<<'YAML'
        services:
          app:
            labels:
              - "traefik.http.routers.app.rule=Host(`a.localhost`) || Host(`b.localhost`) && PathPrefix(`/x`)"
        YAML;

        // Ambiguous which host the path belongs to; first host, no path.
        self::assertSame('http://a.localhost', $this->byName(Compose::parse($yaml), 'app')->url);
    }

    public function testLabelsAsMapAreSupported(): void
    {
        $yaml = <<<'YAML'
        services:
          app:
            labels:
              traefik.http.routers.app.rule: "Host(`app.localhost`)"
        YAML;

        self::assertSame('http://app.localhost', $this->byName(Compose::parse($yaml), 'app')->url);
    }

    // ---- credentials ----

    public function testExtractsCredentialsFromEnvironmentMap(): void
    {
        $services = Compose::parse($this->fixture('compose-base.yml'));
        $db = $this->byName($services, 'db');

        self::assertCount(2, $db->credentials);
        self::assertSame('user', $db->credentials[0]->kind);
        self::assertSame('app', $db->credentials[0]->value);
        self::assertSame('password', $db->credentials[1]->kind);
        self::assertSame('prod-secret', $db->credentials[1]->value);
    }

    public function testIgnoresNonCredentialEnvironmentKeys(): void
    {
        $services = Compose::parse($this->fixture('compose-base.yml'));

        $labels = array_map(fn ($c) => $c->label, $this->byName($services, 'db')->credentials);
        self::assertNotContains('POSTGRES_DB', $labels);
        self::assertSame([], $this->byName($services, 'web')->credentials);
    }

    public function testExtractsCredentialsFromEnvironmentList(): void
    {
        $yaml = <<<'YAML'
        services:
          app:
            environment:
              - DB_USER=admin
              - DB_PASSWORD=hunter2
              - NODE_ENV=production
        YAML;

        $credentials = $this->byName(Compose::parse($yaml), 'app')->credentials;

        self::assertCount(2, $credentials);
        self::assertSame('admin', $credentials[0]->value);
        self::assertSame('hunter2', $credentials[1]->value);
    }

    public function testInterpolationIsKeptVerbatimAndFlagged(): void
    {
        $yaml = <<<'YAML'
        services:
          app:
            environment:
              DB_PASSWORD: ${SECRET_PASSWORD}
        YAML;

        $credential = $this->byName(Compose::parse($yaml), 'app')->credentials[0];

        self::assertSame('${SECRET_PASSWORD}', $credential->value);
        self::assertTrue($credential->isPlaceholder);
    }

    public function testEnvironmentValueWithEqualsSignIsPreserved(): void
    {
        $yaml = <<<'YAML'
        services:
          app:
            environment:
              - DB_PASSWORD=abc=def=ghi
        YAML;

        self::assertSame('abc=def=ghi', $this->byName(Compose::parse($yaml), 'app')->credentials[0]->value);
    }

    // ---- merge ----

    public function testOverrideReplacesScalarValues(): void
    {
        $services = Compose::parse(
            $this->fixture('compose-base.yml'),
            $this->fixture('compose-override.yml'),
        );

        $credentials = $this->byName($services, 'db')->credentials;
        $password = array_values(array_filter($credentials, fn ($c) => $c->kind === 'password'))[0];

        self::assertSame('dev-secret', $password->value);
    }

    public function testOverrideReplacesPortsWholesale(): void
    {
        $services = Compose::parse(
            $this->fixture('compose-base.yml'),
            $this->fixture('compose-override.yml'),
        );

        self::assertSame('http://localhost:3000', $this->byName($services, 'web')->url);
    }

    public function testOverridePreservesKeysAbsentFromOverride(): void
    {
        $services = Compose::parse(
            $this->fixture('compose-base.yml'),
            $this->fixture('compose-override.yml'),
        );

        $labels = array_map(fn ($c) => $c->label, $this->byName($services, 'db')->credentials);
        self::assertContains('POSTGRES_USER', $labels);
    }

    public function testEnvironmentMergesAcrossDifferentSyntaxForms(): void
    {
        // base uses map form, override uses list form — normalization must happen before merge
        $base = "services:\n  app:\n    environment:\n      DB_USER: base-user\n      DB_PASSWORD: base-pass\n";
        $override = "services:\n  app:\n    environment:\n      - DB_PASSWORD=override-pass\n";

        $credentials = $this->byName(Compose::parse($base, $override), 'app')->credentials;
        $values = [];
        foreach ($credentials as $c) {
            $values[$c->label] = $c->value;
        }

        self::assertSame('base-user', $values['DB_USER']);
        self::assertSame('override-pass', $values['DB_PASSWORD']);
    }

    public function testOverrideCanIntroduceNewService(): void
    {
        $base = "services:\n  web:\n    ports:\n      - \"80:80\"\n";
        $override = "services:\n  mailhog:\n    ports:\n      - \"8025:8025\"\n";

        $services = Compose::parse($base, $override);

        self::assertCount(2, $services);
        self::assertSame('http://localhost:8025', $this->byName($services, 'mailhog')->url);
    }

    // ---- degenerate input ----

    public function testEmptyInputProducesNoServices(): void
    {
        self::assertSame([], Compose::parse(''));
    }

    public function testFileWithoutServicesKeyProducesNoServices(): void
    {
        self::assertSame([], Compose::parse("version: '3.8'\n"));
    }

    public function testServiceWithNothingUsefulIsOmitted(): void
    {
        // No URL and no credentials: nothing to show, so it should not appear.
        $services = Compose::parse("services:\n  worker:\n    image: busybox\n");

        self::assertSame([], $services);
    }

    public function testInvalidYamlThrowsParseException(): void
    {
        $this->expectException(ParseException::class);

        Compose::parse("services:\n  web:\n   - broken\n  \tindent: bad\n");
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `composer test -- --filter ComposeTest`
Expected: FAIL with `Error: Class "Makeview\Compose" not found`

- [ ] **Step 4: Write `src/Compose.php`**

```php
<?php

declare(strict_types=1);

namespace Makeview;

use Makeview\Value\Credential;
use Makeview\Value\Service;
use Symfony\Component\Yaml\Yaml;

/**
 * Compose file parsing: merge base with override, then derive a browser URL and
 * credentials per service. Reads no files — the caller supplies the contents.
 */
final class Compose
{
    /** Base compose filenames, priority order. First match wins. */
    public const BASE_FILENAMES = [
        'compose.yml',
        'compose.yaml',
        'docker-compose.yml',
        'docker-compose.yaml',
    ];

    /** Override filenames, priority order. First match wins. */
    public const OVERRIDE_FILENAMES = [
        'compose.override.yml',
        'compose.override.yaml',
        'docker-compose.override.yml',
        'docker-compose.override.yaml',
    ];

    /**
     * Target ports that are never browser-reachable. A service exposing only
     * these gets no URL, but still earns a row through its credentials.
     */
    private const NON_HTTP_PORTS = [5432, 3306, 6379, 27017, 9200, 5672, 11211, 1433, 25, 587];

    /** Entrypoint names that imply TLS. */
    private const SECURE_ENTRYPOINTS = ['secure', 'https', 'websecure'];

    /**
     * @return Service[]
     *
     * @throws \Symfony\Component\Yaml\Exception\ParseException on malformed YAML
     */
    public static function parse(string $baseYaml, string $overrideYaml = ''): array
    {
        $base = self::normalize(self::decode($baseYaml));
        $override = self::normalize(self::decode($overrideYaml));
        $merged = self::deepMerge($base, $override);

        $services = [];
        foreach ($merged['services'] ?? [] as $name => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $service = self::buildService((string) $name, $definition);
            if ($service !== null) {
                $services[] = $service;
            }
        }

        return $services;
    }

    /** @return array<string, mixed> */
    private static function decode(string $yaml): array
    {
        if (trim($yaml) === '') {
            return [];
        }

        $parsed = Yaml::parse($yaml);

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * Rewrite every service's `environment` into map form so that a base using
     * one syntax and an override using the other still merge correctly.
     *
     * @param array<string, mixed> $doc
     *
     * @return array<string, mixed>
     */
    private static function normalize(array $doc): array
    {
        if (!isset($doc['services']) || !is_array($doc['services'])) {
            return $doc;
        }

        foreach ($doc['services'] as $name => $definition) {
            if (!is_array($definition) || !isset($definition['environment'])) {
                continue;
            }

            $doc['services'][$name]['environment'] = self::environmentToMap($definition['environment']);
        }

        return $doc;
    }

    /**
     * @param mixed $environment Either a KEY: value map or a list of KEY=value strings.
     *
     * @return array<string, string>
     */
    private static function environmentToMap(mixed $environment): array
    {
        if (!is_array($environment)) {
            return [];
        }

        $map = [];
        foreach ($environment as $key => $value) {
            if (is_int($key)) {
                // list form: KEY=value, where value may itself contain '='
                if (!is_string($value) || !str_contains($value, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $value, 2);
                $map[trim($k)] = $v;
                continue;
            }

            // map form; a bare `KEY:` yields null, which means "inherit from host"
            $map[(string) $key] = $value === null ? '' : self::scalarToString($value);
        }

        return $map;
    }

    private static function scalarToString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Recursive merge where the override wins. Sequential arrays (ports, labels,
     * volumes) are replaced wholesale rather than concatenated — that is how
     * `docker compose` treats `ports`.
     *
     * @param array<mixed> $base
     * @param array<mixed> $override
     *
     * @return array<mixed>
     */
    private static function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value)
                && isset($base[$key])
                && is_array($base[$key])
                && !array_is_list($value)
            ) {
                $base[$key] = self::deepMerge($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /** @param array<string, mixed> $definition */
    private static function buildService(string $name, array $definition): ?Service
    {
        $credentials = self::extractCredentials($definition);
        [$url, $urlSource] = self::extractUrl($definition);

        // Nothing to show: no address, no credentials.
        if ($url === null && $credentials === []) {
            return null;
        }

        return new Service($name, $url, $urlSource, $credentials);
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return Credential[]
     */
    private static function extractCredentials(array $definition): array
    {
        $environment = self::environmentToMap($definition['environment'] ?? []);

        $credentials = [];
        foreach ($environment as $key => $value) {
            if (!CredentialKeys::matches($key)) {
                continue;
            }
            if (CredentialKeys::isNoise($value)) {
                continue;
            }

            $credentials[] = Credential::fromKey($key, $value);
        }

        return $credentials;
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array{0: ?string, 1: ?string} [url, source]
     */
    private static function extractUrl(array $definition): array
    {
        $traefik = self::traefikUrl(self::labelsToMap($definition['labels'] ?? []));
        if ($traefik !== null) {
            return [$traefik, 'traefik'];
        }

        $port = self::portUrl($definition['ports'] ?? []);
        if ($port !== null) {
            return [$port, 'port'];
        }

        return [null, null];
    }

    /**
     * @param mixed $labels Either a map or a list of `key=value` strings.
     *
     * @return array<string, string>
     */
    private static function labelsToMap(mixed $labels): array
    {
        if (!is_array($labels)) {
            return [];
        }

        $map = [];
        foreach ($labels as $key => $value) {
            if (is_int($key)) {
                if (!is_string($value) || !str_contains($value, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $value, 2);
                $map[trim($k)] = $v;
                continue;
            }

            $map[(string) $key] = self::scalarToString($value);
        }

        return $map;
    }

    /**
     * Build the address a developer would type into a browser, e.g.
     * https://argo.localhost — not localhost:port.
     *
     * @param array<string, string> $labels
     */
    private static function traefikUrl(array $labels): ?string
    {
        foreach ($labels as $key => $rule) {
            if (preg_match('/^traefik\.http\.routers\.([^.]+)\.rule$/', $key, $m) !== 1) {
                continue;
            }

            preg_match_all('/Host\(`([^`]+)`\)/', $rule, $hosts);
            if ($hosts[1] === []) {
                continue;
            }

            $router = $m[1];
            $scheme = self::traefikScheme($labels, $router) ;
            $url = $scheme . '://' . $hosts[1][0];

            // Only unambiguous single-host rules carry their path prefix.
            if (count($hosts[1]) === 1
                && preg_match('/PathPrefix\(`([^`]+)`\)/', $rule, $path) === 1
            ) {
                $url .= '/' . ltrim($path[1], '/');
            }

            return $url;
        }

        return null;
    }

    /** @param array<string, string> $labels */
    private static function traefikScheme(array $labels, string $router): string
    {
        if (isset($labels["traefik.http.routers.{$router}.tls"])) {
            return 'https';
        }

        $entrypoints = mb_strtolower($labels["traefik.http.routers.{$router}.entrypoints"] ?? '');
        foreach (self::SECURE_ENTRYPOINTS as $secure) {
            if (str_contains($entrypoints, $secure)) {
                return 'https';
            }
        }

        return 'http';
    }

    /** @param mixed $ports */
    private static function portUrl(mixed $ports): ?string
    {
        if (!is_array($ports)) {
            return null;
        }

        foreach ($ports as $entry) {
            [$published, $target] = self::splitPort($entry);
            if ($published === null) {
                continue;
            }
            if ($target !== null && in_array($target, self::NON_HTTP_PORTS, true)) {
                continue;
            }

            return 'http://localhost:' . $published;
        }

        return null;
    }

    /**
     * @return array{0: ?int, 1: ?int} [published, target]
     */
    private static function splitPort(mixed $entry): array
    {
        if (is_array($entry)) {
            $published = isset($entry['published']) ? (int) $entry['published'] : null;
            $target = isset($entry['target']) ? (int) $entry['target'] : null;

            return [$published ?: null, $target];
        }

        if (!is_string($entry) && !is_int($entry)) {
            return [null, null];
        }

        // Strip any protocol suffix: "8080:80/tcp"
        $value = explode('/', (string) $entry)[0];
        $parts = explode(':', $value);

        // "80"            -> container port only, no published port
        // "8080:80"       -> published:target
        // "127.0.0.1:8080:80" -> host:published:target
        return match (count($parts)) {
            2 => [(int) $parts[0] ?: null, (int) $parts[1]],
            3 => [(int) $parts[1] ?: null, (int) $parts[2]],
            default => [null, null],
        };
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `composer test -- --filter ComposeTest`
Expected: PASS, 24 tests.

If `testInvalidYamlThrowsParseException` fails because Symfony parses the fixture without complaining, replace the malformed YAML with a definitely-invalid document such as `"services:\n  web:\n    ports: [unclosed\n"`.

- [ ] **Step 6: Commit**

```bash
git add src/Compose.php tests/ComposeTest.php tests/fixtures/
git commit -m "feat: parse compose files into services with URLs and credentials"
```

---

## Task 6: README parser

**Files:**
- Create: `src/Readme.php`
- Create: `tests/ReadmeTest.php`
- Create: `tests/fixtures/readme-table.md`
- Create: `tests/fixtures/readme-loose.md`
- Create: `tests/fixtures/readme-prod-and-dev.md`

**Interfaces:**
- Consumes: `Makeview\Value\Link`, `Makeview\Value\Credential`, `Makeview\CredentialKeys`
- Produces: `Makeview\Readme::parse(string $markdown): array` returning `Link[]`

- [ ] **Step 1: Write the fixtures**

`tests/fixtures/readme-table.md`:

```markdown
# My Project

Some intro prose that mentions nothing useful.

## Přístupy

| Služba | URL | Uživatel | Heslo |
|---|---|---|---|
| Argo CD | https://argo.localhost | admin | argo-pass |
| Grafana | https://grafana.localhost | viewer | graf-pass |
```

`tests/fixtures/readme-loose.md`:

```markdown
# My Project

## Local development

Start it with `make up`, then open [the dashboard](http://localhost:3000).

Username: admin
Heslo: dev-secret

## Deployment

Run `make deploy`. See <https://docs.example.com> for details.
```

`tests/fixtures/readme-prod-and-dev.md`:

```markdown
# Pairing trap

## Development

[Dev Argo](https://argo.dev.localhost)

user: devadmin
heslo: dev-only

## Production

[Prod Argo](https://argo.example.com)

user: prodadmin
heslo: prod-only
```

- [ ] **Step 2: Write the failing test**

Create `tests/ReadmeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Makeview\Tests;

use Makeview\Readme;
use Makeview\Value\Link;
use PHPUnit\Framework\TestCase;

final class ReadmeTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/' . $name);
    }

    /** @param Link[] $links */
    private function byLabel(array $links, string $label): Link
    {
        foreach ($links as $link) {
            if ($link->label === $label) {
                return $link;
            }
        }

        self::fail("Link {$label} not found");
    }

    /** @param Link[] $links */
    private function credentialValues(array $links, string $label): array
    {
        $out = [];
        foreach ($this->byLabel($links, $label)->credentials as $credential) {
            $out[$credential->kind] = $credential->value;
        }

        return $out;
    }

    // ---- table extractor ----

    public function testTableRowsBecomeLinksWithCredentials(): void
    {
        $links = Readme::parse($this->fixture('readme-table.md'));

        $argo = $this->byLabel($links, 'Argo CD');
        self::assertSame('https://argo.localhost', $argo->url);
        self::assertSame('table', $argo->confidence);
        self::assertSame('Přístupy', $argo->context);
        self::assertSame(['user' => 'admin', 'password' => 'argo-pass'], $this->credentialValues($links, 'Argo CD'));
    }

    public function testEveryTableRowProducesItsOwnLink(): void
    {
        $links = Readme::parse($this->fixture('readme-table.md'));

        self::assertSame(['user' => 'viewer', 'password' => 'graf-pass'], $this->credentialValues($links, 'Grafana'));
    }

    public function testTableWithoutCredentialColumnsIsNotTreatedAsCredentialTable(): void
    {
        $markdown = <<<'MD'
        ## Options

        | Flag | Description |
        |---|---|
        | --verbose | Print more |
        MD;

        self::assertSame([], Readme::parse($markdown));
    }

    // ---- definition lines + proximity ----

    public function testDefinitionLinesPairWithPrecedingLink(): void
    {
        $links = Readme::parse($this->fixture('readme-loose.md'));

        $dashboard = $this->byLabel($links, 'the dashboard');
        self::assertSame('http://localhost:3000', $dashboard->url);
        self::assertSame(['user' => 'admin', 'password' => 'dev-secret'], $this->credentialValues($links, 'the dashboard'));
    }

    public function testCzechAndEnglishCredentialKeywordsBothWork(): void
    {
        $markdown = "## Access\n\n[App](https://app.localhost)\n\nuživatel: karel\npassword: tajne\n";

        $links = Readme::parse($markdown);

        self::assertSame(['user' => 'karel', 'password' => 'tajne'], $this->credentialValues($links, 'App'));
    }

    public function testCredentialsSurviveMarkdownEmphasisAndBackticks(): void
    {
        $markdown = "## Access\n\n[App](https://app.localhost)\n\n**Username:** `admin`\n*Heslo:* `p4ss`\n";

        self::assertSame(['user' => 'admin', 'password' => 'p4ss'], $this->credentialValues(Readme::parse($markdown), 'App'));
    }

    public function testEmDashSeparatorIsSupported(): void
    {
        $markdown = "## Access\n\n[App](https://app.localhost)\n\nUsername — admin\n";

        self::assertSame(['user' => 'admin'], $this->credentialValues(Readme::parse($markdown), 'App'));
    }

    // ---- the key test: no pairing across section boundaries ----

    public function testCredentialsNeverPairAcrossSectionBoundary(): void
    {
        $links = Readme::parse($this->fixture('readme-prod-and-dev.md'));

        self::assertSame(['user' => 'devadmin', 'password' => 'dev-only'], $this->credentialValues($links, 'Dev Argo'));
        self::assertSame(['user' => 'prodadmin', 'password' => 'prod-only'], $this->credentialValues($links, 'Prod Argo'));
    }

    public function testCredentialWithNoPrecedingLinkAttachesToSection(): void
    {
        $markdown = "## Přístupy\n\nuser: admin\nheslo: secret\n";

        $links = Readme::parse($markdown);

        self::assertCount(1, $links);
        self::assertNull($links[0]->url);
        self::assertSame('Přístupy', $links[0]->label);
        self::assertSame('Přístupy', $links[0]->context);
        self::assertSame(['user' => 'admin', 'password' => 'secret'], $this->credentialValues($links, 'Přístupy'));
    }

    public function testCredentialAttachesToNearestPrecedingLinkNotTheFirst(): void
    {
        $markdown = <<<'MD'
        ## Access

        [First](https://first.localhost)

        [Second](https://second.localhost)

        heslo: belongs-to-second
        MD;

        $links = Readme::parse($markdown);

        self::assertSame([], $this->byLabel($links, 'First')->credentials);
        self::assertSame(['password' => 'belongs-to-second'], $this->credentialValues($links, 'Second'));
    }

    // ---- env exports in fenced blocks ----

    public function testFencedEnvExportsBecomeCredentials(): void
    {
        $markdown = <<<'MD'
        ## Setup

        [Argo](https://argo.localhost)

        ```bash
        export ARGOCD_PASSWORD=from-fence
        export UNRELATED=nope
        ```
        MD;

        self::assertSame(['password' => 'from-fence'], $this->credentialValues(Readme::parse($markdown), 'Argo'));
    }

    public function testUrlsInsideFencedBlocksAreIgnored(): void
    {
        $markdown = <<<'MD'
        ## Setup

        ```bash
        curl https://example.com/install.sh | sh
        ```
        MD;

        self::assertSame([], Readme::parse($markdown));
    }

    // ---- link extraction ----

    public function testBareUrlUsesHostnameAsLabel(): void
    {
        $links = Readme::parse("## Docs\n\nSee https://docs.example.com for details.\n");

        self::assertSame('docs.example.com', $links[0]->label);
        self::assertSame('https://docs.example.com', $links[0]->url);
    }

    public function testAngleBracketedUrlIsExtracted(): void
    {
        $links = Readme::parse("## Docs\n\nSee <https://docs.example.com> for details.\n");

        self::assertSame('https://docs.example.com', $links[0]->url);
    }

    public function testNonHttpSchemesAreIgnored(): void
    {
        $markdown = "## Contact\n\n[Mail](mailto:a@b.cz) and [Bad](javascript:alert(1))\n";

        self::assertSame([], Readme::parse($markdown));
    }

    public function testDuplicateUrlsAreDeduplicated(): void
    {
        $markdown = "## Access\n\n[App](https://app.localhost)\n\n[App again](https://app.localhost)\n";

        self::assertCount(1, Readme::parse($markdown));
    }

    // ---- noise filters ----

    public function testPlaceholderCredentialsAreFlaggedNotDiscarded(): void
    {
        $markdown = "## Access\n\n[App](https://app.localhost)\n\nheslo: changeme\n";

        $credentials = $this->byLabel(Readme::parse($markdown), 'App')->credentials;

        self::assertCount(1, $credentials);
        self::assertTrue($credentials[0]->isPlaceholder);
    }

    public function testProseIsNotMistakenForACredential(): void
    {
        $markdown = "## Access\n\n[App](https://app.localhost)\n\nPassword: ask the team lead for the current shared value\n";

        self::assertSame([], $this->byLabel(Readme::parse($markdown), 'App')->credentials);
    }

    public function testOverlongValuesAreDiscarded(): void
    {
        $markdown = "## Access\n\n[App](https://app.localhost)\n\nheslo: " . str_repeat('a', 201) . "\n";

        self::assertSame([], $this->byLabel(Readme::parse($markdown), 'App')->credentials);
    }

    // ---- degenerate input ----

    public function testEmptyReadmeProducesNoLinks(): void
    {
        self::assertSame([], Readme::parse(''));
    }

    public function testReadmeWithoutHeadingsStillExtracts(): void
    {
        $links = Readme::parse("[App](https://app.localhost)\n\nheslo: secret\n");

        self::assertCount(1, $links);
        self::assertSame(['password' => 'secret'], $this->credentialValues($links, 'App'));
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `composer test -- --filter ReadmeTest`
Expected: FAIL with `Error: Class "Makeview\Readme" not found`

- [ ] **Step 4: Write `src/Readme.php`**

```php
<?php

declare(strict_types=1);

namespace Makeview;

use Makeview\Value\Credential;
use Makeview\Value\Link;

/**
 * README extraction: split into heading sections, run four extractors per
 * section, then pair credentials with the nearest preceding link. Pairing never
 * crosses a section boundary, which is what keeps production credentials off a
 * development link.
 */
final class Readme
{
    private const CREDENTIAL_WORDS =
        'user|uživatel|uzivatel|jméno|jmeno|login|username|heslo|password|pass|token|api\s?key';

    /** Credential kind by the Czech or English word that introduced it. */
    private const USER_WORDS = ['user', 'uživatel', 'uzivatel', 'jméno', 'jmeno', 'login', 'username'];
    private const TOKEN_WORDS = ['token', 'api key', 'apikey', 'api_key'];

    /** @return Link[] */
    public static function parse(string $markdown): array
    {
        $links = [];

        foreach (self::sections($markdown) as $section) {
            foreach (self::parseSection($section['heading'], $section['body']) as $link) {
                $links[] = $link;
            }
        }

        return self::deduplicate($links);
    }

    /**
     * Split on ATX headings. Content before the first heading gets an empty heading.
     *
     * @return list<array{heading: string, body: string}>
     */
    private static function sections(string $markdown): array
    {
        $lines = preg_split('/\R/', $markdown) ?: [];

        $sections = [];
        $heading = '';
        $body = [];
        $inFence = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
                $inFence = !$inFence;
            }

            // A heading inside a fence is shell output or a comment, not a section.
            if (!$inFence && preg_match('/^#{1,6}\s+(.+?)\s*#*\s*$/', $line, $m) === 1) {
                $sections[] = ['heading' => $heading, 'body' => implode("\n", $body)];
                $heading = trim(self::stripInlineMarkdown($m[1]));
                $body = [];
                continue;
            }

            $body[] = $line;
        }

        $sections[] = ['heading' => $heading, 'body' => implode("\n", $body)];

        return array_values(array_filter($sections, fn ($s) => trim($s['body']) !== '' || $s['heading'] !== ''));
    }

    /** @return Link[] */
    private static function parseSection(string $heading, string $body): array
    {
        $tableLinks = self::extractTable($body, $heading);
        if ($tableLinks !== []) {
            // A credential table is self-contained; proximity would only add noise.
            return $tableLinks;
        }

        return self::pairByProximity($heading, $body);
    }

    /**
     * A markdown table counts as a credential table only when its header has both
     * a URL-or-service column and a user-or-password column.
     *
     * @return Link[]
     */
    private static function extractTable(string $body, string $heading): array
    {
        $lines = preg_split('/\R/', $body) ?: [];
        $links = [];

        for ($i = 0; $i < count($lines) - 1; $i++) {
            if (!str_contains($lines[$i], '|') || preg_match('/^\s*\|?[\s:|-]+\|[\s:|-]*$/', $lines[$i + 1]) !== 1) {
                continue;
            }

            $header = self::tableCells($lines[$i]);
            $columns = self::classifyColumns($header);

            if (!isset($columns['label']) || (!isset($columns['user']) && !isset($columns['password']))) {
                continue;
            }

            for ($row = $i + 2; $row < count($lines); $row++) {
                if (!str_contains($lines[$row], '|')) {
                    break;
                }

                $link = self::tableRowToLink(self::tableCells($lines[$row]), $columns, $heading);
                if ($link !== null) {
                    $links[] = $link;
                }
            }

            $i = $row;
        }

        return $links;
    }

    /** @return list<string> */
    private static function tableCells(string $line): array
    {
        $trimmed = trim($line);
        $trimmed = preg_replace('/^\||\|$/', '', $trimmed) ?? $trimmed;

        return array_map(fn ($cell) => trim(self::stripInlineMarkdown($cell)), explode('|', $trimmed));
    }

    /**
     * @param list<string> $header
     *
     * @return array<string, int> role => column index
     */
    private static function classifyColumns(array $header): array
    {
        $columns = [];

        foreach ($header as $index => $cell) {
            $normalized = mb_strtolower($cell);

            if (!isset($columns['url']) && preg_match('/url|adresa|address|odkaz|link/', $normalized) === 1) {
                $columns['url'] = $index;
                continue;
            }
            if (!isset($columns['user']) && preg_match('/uživatel|uzivatel|user|login|jméno|jmeno/', $normalized) === 1) {
                $columns['user'] = $index;
                continue;
            }
            if (!isset($columns['password']) && preg_match('/heslo|password|pass|token|klíč|klic|key/', $normalized) === 1) {
                $columns['password'] = $index;
                continue;
            }
            if (!isset($columns['name']) && preg_match('/služba|sluzba|service|název|nazev|name|prostředí|prostredi|env/', $normalized) === 1) {
                $columns['name'] = $index;
            }
        }

        // The label column is the service name when present, otherwise the URL.
        if (isset($columns['name'])) {
            $columns['label'] = $columns['name'];
        } elseif (isset($columns['url'])) {
            $columns['label'] = $columns['url'];
        }

        return $columns;
    }

    /**
     * @param list<string>        $cells
     * @param array<string, int>  $columns
     */
    private static function tableRowToLink(array $cells, array $columns, string $heading): ?Link
    {
        $label = trim($cells[$columns['label']] ?? '');
        if ($label === '' || preg_match('/^[-: ]*$/', $label) === 1) {
            return null;
        }

        $url = null;
        if (isset($columns['url'])) {
            $url = self::firstUrlIn($cells[$columns['url']] ?? '');
            if ($columns['label'] === $columns['url'] && $url !== null) {
                $label = self::hostOf($url);
            }
        }

        $credentials = [];
        foreach (['user' => 'user', 'password' => 'password'] as $role => $kind) {
            if (!isset($columns[$role])) {
                continue;
            }

            $value = trim($cells[$columns[$role]] ?? '');
            if ($value === '' || CredentialKeys::isNoise($value)) {
                continue;
            }

            $credentials[] = new Credential($kind, $role === 'user' ? 'uživatel' : 'heslo', $value, CredentialKeys::isPlaceholder($value));
        }

        if ($url === null && $credentials === []) {
            return null;
        }

        return new Link($label, $url, $heading, 'table', $credentials);
    }

    /**
     * Walk the section line by line. Links open a new pairing target; credentials
     * attach to the most recent one. With no link yet, they attach to the section.
     *
     * @return Link[]
     */
    private static function pairByProximity(string $heading, string $body): array
    {
        $lines = preg_split('/\R/', $body) ?: [];

        /** @var list<array{link: ?Link, credentials: list<Credential>}> $groups */
        $groups = [];
        $current = null;
        $inFence = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
                $inFence = !$inFence;
                continue;
            }

            if (!$inFence) {
                foreach (self::linksIn($line, $heading) as $link) {
                    $groups[] = ['link' => $link, 'credentials' => []];
                    $current = count($groups) - 1;
                }
            }

            $credential = $inFence ? self::envCredentialIn($line) : self::definitionCredentialIn($line);
            if ($credential === null) {
                continue;
            }

            if ($current === null) {
                $groups[] = ['link' => null, 'credentials' => []];
                $current = count($groups) - 1;
            }

            $groups[$current]['credentials'][] = $credential;
        }

        $links = [];
        foreach ($groups as $group) {
            $link = $group['link'];

            if ($link === null) {
                if ($group['credentials'] === [] || $heading === '') {
                    continue;
                }
                $links[] = new Link($heading, null, $heading, 'proximity', $group['credentials']);
                continue;
            }

            $links[] = $group['credentials'] === [] ? $link : $link->withCredentials($group['credentials']);
        }

        return $links;
    }

    /** @return Link[] */
    private static function linksIn(string $line, string $heading): array
    {
        $links = [];
        $consumed = $line;

        // [label](url)
        if (preg_match_all('/\[([^\]]*)\]\(\s*([^)\s]+)[^)]*\)/', $line, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $url = self::normalizeUrl($match[2]);
                if ($url === null) {
                    continue;
                }

                $label = trim(self::stripInlineMarkdown($match[1]));
                $links[] = new Link($label !== '' ? $label : self::hostOf($url), $url, $heading, 'proximity', []);
                $consumed = str_replace($match[0], '', $consumed);
            }
        }

        // <https://…> and bare URLs, minus anything already taken by a markdown link
        if (preg_match_all('/<?(https?:\/\/[^\s<>()\[\]"\']+)>?/', $consumed, $bare) !== false) {
            foreach ($bare[1] as $candidate) {
                $url = self::normalizeUrl(rtrim($candidate, '.,;:'));
                if ($url === null) {
                    continue;
                }

                $links[] = new Link(self::hostOf($url), $url, $heading, 'proximity', []);
            }
        }

        return $links;
    }

    /** `user: admin`, `**Heslo:** `x``, `Username — admin` */
    private static function definitionCredentialIn(string $line): ?Credential
    {
        $clean = self::stripInlineMarkdown($line);

        $pattern = '/^\s*[-*+]?\s*(' . self::CREDENTIAL_WORDS . ')\s*[:=—–-]\s*(.+?)\s*$/iu';
        if (preg_match($pattern, $clean, $m) !== 1) {
            return null;
        }

        $value = trim($m[2]);
        if (CredentialKeys::isNoise($value)) {
            return null;
        }

        $word = mb_strtolower(trim($m[1]));

        return new Credential(
            self::kindForWord($word),
            $word,
            $value,
            CredentialKeys::isPlaceholder($value),
        );
    }

    /** `export ARGOCD_PASSWORD=x` or `ARGOCD_PASSWORD=x` inside a fenced block */
    private static function envCredentialIn(string $line): ?Credential
    {
        if (preg_match('/^\s*(?:export\s+)?([A-Z][A-Z0-9_]*)=(.+?)\s*$/', trim($line), $m) !== 1) {
            return null;
        }

        if (!CredentialKeys::matches($m[1])) {
            return null;
        }

        $value = trim($m[2], " \t\"'");
        if (CredentialKeys::isNoise($value)) {
            return null;
        }

        return Credential::fromKey($m[1], $value);
    }

    private static function kindForWord(string $word): string
    {
        if (in_array($word, self::USER_WORDS, true)) {
            return 'user';
        }
        if (in_array($word, self::TOKEN_WORDS, true)) {
            return 'token';
        }

        return 'password';
    }

    private static function firstUrlIn(string $text): ?string
    {
        if (preg_match('/\[([^\]]*)\]\(\s*([^)\s]+)[^)]*\)/', $text, $m) === 1) {
            return self::normalizeUrl($m[2]);
        }

        if (preg_match('/<?(https?:\/\/[^\s<>()\[\]"\']+)>?/', $text, $m) === 1) {
            return self::normalizeUrl(rtrim($m[1], '.,;:'));
        }

        return null;
    }

    /** Only http and https survive; anything else (javascript:, mailto:) is dropped. */
    private static function normalizeUrl(string $candidate): ?string
    {
        $url = trim($candidate, " \t<>\"'");
        if (preg_match('/^https?:\/\/[^\s]+$/i', $url) !== 1) {
            return null;
        }

        return $url;
    }

    private static function hostOf(string $url): string
    {
        return parse_url($url, PHP_URL_HOST) ?: $url;
    }

    private static function stripInlineMarkdown(string $text): string
    {
        return trim((string) preg_replace('/[`*_]/', '', $text));
    }

    /**
     * Keep the first occurrence of each URL. Credential-only entries (no URL) are
     * keyed by context so two sections can each contribute one.
     *
     * @param Link[] $links
     *
     * @return Link[]
     */
    private static function deduplicate(array $links): array
    {
        $seen = [];
        $out = [];

        foreach ($links as $link) {
            $key = ($link->url ?? '') . '|' . ($link->url === null ? $link->context : '');
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $link;
        }

        return $out;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `composer test -- --filter ReadmeTest`
Expected: PASS, 21 tests.

Two failures are likely on the first run and both are worth understanding rather than patching around:

- If `testCredentialAttachesToNearestPrecedingLinkNotTheFirst` fails, `$current` is not advancing when a second link appears. Check that `linksIn` returns the link and that `$current` is reassigned inside the loop over its results.
- If `testFencedEnvExportsBecomeCredentials` fails with zero credentials, the fence toggle in `pairByProximity` is consuming the export lines. The `continue` after toggling must only skip the fence marker line itself, not the lines inside.

- [ ] **Step 6: Run the whole suite**

Run: `composer test`
Expected: PASS, 65 tests total (11 + 9 + 24 + 21).

- [ ] **Step 7: Commit**

```bash
git add src/Readme.php tests/ReadmeTest.php tests/fixtures/
git commit -m "feat: extract links and credentials from README files"
```

---

## Task 7: Project module and view integration

**Files:**
- Create: `src/Project.php`
- Modify: `index.php` (routing, two new sections, credential widget CSS and JS)

**Interfaces:**
- Consumes: everything from Tasks 2-6
- Produces:
  - `Makeview\Project::scan(string $root): array` — the relocated `scan_projects`
  - `Makeview\Project::meta(string $dir): array` — the relocated `project_meta`
  - `Makeview\Project::firstExisting(string $dir, array $names): ?string`
  - `Makeview\Project::services(string $dir): array` — reads compose files, returns `Service[]`, returns `[]` on failure
  - `Makeview\Project::composeFailed(string $dir): bool` — true when a compose file exists but could not be parsed
  - `Makeview\Project::readmeLinks(string $readmePath): array` — returns `Link[]`

`Project` is the one module allowed to touch the filesystem. It is deliberately untested by unit tests — it is thin glue over already-tested parsers, and testing it would mean building a fixture directory tree for very little return. Step 8's smoke test covers it end to end.

- [ ] **Step 1: Write `src/Project.php`**

```php
<?php

declare(strict_types=1);

namespace Makeview;

use Makeview\Value\Link;
use Makeview\Value\Service;
use Symfony\Component\Yaml\Exception\ParseException;

/** Filesystem access. Every read of a project directory happens here. */
final class Project
{
    private const MAKEFILE_NAMES = ['Makefile', 'makefile', 'GNUmakefile'];
    private const README_NAMES = ['README.md', 'readme.md', 'Readme.md'];

    /**
     * Scan a root directory for project subdirectories holding a Makefile or README.
     *
     * @return array<string, array{name: string, makefile: ?string, readme: ?string}>
     */
    public static function scan(string $root): array
    {
        $out = [];

        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            $makefile = self::firstExisting($dir, self::MAKEFILE_NAMES);
            $readme = self::firstExisting($dir, self::README_NAMES);

            if ($makefile !== null || $readme !== null) {
                $out[$name] = ['name' => $name, 'makefile' => $makefile, 'readme' => $readme];
            }
        }

        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }

    /** @param list<string> $names */
    public static function firstExisting(string $dir, array $names): ?string
    {
        foreach ($names as $name) {
            if (is_file("{$dir}/{$name}")) {
                return "{$dir}/{$name}";
            }
        }

        return null;
    }

    /**
     * Cheap metadata straight off the filesystem — no git binary involved.
     *
     * @return array{branch: ?string, mtime: int, stack: list<string>}
     */
    public static function meta(string $dir): array
    {
        $branch = null;
        if (is_file("{$dir}/.git/HEAD")) {
            $head = trim((string) file_get_contents("{$dir}/.git/HEAD"));
            $branch = str_starts_with($head, 'ref: refs/heads/')
                ? substr($head, 16)
                : substr($head, 0, 7);
        }

        // .git/index mtime approximates the last add/commit/checkout; dir mtime as fallback.
        $mtime = is_file("{$dir}/.git/index") ? filemtime("{$dir}/.git/index") : filemtime($dir);

        $stack = [];
        if (is_file("{$dir}/composer.json")) {
            $stack[] = 'PHP';
        }
        if (is_file("{$dir}/package.json")) {
            $stack[] = 'JS';
        }
        if (self::composeFile($dir) !== null || is_file("{$dir}/Dockerfile")) {
            $stack[] = 'Docker';
        }

        return ['branch' => $branch, 'mtime' => (int) $mtime, 'stack' => $stack];
    }

    /**
     * Services from the project's compose files. A malformed or unreadable file
     * yields an empty list rather than an exception — one broken project must not
     * take down the page.
     *
     * @return Service[]
     */
    public static function services(string $dir): array
    {
        $base = self::composeFile($dir);
        if ($base === null) {
            return [];
        }

        $override = self::firstExisting($dir, Compose::OVERRIDE_FILENAMES);

        try {
            return Compose::parse(
                (string) @file_get_contents($base),
                $override !== null ? (string) @file_get_contents($override) : '',
            );
        } catch (ParseException) {
            return [];
        }
    }

    /** True when a compose file is present but could not be parsed. */
    public static function composeFailed(string $dir): bool
    {
        $base = self::composeFile($dir);
        if ($base === null) {
            return false;
        }

        $contents = @file_get_contents($base);
        if ($contents === false) {
            return true;
        }

        $override = self::firstExisting($dir, Compose::OVERRIDE_FILENAMES);

        try {
            Compose::parse($contents, $override !== null ? (string) @file_get_contents($override) : '');

            return false;
        } catch (ParseException) {
            return true;
        }
    }

    /** @return Link[] */
    public static function readmeLinks(string $readmePath): array
    {
        $contents = @file_get_contents($readmePath);

        return $contents === false ? [] : Readme::parse($contents);
    }

    private static function composeFile(string $dir): ?string
    {
        return self::firstExisting($dir, Compose::BASE_FILENAMES);
    }
}
```

The `@` suppression on `file_get_contents` is the one deliberate exception to the no-suppression rule: the failure is immediately converted into a visible UI state via `composeFailed()`, so nothing is actually silenced.

- [ ] **Step 2: Replace the PHP header of `index.php`**

Replace everything from the opening `<?php` through the `h()` function definition (originally `index.php:1-97`, now shorter after Task 4) with:

```php
<?php

declare(strict_types=1);

// Makeview – line viewer of make targets, services and README per project.
// Mount projects read-only at /projects.

require_once __DIR__ . '/vendor/autoload.php';

use Makeview\Make;
use Makeview\Project;
use Makeview\Value\Credential;

define('ROOT', rtrim(getenv('MAKEVIEW_DIR') ?: '/projects', '/'));

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Czech relative time: "před 5 min", "včera", "před 3 dny"… */
function rel_time(int $t): string
{
    $d = time() - $t;
    if ($d < 3600) {
        return 'před ' . max(1, intdiv($d, 60)) . ' min';
    }
    if ($d < 86400) {
        return 'před ' . intdiv($d, 3600) . ' h';
    }
    $days = intdiv($d, 86400);
    if ($days === 1) {
        return 'včera';
    }
    if ($days < 31) {
        return "před $days dny";
    }
    if ($days < 365) {
        return 'před ' . intdiv($days, 30) . ' měs.';
    }

    return 'před ' . intdiv($days, 365) . ' r.';
}

/** First real paragraph of a README as plain text, for the featured exhibit. */
function readme_excerpt(string $file, int $max = 220): ?string
{
    foreach (preg_split('/\R{2,}/', (string) file_get_contents($file)) ?: [] as $block) {
        $block = trim($block);
        // skip headings, code fences, tables, images, html
        if ($block === '' || preg_match('/^[#`|!<\[-]/', $block)) {
            continue;
        }
        $text = trim(preg_replace(['/\[([^\]]*)\]\([^)]*\)/', '/[`*_>]/'], ['$1', ''], $block));
        $text = preg_replace('/\s+/', ' ', $text);
        if (mb_strlen($text) < 20) {
            continue;
        }

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }

    return null;
}

/** Render one credential as a copy-and-reveal widget. */
function credential_widget(Credential $c): string
{
    $label = h($c->label);

    if ($c->isPlaceholder) {
        return '<span class="cred cred-ph" title="' . $label . '">' . h($c->value) . '</span>';
    }

    if ($c->kind === 'user') {
        return '<button type="button" class="cred cmd" data-cmd="' . h($c->value) . '"'
            . ' data-tip="Kopírovat ' . $label . '">' . h($c->value) . '</button>';
    }

    $encoded = h(base64_encode($c->value));

    return '<span class="cred cred-secret">'
        . '<span class="cred-mask" data-val="' . $encoded . '">•••••••</span>'
        . '<button type="button" class="cred-eye" aria-label="Zobrazit ' . $label . '" title="Zobrazit">👁</button>'
        . '<button type="button" class="cred-copy" data-val="' . $encoded . '" aria-label="Kopírovat ' . $label . '" title="Kopírovat">⧉</button>'
        . '</span>';
}

/** Render a URL as a link, or as plain text when the scheme is not http(s). */
function url_link(string $url): string
{
    if (preg_match('/^https?:\/\//i', $url) !== 1) {
        return '<span class="url">' . h($url) . '</span>';
    }

    return '<a class="url" href="' . h($url) . '" target="_blank" rel="noopener noreferrer">' . h($url) . '</a>';
}

// ---- routing ----
$projects = Project::scan(ROOT);
$sel = $_GET['p'] ?? null;
// whitelist blocks traversal; is_string blocks ?p[]=
if (!is_string($sel) || !isset($projects[$sel])) {
    $sel = null;
}
?>
```

- [ ] **Step 3: Update the remaining `index.php` call sites**

Three replacements in the body:

- `project_meta(ROOT . '/' . $name)` → `Project::meta(ROOT . '/' . $name)` (two occurrences)
- `Make::parseTargets((string)file_get_contents($p['makefile']))` — already correct from Task 4, leave as is

The home page keeps parsing compose for the featured project only. In the `$sel === null` branch, after `$featExcerpt` is computed, add:

```php
  $featServices = $fp !== null && isset($fp) ? Project::services(ROOT . '/' . $featName) : [];
```

Actually the cleaner form, replacing the whole featured block:

```php
  $featName = array_key_first($cards);
  $featTargets = [];
  $featExcerpt = null;
  $featServices = [];
  if ($featName !== null) {
      $fp = $projects[$featName];
      if ($fp['makefile']) {
          $featTargets = array_slice(Make::parseTargets((string) file_get_contents($fp['makefile']))['documented'], 0, 3);
      }
      if ($fp['readme']) {
          $featExcerpt = readme_excerpt($fp['readme']);
      }
      $featServices = Project::services(ROOT . '/' . $featName);
  }
```

Then inside the `.featured` section, after the `$featTargets` block, add the featured project's first service URL as a quick-open link:

```php
      <?php if ($featServices): $firstUrl = null; foreach ($featServices as $s) { if ($s->url !== null) { $firstUrl = $s->url; break; } } ?>
        <?php if ($firstUrl !== null): ?>
          <p class="fmeta"><?= url_link($firstUrl) ?></p>
        <?php endif; ?>
      <?php endif; ?>
```

The catalog loop is left completely unchanged — no service count, per the spec's Performance section.

- [ ] **Step 4: Add the two new sections to the detail branch**

In the `else` branch (project detail), immediately **before** the `<?php if ($p['readme']): ?>` README block, insert:

```php
  <?php
    $services = Project::services(ROOT . '/' . $sel);
    $composeFailed = $services === [] && Project::composeFailed(ROOT . '/' . $sel);
  ?>
  <?php if ($composeFailed): ?>
    <h3 class="sect">Služby</h3>
    <p class="empty">compose.yml se nepodařilo načíst.</p>
  <?php elseif ($services): ?>
    <h3 class="sect">Služby</h3>
    <table class="cmds">
      <?php foreach ($services as $s): ?>
        <tr>
          <td class="svc-name"><?= h($s->name) ?></td>
          <td class="svc-url">
            <?php if ($s->url !== null): ?>
              <?= url_link($s->url) ?>
              <span class="badge"><?= $s->urlSource === 'traefik' ? 'traefik' : 'localhost' ?></span>
            <?php else: ?>
              <span class="hint">—</span>
            <?php endif; ?>
          </td>
          <td class="svc-creds">
            <?php foreach ($s->credentials as $c): ?><?= credential_widget($c) ?><?php endforeach; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <?php $links = $p['readme'] ? Project::readmeLinks($p['readme']) : []; ?>
  <?php if ($links): ?>
    <h3 class="sect">Odkazy z README</h3>
    <div class="links">
      <?php foreach ($links as $l): ?>
        <div class="lrow">
          <div class="lhead">
            <span class="llabel"><?= h($l->label) ?></span>
            <?php if ($l->context !== ''): ?><span class="lctx"><?= h($l->context) ?></span><?php endif; ?>
          </div>
          <div class="lbody">
            <?php if ($l->url !== null): ?><?= url_link($l->url) ?><?php endif; ?>
            <?php foreach ($l->credentials as $c): ?><?= credential_widget($c) ?><?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
```

- [ ] **Step 5: Add the CSS**

Insert into the `<style>` block, immediately before the `/* README: prose in sans for readability */` comment:

```css
  /* services + readme links */
  .svc-name { font-weight:600; }
  .svc-url { white-space:nowrap; }
  .svc-creds { text-align:right; white-space:nowrap; }
  .url { color:var(--accent); text-decoration:none;
         text-underline-offset:3px; text-decoration-line:underline;
         text-decoration-color:color-mix(in oklab, var(--accent) 35%, transparent); }
  .url:hover { text-decoration-color:var(--accent); }

  .cred { display:inline-flex; align-items:center; gap:4px; margin-left:10px;
          font-size:13px; vertical-align:middle; }
  .cred.cmd { margin-left:10px; }
  .cred-ph { color:var(--muted); font-style:italic; }
  .cred-secret { border:1px solid var(--line); border-radius:4px; padding:1px 4px 1px 8px;
                 background:var(--panel); }
  .cred-mask { font-family:inherit; letter-spacing:.08em; color:var(--muted); }
  .cred-mask.shown { color:var(--ink); letter-spacing:0; }
  .cred-eye, .cred-copy { appearance:none; background:none; border:none; cursor:pointer;
                          padding:2px 3px; font-size:11px; line-height:1; opacity:.5;
                          color:inherit; transition:opacity .12s; }
  .cred-eye:hover, .cred-copy:hover { opacity:1; }
  .cred-copy.copied { opacity:1; color:var(--accent); }

  .links { border-top:1px solid var(--line); }
  .lrow { padding:11px 10px; border-bottom:1px solid var(--hairline); }
  .lhead { display:flex; align-items:baseline; gap:12px; }
  .llabel { font-size:15px; font-weight:600; letter-spacing:-.01em; }
  .lctx { flex:1; text-align:right; color:var(--muted); font-size:12px;
          overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .lbody { margin-top:4px; display:flex; flex-wrap:wrap; align-items:center; gap:2px 4px; }
  .lbody .cred:first-child { margin-left:0; }

  @media (max-width:720px) {
    .svc-creds { text-align:left; white-space:normal; }
    .cred-eye, .cred-copy { padding:8px 6px; font-size:13px; }
    .lctx { display:none; }
  }
```

- [ ] **Step 6: Add the JavaScript**

Insert into the `<script>` block, immediately after the existing copy-on-click handler:

```javascript
// Credential reveal + copy. Values are base64 in data-val so they don't shout
// from view-source; this is a screen-sharing convenience, not a secret store.
document.addEventListener('click', e => {
  const eye = e.target.closest('.cred-eye');
  if (eye) {
    const mask = eye.parentElement.querySelector('.cred-mask');
    const shown = mask.classList.toggle('shown');
    mask.textContent = shown ? atob(mask.dataset.val) : '•••••••';
    eye.setAttribute('aria-label', (shown ? 'Skrýt' : 'Zobrazit'));
    return;
  }

  const copy = e.target.closest('.cred-copy');
  if (!copy) return;
  navigator.clipboard?.writeText(atob(copy.dataset.val));
  copy.classList.add('copied');
  setTimeout(() => copy.classList.remove('copied'), 1000);
});

// Re-mask everything when focus leaves the page — avoids a revealed password
// lingering on screen after switching away.
window.addEventListener('blur', () => {
  document.querySelectorAll('.cred-mask.shown').forEach(m => {
    m.classList.remove('shown');
    m.textContent = '•••••••';
  });
});
```

`textContent` is used exclusively — never `innerHTML` — so a credential containing `<` cannot inject markup.

- [ ] **Step 7: Verify the page renders and shows the new sections**

Run:
```bash
MAKEVIEW_DIR=$(dirname $(pwd)) php -S 127.0.0.1:8112 index.php > /tmp/makeview-check.log 2>&1 &
sleep 2
curl -s -o /dev/null -w "home %{http_code}\n" 'http://127.0.0.1:8112/'
curl -s 'http://127.0.0.1:8112/?p=MakeView' | grep -c 'class="sect"'
kill %1
```
Expected: `home 200`, then a count of at least `2` (Make příkazy + README; MakeView's own compose.yml has a port so Služby should appear too, making it 3).

If the count is `0`, read `/tmp/makeview-check.log`.

- [ ] **Step 8: Verify escaping holds against a hostile README**

Run:
```bash
mkdir -p /tmp/mv-xss/evil
printf '## Access\n\n[x](javascript:alert(1))\n\nheslo: <img src=x onerror=alert(1)>\n' > /tmp/mv-xss/evil/README.md
MAKEVIEW_DIR=/tmp/mv-xss php -S 127.0.0.1:8113 index.php > /tmp/makeview-xss.log 2>&1 &
sleep 2
curl -s 'http://127.0.0.1:8113/?p=evil' | grep -c 'onerror=alert'
curl -s 'http://127.0.0.1:8113/?p=evil' | grep -c 'href="javascript:'
kill %1
rm -rf /tmp/mv-xss
```
Expected: both counts are `0`. The `javascript:` URL must not have been rendered as a link at all, and the credential value must appear only base64-encoded inside `data-val`.

- [ ] **Step 9: Run the full suite once more**

Run: `composer test`
Expected: PASS, 65 tests.

- [ ] **Step 10: Commit**

```bash
git add src/Project.php index.php
git commit -m "feat: show services and README links in the project view"
```

---

## Task 8: Docker build and documentation

**Files:**
- Modify: `Dockerfile`
- Modify: `README.md`

**Interfaces:**
- Consumes: everything
- Produces: a working container image; user-facing documentation of the two new sections

- [ ] **Step 1: Rewrite `Dockerfile`**

```dockerfile
FROM composer:2 AS deps
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

FROM php:8.3-cli-alpine
WORKDIR /app
COPY --from=deps /app/vendor ./vendor
COPY index.php ./
COPY src ./src

EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "index.php"]
```

The two-stage build keeps composer out of the runtime image. `composer.lock` is copied so the install is reproducible — this is why Task 1 kept it out of `.gitignore`.

- [ ] **Step 2: Build the image**

Run: `docker build -t makeview-test .`
Expected: build completes, final line `naming to docker.io/library/makeview-test`.

- [ ] **Step 3: Run the container against the parent directory**

Run:
```bash
docker run --rm -d --name makeview-test -p 127.0.0.1:8114:8080 -v "$(dirname $(pwd)):/projects:ro" makeview-test
sleep 3
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8114/
docker rm -f makeview-test
```
Expected: `200`

- [ ] **Step 4: Update `README.md`**

Replace the intro paragraph and the `## Install` section. The intro becomes:

```markdown
# Makeview

A PHP dashboard for your local projects. It scans a directory of projects,
parses their **Makefile targets** (documented `target: ## desc` first), pulls
**services and URLs** out of `compose.yml` (including Traefik hostnames), finds
**links and credentials** in READMEs, shows **git branch + last activity** (read
straight from `.git` files, no git binary needed), and renders the READMEs
themselves. Click any command, URL, or credential to copy it.

> UI text is in Czech. No database, no framework, no build step — one PHP file,
> a handful of small modules, and two Composer packages.
```

Replace both install snippets:

````markdown
### Docker (recommended)

```bash
git clone https://github.com/Resetnak/MakeView && cd MakeView
PERSONAL_DIR=~/projects docker compose up -d --build
# → http://localhost:8111
```

### Local PHP (no Docker)

Needs PHP 8.2+ and Composer.

```bash
composer install --no-dev
MAKEVIEW_DIR=~/projects php -S 127.0.0.1:8111 index.php
# → http://localhost:8111
```
````

Replace the "Any PHP host" section:

```markdown
### Any PHP host

Drop `index.php`, `src/`, and `vendor/` into any PHP 8.2+ webroot and set the
`MAKEVIEW_DIR` env var. Note the app is meant for **your own machine**: it
happily displays everything it finds, including credentials written in your
READMEs, so don't point it at private projects on a public server.
```

Extend "How it works" with:

```markdown
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
```

Add a Development section before `## License`:

````markdown
## Development

```bash
composer install
composer test              # PHPUnit
composer test:coverage     # with a coverage summary
```

Parsers live in `src/` and take file **contents**, not paths — all filesystem
access is in `src/Project.php`. That is what keeps `tests/` free of fixtures on
disk beyond `tests/fixtures/`.
````

- [ ] **Step 5: Verify the documented commands actually work**

Run: `composer test && composer test:coverage 2>&1 | tail -5`
Expected: tests pass; the coverage summary prints. If coverage tooling is missing, PHPUnit prints a notice about no code coverage driver — that is acceptable and does not fail the build.

- [ ] **Step 6: Commit**

```bash
git add Dockerfile README.md
git commit -m "docs: document services and README links; build vendor in Docker"
```

---

## Self-Review Notes

Checked against the spec section by section.

**Coverage.** Every spec section maps to a task: Architecture → Tasks 1, 3, 4, 7; Compose parsing → Task 5; README parsing → Task 6; UI → Task 7; Error handling → Task 7 (`Project::services` / `composeFailed`); Security → Task 7 Steps 5-8 plus the `url_link` and `credential_widget` helpers; Performance → Task 7 Step 3 (featured-only parsing, catalog untouched); Testing → Tasks 2, 4, 5, 6; Documentation → Task 8.

**Two deviations from the spec, both deliberate:**

1. The spec listed `src/Make.php` as holding `parse_targets` "relocated verbatim". Task 4 changes the signature to take contents rather than a path, because the Global Constraints require parsers to do no I/O. The behavior is otherwise identical, and `MakeTest` pins it. The CRLF fix is a genuine behavior change, called out in Task 4 Step 1.

2. The spec's Services table sketch showed credentials for `postgres` with no URL. Task 5 `testServiceWithNothingUsefulIsOmitted` additionally drops services that have *neither* — otherwise every `image: busybox` worker would occupy a row saying nothing.

**Type consistency.** `Service` uses `urlSource`, and Task 7's view reads `$s->urlSource` — matching. `Link::withCredentials` is defined in Task 3 and called in Task 6's `pairByProximity` — matching. `Credential::fromKey` is defined in Task 2, used in Tasks 5 and 6 — matching. `Compose::BASE_FILENAMES` / `OVERRIDE_FILENAMES` are defined in Task 5 and consumed by `Project` in Task 7 — matching.

**Test count.** 11 + 9 + 24 + 21 = 65, asserted in Task 6 Step 6 and Task 7 Step 9.
