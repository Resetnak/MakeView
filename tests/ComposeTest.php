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
        // Each service carries a credential so it still earns a row; the point
        // under test is that the database port yields no URL, not omission.
        $yaml = <<<'YAML'
        services:
          db:
            ports:
              - "5432:5432"
            environment:
              POSTGRES_PASSWORD: secret
          cache:
            ports:
              - "6379:6379"
            environment:
              REDIS_PASSWORD: secret
        YAML;

        $services = Compose::parse($yaml);

        self::assertNull($this->byName($services, 'db')->url);
        self::assertNull($this->byName($services, 'db')->urlSource);
        self::assertNull($this->byName($services, 'cache')->url);
    }

    public function testNegativePublishedPortProducesNoUrl(): void
    {
        $yaml = <<<'YAML'
        services:
          app:
            ports:
              - "-1:80"
            environment:
              APP_SECRET: secret
        YAML;

        self::assertNull($this->byName(Compose::parse($yaml), 'app')->url);
    }

    public function testOutOfRangePublishedPortProducesNoUrl(): void
    {
        $yaml = <<<'YAML'
        services:
          app:
            ports:
              - "99999:80"
            environment:
              APP_SECRET: secret
        YAML;

        self::assertNull($this->byName(Compose::parse($yaml), 'app')->url);
    }

    public function testPortRangeSyntaxProducesNoUrl(): void
    {
        $yaml = <<<'YAML'
        services:
          app:
            ports:
              - "8000-8005:8000-8005"
            environment:
              APP_SECRET: secret
        YAML;

        self::assertNull($this->byName(Compose::parse($yaml), 'app')->url);
    }

    public function testNonHttpTargetPortWithoutExplicitTargetIsRecognizedFromPublished(): void
    {
        // Long syntax with only "published" set (no "target") should still be
        // treated as a database port when the published port itself is well-known.
        $yaml = <<<'YAML'
        services:
          db:
            ports:
              - published: 5432
            environment:
              POSTGRES_PASSWORD: secret
        YAML;

        self::assertNull($this->byName(Compose::parse($yaml), 'db')->url);
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

    public function testTlsLabelSetToFalseDoesNotSelectHttpsScheme(): void
    {
        $yaml = <<<'YAML'
        services:
          app:
            labels:
              - "traefik.http.routers.app.rule=Host(`app.localhost`)"
              - "traefik.http.routers.app.tls=false"
        YAML;

        $services = Compose::parse($yaml);

        self::assertSame('http://app.localhost', $this->byName($services, 'app')->url);
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

    public function testHostLabelRejectsInjectedScriptCharacters(): void
    {
        // Carries a credential so it still earns a row despite having no URL;
        // the point under test is that the malicious rule yields no URL.
        $yaml = <<<'YAML'
        services:
          app:
            labels:
              - "traefik.http.routers.app.rule=Host(`a\"><script>alert(1)</script>`)"
            environment:
              APP_SECRET: secret
        YAML;

        self::assertNull($this->byName(Compose::parse($yaml), 'app')->url);
        self::assertNull($this->byName(Compose::parse($yaml), 'app')->urlSource);
    }

    public function testHostLabelRejectsUserinfoPhishingHost(): void
    {
        $yaml = <<<'YAML'
        services:
          app:
            labels:
              - "traefik.http.routers.app.rule=Host(`good.localhost@evil.example.com`)"
            environment:
              APP_SECRET: secret
        YAML;

        self::assertNull($this->byName(Compose::parse($yaml), 'app')->url);
    }

    public function testPathPrefixLabelRejectsInjectedAttributeCharacters(): void
    {
        $yaml = <<<'YAML'
        services:
          app:
            labels:
              - "traefik.http.routers.app.rule=Host(`ok.localhost`) && PathPrefix(`/x\" onmouseover=alert(1) `)"
        YAML;

        self::assertSame('http://ok.localhost', $this->byName(Compose::parse($yaml), 'app')->url);
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
