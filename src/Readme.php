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
        'user(?:name)?|uživatel(?:ské\s+jméno)?|uzivatel(?:ske\s+jmeno)?|přihlašovací\s+jméno|prihlasovaci\s+jmeno'
        . '|jméno|jmeno|login|e-?mail|účet|ucet|account'
        . '|heslo|password|passwd|pass|token|api[\s_-]?key|secret|klíč|klic';

    /** Credential kind by the Czech or English word that introduced it. */
    private const USER_WORDS = [
        'user', 'username', 'uživatel', 'uzivatel', 'uživatelské jméno', 'uzivatelske jmeno',
        'přihlašovací jméno', 'prihlasovaci jmeno', 'jméno', 'jmeno', 'login',
        'email', 'e-mail', 'účet', 'ucet', 'account',
    ];
    private const TOKEN_WORDS = ['token', 'api key', 'apikey', 'api_key', 'api-key', 'klíč', 'klic'];

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
        $fence = self::fenceMask($lines);

        $sections = [];
        $heading = '';
        $body = [];

        foreach ($lines as $i => $line) {
            $isHeading = preg_match('/^#{1,6}\s+(.+?)\s*#*\s*$/', $line, $m) === 1;

            // Lines inside a matched (properly opened-and-closed) fence never open a
            // new section, no matter what they look like: a shell `#` comment inside
            // a ```sh block must not be read as a heading. A fence marker line itself
            // is also never a heading boundary. Only an *unterminated* fence — one
            // that never finds its matching closer — stops suppressing content, so a
            // credential in a later section (e.g. Production) can't silently merge
            // into an earlier one (e.g. Development) just because someone forgot a
            // closing fence.
            if (!$fence['insideClosedFence'][$i] && !$fence['isMarkerLine'][$i] && $isHeading) {
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

    /**
     * Two-pass fence classification shared by sections() and pairByProximity() so
     * both agree on what "inside a fence" means. A fence opener (``` or ~~~) is
     * only "closed" by a same-or-longer marker of the *same* character, per
     * CommonMark; pass one finds those matched pairs, pass two turns them into a
     * per-line mask. An opener with no matching closer is left unmatched: it must
     * not suppress detection for the rest of the document (see sections() above),
     * so every line after an unterminated opener is reported as outside any fence.
     *
     * @param list<string> $lines
     * @return array{insideClosedFence: list<bool>, isMarkerLine: list<bool>}
     */
    private static function fenceMask(array $lines): array
    {
        $count = count($lines);
        $insideClosedFence = array_fill(0, $count, false);
        $isMarkerLine = array_fill(0, $count, false);

        $openChar = null;
        $openLength = 0;
        $openIndex = null;

        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(`{3,}|~{3,})/', $line, $m) !== 1) {
                continue;
            }

            $char = $m[1][0];
            $length = strlen($m[1]);

            if ($openIndex === null) {
                // Opening a new fence.
                $openChar = $char;
                $openLength = $length;
                $openIndex = $i;
                $isMarkerLine[$i] = true;
                continue;
            }

            if ($char === $openChar && $length >= $openLength) {
                // Matching closer: mark every line strictly between opener and
                // closer as inside a closed fence, then reset for the next fence.
                $isMarkerLine[$i] = true;
                for ($j = $openIndex + 1; $j < $i; $j++) {
                    $insideClosedFence[$j] = true;
                }
                $openChar = null;
                $openLength = 0;
                $openIndex = null;
                continue;
            }

            // A ``` or ~~~ line of the wrong type/length while already inside a
            // fence is just fence content (e.g. a nested example), not a marker.
        }

        // An opener with no matching closer never suppresses anything: leave the
        // rest of the document, including the opener line itself, unmasked.
        if ($openIndex !== null) {
            $isMarkerLine[$openIndex] = false;
        }

        return ['insideClosedFence' => $insideClosedFence, 'isMarkerLine' => $isMarkerLine];
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

            if (isset($columns['variable'], $columns['value'])) {
                $env = self::envTableLink($lines, $i, $columns, $heading);
                if ($env !== null) {
                    $links[] = $env;
                }

                continue;
            }

            if (!isset($columns['label']) || (!isset($columns['user']) && !isset($columns['password']))) {
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
        return array_map(fn ($cell) => trim(self::stripInlineMarkdown($cell)), self::rawTableCells($line));
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
            if (!isset($columns['password']) && preg_match('/heslo|password|passwd|pass|token|klíč|klic|key|secret/', $normalized) === 1) {
                $columns['password'] = $index;
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
            $credentials[] = new Credential(
                $kind,
                $kind === 'user' ? 'uživatel' : ($kind === 'token' ? 'token' : 'heslo'),
                $value,
                CredentialKeys::isPlaceholder($value),
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
        $fence = self::fenceMask($lines);

        /** @var list<array{link: ?Link, credentials: list<Credential>, sources: list<string>}> $groups */
        $groups = [];
        $current = null;

        foreach ($lines as $i => $line) {
            // Fence classification comes from the same two-pass fenceMask() that
            // sections() uses, so both agree on what's "inside a fence" — a naive
            // per-line toggle here previously desynced from sections() whenever a
            // fence contained a line that looked like a heading.
            $inFence = $fence['insideClosedFence'][$i];

            if ($fence['isMarkerLine'][$i]) {
                continue;
            }

            // Credential search target: normally the whole line, but if the line
            // also carries a link (e.g. `[App](url) heslo: secret`), search the
            // residue with the link syntax stripped out instead. Otherwise the
            // credential pattern's anchor never matches past the markdown link,
            // and a same-line secret would go unreported.
            $credentialSource = $line;
            $foundLinkOnThisLine = false;

            if (!$inFence) {
                $extracted = self::linksAndResidueIn($line, $heading);
                foreach ($extracted['links'] as $link) {
                    $groups[] = ['link' => $link, 'credentials' => [], 'sources' => []];
                    $current = count($groups) - 1;
                    $foundLinkOnThisLine = true;
                }

                if ($foundLinkOnThisLine) {
                    $credentialSource = $extracted['residue'];
                }
            }

            $credentials = $inFence
                ? self::fencedCredentialsIn($line)
                : self::definitionCredentialsIn($credentialSource);

            if ($credentials === []) {
                continue;
            }

            if ($current === null) {
                $groups[] = ['link' => null, 'credentials' => [], 'sources' => []];
                $current = count($groups) - 1;
            }

            foreach ($credentials as $credential) {
                $groups[$current]['credentials'][] = $credential;
                $groups[$current]['sources'][] = $inFence ? 'env' : 'definition';
            }
        }

        $links = [];
        foreach ($groups as $group) {
            $link = $group['link'];
            $confidence = self::confidenceFor($group['sources']);

            if ($link === null) {
                if ($group['credentials'] === [] || $heading === '') {
                    continue;
                }
                $links[] = new Link($heading, null, $heading, $confidence, $group['credentials']);
                continue;
            }

            if ($group['credentials'] === []) {
                $links[] = $link;
                continue;
            }

            // A link may already carry credentials of its own — basic-auth
            // taken out of its URL. Those are the strongest evidence there is
            // (they were literally part of the address), so they are kept
            // alongside whatever the surrounding lines contributed.
            $links[] = new Link(
                $link->label,
                $link->url,
                $link->context,
                $confidence,
                [...$link->credentials, ...$group['credentials']],
            );
        }

        return $links;
    }

    /**
     * How the credentials in one group were read, from the least reliable source
     * in it. A group mixing definition lines and env exports is only as trustworthy
     * as a plain proximity guess, so it degrades rather than claiming either.
     *
     * @param list<string> $sources
     */
    private static function confidenceFor(array $sources): string
    {
        $distinct = array_unique($sources);

        return count($distinct) === 1 ? reset($distinct) : 'proximity';
    }

    /** @return Link[] */
    private static function linksIn(string $line, string $heading): array
    {
        return self::linksAndResidueIn($line, $heading)['links'];
    }

    /**
     * Same extraction as linksIn(), but also returns the line with every consumed
     * link substring removed. The residue lets the caller check for a credential
     * that sits on the same line as a link (e.g. `[App](url) heslo: secret`)
     * without the link's own brackets/URL confusing the credential pattern.
     *
     * @return array{links: Link[], residue: string}
     */
    private static function linksAndResidueIn(string $line, string $heading): array
    {
        $links = [];
        $consumed = $line;

        // Badges — ![alt](image) on its own, or wrapped in a link as
        // [![alt](image)](target) — are decoration, not addresses a reader would
        // ever open. Drop the image syntax first so neither the image URL nor the
        // leftover "![alt" text can become a link or a label. The wrapping link's
        // target goes too: a badge's target is CI plumbing, and it would otherwise
        // surface under the alt text of an image nobody asked to see.
        $consumed = (string) preg_replace('/\[!\[[^\]]*\]\([^)]*\)\]\([^)]*\)/', '', $consumed);
        $consumed = (string) preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $consumed);
        $line = $consumed;

        // [label](url)
        if (preg_match_all('/\[([^\]]*)\]\(\s*([^)\s]+)[^)]*\)/', $line, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $url = self::normalizeUrl($match[2]);
                if ($url === null) {
                    continue;
                }

                [$url, $basicAuth] = self::splitBasicAuth($url);
                $label = trim(self::stripInlineMarkdown($match[1]));
                $links[] = new Link($label !== '' ? $label : self::hostOf($url), $url, $heading, 'proximity', $basicAuth);
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

                [$url, $basicAuth] = self::splitBasicAuth($url);
                $links[] = new Link(self::hostOf($url), $url, $heading, 'proximity', $basicAuth);
                $consumed = str_replace($candidate, '', $consumed);
            }
        }

        return ['links' => $links, 'residue' => $consumed];
    }

    /**
     * Every credential stated in prose on one line: `user: admin`,
     * `**Heslo:** `x``, `Benutzername: admin`, or two pairs in one sentence.
     *
     * A line can carry more than one credential, so this returns a list. The
     * older single-value form could only ever see the first pair, which meant
     * "uživatel `admin`, heslo `admin`" yielded a username and no password.
     *
     * @return list<Credential>
     */
    private static function definitionCredentialsIn(string $line): array
    {
        $clean = self::stripInlineMarkdown($line, keepBackticks: true);

        $credentials = CredentialPhrases::inLine($clean);
        if ($credentials !== []) {
            return $credentials;
        }

        // No word introduced anything, but a bare `login` / `password` pair
        // still states an account.
        return CredentialPhrases::slashSeparatedPair($clean);
    }

    /**
     * Credentials inside a fenced block. Three shapes appear there, and all
     * three are equally common:
     *
     *   export ADMIN_PASSWORD=x      shell, the original case
     *   password: postgres           YAML config, or a pasted test account
     *   {"password":"x"}             a curl example
     *
     * Before this, only the first was read, so a README that pasted its test
     * account into a plain ``` block reported nothing at all.
     *
     * @return list<Credential>
     */
    private static function fencedCredentialsIn(string $line): array
    {
        $env = self::envCredentialIn($line);
        if ($env !== null) {
            return [$env];
        }

        $json = CredentialPhrases::inJson($line);
        if ($json !== []) {
            return $json;
        }

        return CredentialPhrases::inLine($line);
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

    /**
     * Split `http://user:pass@host/path` into a clean URL and the credentials it
     * carried.
     *
     * The credentials are reported like any other, but they are also removed
     * from the URL itself. The dashboard renders the URL as a clickable anchor,
     * and a password in an `href` is a password in the referrer, in the browser
     * history and in every copy of a shared screenshot — visible in ways the
     * masked credential row deliberately is not.
     *
     * @return array{0: string, 1: list<Credential>}
     */
    private static function splitBasicAuth(string $url): array
    {
        $user = parse_url($url, PHP_URL_USER);
        if (!is_string($user) || $user === '') {
            return [$url, []];
        }

        $password = parse_url($url, PHP_URL_PASS);

        $credentials = [new Credential('user', 'uživatel', urldecode($user), CredentialKeys::isPlaceholder($user))];
        if (is_string($password) && $password !== '') {
            $decoded = urldecode($password);
            $credentials[] = new Credential('password', 'heslo', $decoded, CredentialKeys::isPlaceholder($decoded));
        }

        // Rebuild without the userinfo part. Anything we cannot rebuild safely
        // keeps its original URL rather than being silently mangled.
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($host) || !is_string($scheme)) {
            return [$url, $credentials];
        }

        $port = parse_url($url, PHP_URL_PORT);
        $clean = $scheme . '://' . $host . (is_int($port) ? ':' . $port : '')
            . (parse_url($url, PHP_URL_PATH) ?: '');

        $query = parse_url($url, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $clean .= '?' . $query;
        }

        return [$clean, $credentials];
    }

    /**
     * Label for a link that carries no text of its own. The port is part of it:
     * a README that lists http://localhost:4200, :4201 and :4202 describes three
     * different services, and labelling all three "localhost" makes the list
     * useless. The path is kept for the same reason when the host alone would
     * not distinguish two entries.
     */
    private static function hostOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return $url;
        }

        $port = parse_url($url, PHP_URL_PORT);
        if (is_int($port)) {
            $host .= ':' . $port;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && trim($path, '/') !== '') {
            $host .= rtrim($path, '/');
        }

        return $host;
    }

    /**
     * @param bool $keepBackticks Credential detection needs them: a backticked
     *                            value may contain spaces precisely because the
     *                            backticks delimit it, so stripping them first
     *                            would make `heslo: `two words`` unreadable.
     */
    private static function stripInlineMarkdown(string $text, bool $keepBackticks = false): string
    {
        return trim((string) preg_replace($keepBackticks ? '/[*_]/' : '/[`*_]/', '', $text));
    }

    /**
     * Keep the first occurrence of each URL within a section. The context is always
     * part of the key — otherwise two sections linking the same URL would collapse
     * into one entry that keeps only the first section's credentials, which could
     * silently attach a production secret to a link shown under a development
     * heading (or vice versa).
     *
     * Credential-only entries (no URL, e.g. a table row with no URL column) are
     * NOT distinguished by context alone: a section can contain several of them
     * (one per table row), and they would all share the same empty-url/context
     * key. The label is added to the key in that case so two different services
     * without URLs in the same section are not silently collapsed into one,
     * dropping one of their credentials. Label is deliberately left out of the
     * key when a URL is present, so the existing "same URL under two different
     * labels is the same link" dedup behaviour is unaffected.
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
            $key = ($link->url ?? '') . '|' . $link->context;
            if ($link->url === null) {
                $key .= '|' . $link->label;
            }

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $link;
        }

        return $out;
    }
}
