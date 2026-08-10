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
     * @param list<string>       $cells
     * @param array<string, int> $columns
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
