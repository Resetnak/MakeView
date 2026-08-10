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
        // When a service defines multiple routers, the first one whose label
        // key is encountered (map iteration / list order after
        // labelsToMap()) wins; there is no priority or weight comparison.
        // This mirrors YAML declaration order, not Traefik's own routing
        // precedence — acceptable here since this only picks a URL to
        // display, not an actual routing decision.
        foreach ($labels as $key => $rule) {
            if (preg_match('/^traefik\.http\.routers\.([^.]+)\.rule$/', $key, $m) !== 1) {
                continue;
            }

            // Any `Host(...)` occurrence at all — including ones whose contents
            // fail the strict charset below — makes this rule host-based. If it
            // fails the strict match, we must yield no URL rather than fall
            // through to a different, unrelated URL source for this router.
            if (preg_match_all('/Host\(`([^`]*)`\)/', $rule, $anyHosts) === 0) {
                continue;
            }

            // Restrict captured hostnames to legal hostname characters only.
            // Rejects userinfo (`@`), markup/quote injection, and anything else
            // that isn't a valid DNS label so no attacker-controlled substring
            // ever reaches a rendered `<a href>`.
            preg_match_all('/Host\(`([A-Za-z0-9.\-]+)`\)/', $rule, $hosts);
            if ($hosts[1] === [] || count($hosts[1]) !== count($anyHosts[1])) {
                return null;
            }

            $router = $m[1];
            $scheme = self::traefikScheme($labels, $router);
            $url = $scheme . '://' . $hosts[1][0];

            // Only unambiguous single-host rules carry their path prefix.
            if (count($hosts[1]) === 1
                && preg_match('/PathPrefix\(`([A-Za-z0-9._\-\/]+)`\)/', $rule, $path) === 1
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
        $tls = mb_strtolower($labels["traefik.http.routers.{$router}.tls"] ?? '');
        if ($tls !== '' && $tls !== 'false') {
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

            // A well-known database/queue/mail port disqualifies the URL.
            // When no target is given (e.g. long syntax with only
            // "published" set), judge the published port itself.
            $portToCheck = $target ?? $published;
            if (in_array($portToCheck, self::NON_HTTP_PORTS, true)) {
                continue;
            }

            return 'http://localhost:' . $published;
        }

        return null;
    }

    /** Valid TCP/UDP port range. */
    private const MIN_PORT = 1;
    private const MAX_PORT = 65535;

    /**
     * @return array{0: ?int, 1: ?int} [published, target]
     */
    private static function splitPort(mixed $entry): array
    {
        if (is_array($entry)) {
            $published = isset($entry['published']) ? self::toPort($entry['published']) : null;
            $target = isset($entry['target']) ? self::toPort($entry['target']) : null;

            return [$published, $target];
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
            2 => [self::toPort($parts[0]), self::toPort($parts[1])],
            3 => [self::toPort($parts[1]), self::toPort($parts[2])],
            default => [null, null],
        };
    }

    /**
     * Parse a strictly numeric, in-range port. Rejects range syntax
     * (e.g. "8000-8005"), negative numbers, and anything above 65535 —
     * none of those describe a single browser-reachable localhost URL.
     */
    private static function toPort(mixed $value): ?int
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }

        $port = (int) $value;

        return $port >= self::MIN_PORT && $port <= self::MAX_PORT ? $port : null;
    }
}
