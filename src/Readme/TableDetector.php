<?php

declare(strict_types=1);

namespace Makeview\Readme;

use Makeview\CredentialKeys;
use Makeview\CredentialPhrases;
use Makeview\Readme as ReadmeParser;
use Makeview\Value\Credential;
use Makeview\Value\Link;

/**
 * Markdown table handling for README parsing. A table counts as a credential
 * table only when its header has both a URL-or-service column and a
 * user-or-password column, or when it is a two-column "Field | Value" table
 * describing a single account.
 */
final class TableDetector
{
    /**
     * A markdown table counts as a credential table only when its header has both
     * a URL-or-service column and a user-or-password column.
     *
     * @return Link[]
     */
    public static function extractTable(string $body, string $heading): array
    {
        $lines = preg_split('/\R/', $body) ?: [];
        $links = [];

        for ($i = 0; $i < count($lines) - 1; $i++) {
            if (!str_contains($lines[$i], '|') || preg_match('/^\s*\|?[\s:|-]+\|[\s:|-]*$/', $lines[$i + 1]) !== 1) {
                continue;
            }

            $header = self::tableCells($lines[$i]);
            $columns = self::classifyColumns($header);

            if (isset($columns['variable'], $columns['value'])) {
                $env = self::envTableLink($lines, $i, $columns, $heading);
                if ($env !== null) {
                    $links[] = $env;
                }

                continue;
            }

            if (!isset($columns['label']) || (!isset($columns['user']) && ($columns['secrets'] ?? []) === [])) {
                // The header named no role, but a two-column table often puts
                // the roles in the first column instead: `| Field | Value |`
                // over rows `Email` and `Password`. Read it sideways before
                // giving up on it.
                $transposed = self::transposedTableLink($lines, $i, $heading);
                if ($transposed !== null) {
                    $links[] = $transposed;
                }

                continue;
            }

            for ($row = $i + 2; $row < count($lines); $row++) {
                if (!str_contains($lines[$row], '|')) {
                    break;
                }

                $link = self::tableRowToLink(self::tableCells($lines[$row]), $columns, $heading, $header);
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
        return array_map(fn ($cell) => trim(ReadmeParser::stripInlineMarkdown($cell)), self::rawTableCells($line));
    }

    /** The same split, with the markup left on. @return list<string> */
    private static function rawTableCells(string $line): array
    {
        $trimmed = trim($line);
        $trimmed = preg_replace('/^\||\|$/', '', $trimmed) ?? $trimmed;

        return explode('|', $trimmed);
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

            // Tested before the roles below: a header cell reading "Variable"
            // or "Env" would otherwise be claimed as a service name, and "Key"
            // as a password, turning a configuration table into an account.
            if (!isset($columns['variable']) && preg_match('/^(proměnná|promenna|variable|env|env\s*var|klíč|klic|key|nastavení|nastaveni|setting|option)$/u', $normalized) === 1) {
                $columns['variable'] = $index;
                continue;
            }
            if (!isset($columns['value']) && preg_match('/^(hodnota|value|výchozí|vychozi|default|výchozí\s+hodnota|vychozi\s+hodnota|default\s+value|příklad|priklad|example)$/u', $normalized) === 1) {
                $columns['value'] = $index;
                continue;
            }

            if (!isset($columns['url']) && preg_match('/url|adresa|address|odkaz|link/', $normalized) === 1) {
                $columns['url'] = $index;
                continue;
            }
            if (!isset($columns['user']) && preg_match('/uživatel|uzivatel|user|login|jméno|jmeno|e-?mail|účet|ucet|account/', $normalized) === 1) {
                $columns['user'] = $index;
                continue;
            }
            // Every secret column counts, not just the first. A row reading
            // `| Access Key | Secret Key |` states two halves of one account,
            // and claiming a single `password` slot dropped whichever came
            // second — the dashboard showed an access key with no secret.
            if (preg_match('/heslo|password|passwd|pass|token|klíč|klic|key|secret/', $normalized) === 1) {
                $columns['secrets'][] = $index;
                continue;
            }
            if (!isset($columns['name']) && preg_match('/služba|sluzba|service|název|nazev|name|prostředí|prostredi|env|portál|portal|aplikace|app/', $normalized) === 1) {
                $columns['name'] = $index;
            }
        }

        // The label column is the service name when present, then the URL. A
        // credential table often has neither — `| Email | Heslo | Role |` names
        // no service at all — and there the account itself is the only thing
        // that identifies the row, so it becomes the label. Without this the
        // whole table used to be discarded for want of a label column.
        if (isset($columns['name'])) {
            $columns['label'] = $columns['name'];
        } elseif (isset($columns['url'])) {
            $columns['label'] = $columns['url'];
        } elseif (isset($columns['user'])) {
            $columns['label'] = $columns['user'];
        }

        return $columns;
    }

    /**
     * A configuration table: one column names an environment variable, another
     * gives its value. The names are the same ones compose files use, so
     * CredentialKeys decides which rows state a credential and which merely
     * document a port.
     *
     * A value column is required. `| Proměnná | Popis |` describes what a
     * variable means without ever saying what it is set to, and reading its
     * prose as a secret is how a documentation table becomes a wall of
     * nonsense.
     *
     * @param list<string>       $lines
     * @param array<string, int> $columns
     */
    private static function envTableLink(array $lines, int $headerIndex, array $columns, string $heading): ?Link
    {
        if ($heading === '') {
            return null;
        }

        $credentials = [];

        for ($i = $headerIndex + 2; $i < count($lines); $i++) {
            if (!str_contains($lines[$i], '|')) {
                break;
            }

            $cells = self::tableCells($lines[$i]);
            $value = trim($cells[$columns['value']] ?? '');

            // Variable names are read with their underscores intact: the shared
            // vocabulary matches on `DB_PASSWORD`, and the inline-markdown strip
            // that serves every other column would leave it as `DBPASSWORD`.
            $key = trim(str_replace('`', '', self::rawTableCells($lines[$i])[$columns['variable']] ?? ''));

            if ($key === '' || $value === '' || !CredentialKeys::matches($key)) {
                continue;
            }

            if (CredentialKeys::isNoise($value)) {
                continue;
            }

            $credentials[] = Credential::fromKey($key, $value);
        }

        return $credentials === [] ? null : new Link($heading, null, $heading, 'table', $credentials);
    }

    /**
     * Read a table whose roles run down the first column rather than across the
     * header — `| Field | Value |` over rows `Email` / `Password`, the shape a
     * README uses to describe one single test account.
     *
     * Only two-column tables qualify. A wider one lists many things and its
     * header is the description of them; reading it sideways would pair a role
     * with whichever column happened to come second.
     *
     * @param list<string> $lines
     */
    private static function transposedTableLink(array $lines, int $headerIndex, string $heading): ?Link
    {
        if (count(self::tableCells($lines[$headerIndex])) !== 2) {
            return null;
        }

        $credentials = [];

        for ($i = $headerIndex + 2; $i < count($lines); $i++) {
            if (!str_contains($lines[$i], '|')) {
                break;
            }

            $cells = self::tableCells($lines[$i]);
            if (count($cells) !== 2) {
                break;
            }

            [$role, $value] = $cells;
            $value = trim($value);

            if ($value === '' || CredentialKeys::isNoise($value)) {
                continue;
            }

            // The role cell must be the word itself, not a sentence mentioning
            // it: a `| Proměnná | Popis |` table documents variables, and its
            // descriptions are prose, not secrets.
            if (preg_match('/^\s*(' . CredentialPhrases::WORD_PATTERN . ')\s*$/iu', $role) !== 1) {
                continue;
            }

            $kind = CredentialPhrases::kindFor($role);
            $evidence = ['introduced' => true, 'structured' => true];

            $credentials[] = new Credential(
                $kind,
                $kind === 'user' ? 'uživatel' : ($kind === 'token' ? 'token' : 'heslo'),
                $value,
                CredentialKeys::isPlaceholder($value),
                Scorer::score($value, $evidence),
                Scorer::explain($value, $evidence),
            );
        }

        // One lone value is not an account; it is a row that happened to be
        // named "password". Require a pair before believing the table.
        if (count($credentials) < 2 || $heading === '') {
            return null;
        }

        return new Link($heading, null, $heading, 'table', $credentials);
    }

    /**
     * @param list<string>                    $cells
     * @param array<string, int|list<int>>    $columns
     * @param list<string>                    $headers Header cells, so a secret
     *                                                 column can be labelled and
     *                                                 typed by the name the author gave it.
     */
    private static function tableRowToLink(array $cells, array $columns, string $heading, array $headers = []): ?Link
    {
        $label = trim($cells[$columns['label']] ?? '');
        if ($label === '' || preg_match('/^[-: ]*$/', $label) === 1) {
            return null;
        }

        $url = null;
        if (isset($columns['url'])) {
            $url = self::firstUrlIn($cells[$columns['url']] ?? '');
            if ($columns['label'] === $columns['url'] && $url !== null) {
                $label = ReadmeParser::hostOf($url);
            }
        }

        $credentials = [];

        // A table states its roles in the header: the strongest form of
        // evidence there is, structured and introduced at once.
        $evidence = ['introduced' => true, 'structured' => true];

        if (isset($columns['user'])) {
            $value = trim($cells[$columns['user']] ?? '');
            if ($value !== '' && !CredentialKeys::isNoise($value)) {
                $credentials[] = new Credential(
                    'user',
                    'uživatel',
                    $value,
                    CredentialKeys::isPlaceholder($value),
                    Scorer::score($value, $evidence),
                    Scorer::explain($value, $evidence),
                );
            }
        }

        // The header names each secret, so it decides the kind and the label:
        // an "Access Key" column holds tokens, a "Heslo" column holds
        // passwords, and a row may well carry both.
        foreach ($columns['secrets'] ?? [] as $index) {
            $value = trim($cells[$index] ?? '');
            if ($value === '' || CredentialKeys::isNoise($value)) {
                continue;
            }

            $header = trim($headers[$index] ?? '');
            $kind = $header !== '' ? CredentialPhrases::kindFor($header) : 'password';
            if ($kind === 'user') {
                // A header the user pattern also claims ("Login key") must not
                // turn a secret column into a username.
                $kind = 'password';
            }

            $credentials[] = new Credential(
                $kind,
                $header !== '' ? $header : ($kind === 'token' ? 'token' : 'heslo'),
                $value,
                CredentialKeys::isPlaceholder($value),
                Scorer::score($value, $evidence),
                Scorer::explain($value, $evidence),
            );
        }

        if ($url === null && $credentials === []) {
            return null;
        }

        return new Link($label, $url, $heading, 'table', $credentials);
    }

    private static function firstUrlIn(string $text): ?string
    {
        if (preg_match('/\[([^\]]*)\]\(\s*([^)\s]+)[^)]*\)/', $text, $m) === 1) {
            return ReadmeParser::normalizeUrl($m[2]);
        }

        if (preg_match('/<?(https?:\/\/[^\s<>()\[\]"\']+)>?/', $text, $m) === 1) {
            return ReadmeParser::normalizeUrl(rtrim($m[1], '.,;:'));
        }

        return null;
    }
}
