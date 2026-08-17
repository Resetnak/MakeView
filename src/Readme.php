<?php

declare(strict_types=1);

namespace Makeview;

use Makeview\Readme\AdjacentLineDetector;
use Makeview\Readme\BlockParser;
use Makeview\Readme\CommandLineDetector;
use Makeview\Readme\LinkDetector;
use Makeview\Readme\ListDetector;
use Makeview\Readme\Scorer;
use Makeview\Readme\ShapeDetector;
use Makeview\Readme\TableDetector;
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
    /** @return Link[] */
    public static function parse(string $markdown): array
    {
        $links = [];

        foreach (self::sections($markdown) as $section) {
            foreach (self::parseSection($section['heading'], $section['body']) as $link) {
                $links[] = $link;
            }
        }

        // Block-based detectors catch shapes the section walk cannot: a value
        // on the line below its label, links defined at the foot of the file,
        // and secrets identifiable by their own format with no introducing word.
        $blocks = BlockParser::parse($markdown);

        foreach (LinkDetector::detect($blocks) as $found) {
            $links[] = new Link($found['label'], $found['url'], $found['heading'], 'proximity', []);
        }

        // One link per heading, never one for the whole document: a single
        // link labelled with the first block's heading would carry every list
        // credential in the file, showing a production secret under a
        // development heading. Grouping preserves document order.
        $byHeading = [];
        foreach (ListDetector::detect($blocks) as $found) {
            $byHeading[$found['heading']][] = $found['credential'];
        }

        // A label alone on one line with its value on the next is neither a
        // list nor a complete line, so neither detector above sees it.
        foreach (AdjacentLineDetector::detect($blocks) as $found) {
            $byHeading[$found['heading']][] = $found['credential'];
        }

        // Credentials passed as command arguments (`psql -U app`, `curl -u
        // admin:pass`), which no word introduces.
        foreach (CommandLineDetector::detect($blocks) as $found) {
            $byHeading[$found['heading']][] = $found['credential'];
        }

        foreach ($byHeading as $heading => $credentials) {
            $links[] = new Link((string) $heading, null, (string) $heading, 'definition', $credentials);
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
        $tableLinks = TableDetector::extractTable($body, $heading);
        if ($tableLinks !== []) {
            // A credential table is self-contained; proximity would only add noise.
            return $tableLinks;
        }

        return self::pairByProximity($heading, $body);
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

            if ($credentials === [] && !$inFence) {
                $credentials = self::shapeIdentifiedCredentialsIn($credentialSource);
            }

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
     * A secret recognisable purely from its own format, with no `heslo:` or
     * `token:` in front of it — a pasted `AKIA…` key or a JWT dropped into a
     * sentence. Word-driven detection never sees these, so each
     * whitespace-separated word on the line is checked against
     * {@see ShapeDetector} instead, and the ones that match are scored so the
     * dashboard can show why.
     *
     * @return list<Credential>
     */
    private static function shapeIdentifiedCredentialsIn(string $line): array
    {
        // Deliberately NOT stripInlineMarkdown(): that helper removes `_` as
        // markdown emphasis, which is also a literal character inside real
        // secrets (`ghp_…`, `xoxb-…`). Stripping it silently corrupts the value
        // the dashboard hands the user, and destroys the very prefix the shape
        // is recognised by. Emphasis markers are shed by the per-word trim
        // below instead, which only touches the ends of a token.
        // An assignment is read as an assignment, fence or no fence. Scanning
        // `PG_USERS_HOST_PORT=15432` as one opaque token cleared the entropy
        // bar on its digits and its `=`, and reported the whole string —
        // variable name included — as a secret. Splitting it here lets
        // CredentialKeys apply the same judgement it already applies inside a
        // fence, which knows that a key ending in _PORT or _HOST names no
        // secret. Returning early is deliberate: once a line is an assignment,
        // its remaining words are the value, already handled.
        // An assignment need not start its line: READMEs write them mid-sentence
        // ("Set FOO=bar before running") and after a bullet marker. Anchoring at
        // the start missed both, and the whole `KEY=VALUE` string went on to the
        // entropy scan below.
        // Note the two distinct outcomes: a line holding an assignment returns
        // here either way. `ANDROID_SERIAL=emulator-5554` yields no credential
        // AND stops — falling through to the entropy scan is exactly what
        // reported the whole `KEY=VALUE` string as a secret.
        if (self::hasEnvAssignment($line)) {
            return self::envAssignmentsIn($line);
        }

        $words = preg_split('/\s+/', trim($line, " \t,;")) ?: [];

        $credentials = [];

        foreach ($words as $word) {
            // A word that still carries `[`, `]`, `(`, or `)` is leftover markdown
            // link syntax linksAndResidueIn() could not strip — a mailto:/javascript:
            // link is deliberately left unconsumed there because it is not a URL we
            // report, not a credential candidate for this scan to split apart.
            if (preg_match('/[\[\]()]/', $word) === 1) {
                continue;
            }

            // `*` and `_` are trimmed only at the ends, where they are emphasis
            // markers; inside a token they are part of the secret itself.
            $candidate = trim($word, " \t,;.!?\"'`<>*_");
            if ($candidate === '') {
                continue;
            }

            $shape = ShapeDetector::detect($candidate);
            if ($shape === null || !self::looksGenerated($shape, $candidate)) {
                continue;
            }

            // Evidence is observed, never assumed. This scan runs on free prose,
            // where the only thing actually known about the candidate is its
            // shape; claiming `structured` here put every match at 0.70, above
            // THRESHOLD_CONFIRMED, so prose reached the UI behind the "real
            // secret" widget and the uncertain branch became unreachable.
            // A bare shape match alone is 0.55 — `likely`, not `confirmed`.
            $evidence = ['quoted' => self::isBacktickQuoted($word)];
            $score = Scorer::score($candidate, $evidence);

            $credentials[] = new Credential(
                'token',
                $shape,
                $candidate,
                CredentialKeys::isPlaceholder($candidate),
                $score,
                Scorer::explain($candidate, $evidence),
            );
        }

        return $credentials;
    }

    /**
     * A value an author wrapped in backticks was marked as a literal, which is
     * weak but real corroboration that it is a value rather than prose. It is
     * the only extra signal available to a scan that runs on bare words.
     */
    private static function isBacktickQuoted(string $word): bool
    {
        $trimmed = trim($word, " \t,;.!?\"'<>");

        return strlen($trimmed) > 2 && str_starts_with($trimmed, '`') && str_ends_with($trimmed, '`');
    }

    /** Same-case letter runs longer than this never occur in a generated secret. */
    private const MAX_GENERATED_CASE_RUN = 2;

    /**
     * The prefixed-vendor and JWT shapes are already narrow enough to trust on
     * their own — nothing but a real token matches `AKIA…` or a decodable JWT
     * header. The `high_entropy` fallback is not: Shannon entropy alone scores
     * an ordinary word above the bits/char threshold whenever its letters are
     * varied enough, including plain English words like "Description" or
     * "Authentication", hyphenated compounds like "user-scoped", and — the
     * false positive this gate exists to rule out — camelCase identifiers
     * lifted straight from source, like "getUserName" or "isPlaceholder".
     *
     * A digit is still the strongest signal a word is generated rather than
     * typed, so that alone is enough. But requiring it unconditionally
     * silently drops letter-only generated secrets like `xKmPvQzLwnR` — exactly
     * the "shape-identified secret with no introducing word" case this scan
     * exists for. What actually separates a generated string from an English
     * word or a code identifier is not *how many times* the case changes but
     * *how long a run of same-case letters gets between changes*: prose and
     * identifiers are built from real words or word-fragments, so every
     * transition is followed by a run of several letters in one case
     * ("Description", "get|User|Name" — each run is 3+ letters); a generated
     * string has no words in it, so case flips every letter or two
     * ("x|K|m|P|v|Q|z|L|wn|R" tops out at a run of 2). A candidate with no
     * digit is therefore accepted only when its longest same-case run is
     * short enough that it could not be a real word or identifier fragment.
     */
    private static function looksGenerated(string $shape, string $candidate): bool
    {
        if ($shape !== 'high_entropy') {
            return true;
        }

        if (preg_match('/[0-9]/', $candidate) === 1) {
            return true;
        }

        return self::longestCaseRun($candidate) <= self::MAX_GENERATED_CASE_RUN;
    }

    /**
     * Length of the longest run of consecutive letters that share the same
     * case. Case changes (upper/lower boundaries) start a new run; non-letter
     * characters (digits, punctuation) are ignored rather than breaking a run,
     * since they carry no case of their own.
     */
    private static function longestCaseRun(string $candidate): int
    {
        $letters = array_values(array_filter(
            preg_split('//u', $candidate, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            static fn (string $char): bool => ctype_alpha($char),
        ));

        $longest = 0;
        $current = 0;
        $currentIsUpper = null;

        foreach ($letters as $letter) {
            $isUpper = ctype_upper($letter);
            if ($currentIsUpper !== null && $isUpper !== $currentIsUpper) {
                $current = 0;
            }
            $current++;
            $currentIsUpper = $isUpper;
            $longest = max($longest, $current);
        }

        return $longest;
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

    /**
     * A shell-style assignment anywhere on a line: at its start, after a bullet
     * marker, or inside a sentence. The key is upper-case by convention, which
     * is what keeps this from matching ordinary prose containing an `=`.
     */
    private const ENV_ASSIGNMENT_PATTERN =
        '/(?:^|[\s*_`>(\[])(?:export\s+)?([A-Z][A-Z0-9_]*)=("[^"\n]*"|\'[^\'\n]*\'|[^\s]*)/';

    /** Whether the line states an assignment at all, secret or not. */
    private static function hasEnvAssignment(string $line): bool
    {
        return preg_match(self::ENV_ASSIGNMENT_PATTERN, $line) === 1;
    }

    /**
     * Every `KEY=VALUE` on a line, wherever it sits — start of line, after a
     * bullet, or mid-sentence. Each key is judged by CredentialKeys, so a name
     * ending in _PORT or _HOST yields nothing while _PASSWORD yields its value.
     *
     * Returning an empty list for `ANDROID_SERIAL=emulator-5554` is the point:
     * the caller then reports nothing at all for that line, rather than letting
     * the entropy scan take the whole string as one opaque secret.
     *
     * The value ends at whitespace. A value containing spaces has to be quoted
     * to be readable as one anyway, and the quotes are stripped below.
     *
     * @return list<Credential>
     */
    private static function envAssignmentsIn(string $line): array
    {
        if (preg_match_all(self::ENV_ASSIGNMENT_PATTERN, $line, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $credentials = [];

        foreach ($matches as $match) {
            if (!CredentialKeys::matches($match[1])) {
                continue;
            }

            $value = trim($match[2], " \t\"'");
            if ($value === '' || CredentialKeys::isNoise($value)) {
                continue;
            }

            $credentials[] = Credential::fromKey($match[1], $value);
        }

        return $credentials;
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
    public static function normalizeUrl(string $candidate): ?string
    {
        // Backticks are trimmed with the other delimiters: READMEs write
        // `http://localhost:8080` constantly, and a bare URL ends at
        // whitespace, so the closing mark stayed inside the value and reached
        // the dashboard's href — a link that does not resolve.
        //
        // Trailing sentence punctuation goes too. "API is at http://x." ends a
        // sentence, not a path, and every call site was rtrim-ing the same
        // characters by hand anyway.
        $url = trim($candidate, " \t<>\"'`");
        $url = rtrim($url, '.,;:');

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

        // URL syntax defines the userinfo part: this is not an inference from
        // wording or layout but the format itself saying what these are, and
        // the value sits next to the link it opens.
        $evidence = ['introduced' => true, 'structured' => true, 'nearLink' => true];

        $decodedUser = urldecode($user);
        $credentials = [new Credential(
            'user',
            'uživatel',
            $decodedUser,
            CredentialKeys::isPlaceholder($user),
            Scorer::score($decodedUser, $evidence),
            Scorer::explain($decodedUser, $evidence),
        )];

        if (is_string($password) && $password !== '') {
            $decoded = urldecode($password);
            $credentials[] = new Credential(
                'password',
                'heslo',
                $decoded,
                CredentialKeys::isPlaceholder($decoded),
                Scorer::score($decoded, $evidence),
                Scorer::explain($decoded, $evidence),
            );
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
    public static function hostOf(string $url): string
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
    public static function stripInlineMarkdown(string $text, bool $keepBackticks = false): string
    {
        // Emphasis is a *pair* of markers wrapping a run of text, and only the
        // paired form is markdown. A lone `_` inside a word is a literal
        // character — `grafana_admin`, `ghp_…`, `POSTGRES_PASSWORD` — so
        // deleting every `_` corrupts the value the dashboard hands the reader.
        // Matching the pairs leaves those untouched.
        $stripped = (string) preg_replace(
            ['/\*\*(.+?)\*\*/us', '/\*(.+?)\*/us', '/(?<![A-Za-z0-9])__(.+?)__(?![A-Za-z0-9])/us', '/(?<![A-Za-z0-9])_(.+?)_(?![A-Za-z0-9])/us'],
            '$1',
            $text
        );

        if (!$keepBackticks) {
            $stripped = str_replace('`', '', $stripped);
        }

        return trim($stripped);
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
        $positions = [];
        $out = [];

        foreach ($links as $link) {
            $key = ($link->url ?? '') . '|' . $link->context;
            if ($link->url === null) {
                $key .= '|' . $link->label;
            }

            if (!isset($positions[$key])) {
                $positions[$key] = count($out);
                $out[] = $link;
                continue;
            }

            // Two detectors reaching the same link is the normal case, not a
            // conflict: one may have read the username and the other the
            // password. Dropping the later entry outright discarded whichever
            // half arrived second, so the halves are merged instead.
            $out[$positions[$key]] = self::mergeCredentials($out[$positions[$key]], $link);
        }

        return $out;
    }

    /**
     * Add the second link's credentials to the first, keeping document order
     * and skipping values already present. A value can legitimately be reported
     * twice — a table row and a sentence stating the same account — and showing
     * it twice reads as two different secrets.
     */
    private static function mergeCredentials(Link $kept, Link $duplicate): Link
    {
        $credentials = [];

        // Keyed by value alone, not by value and kind. The same secret often
        // arrives from two detectors with different kinds — the shape scan
        // guesses `token` from entropy while a `Heslo:` label states
        // `password` — and keying on both let it through twice, which reads as
        // two separate secrets that happen to be identical.
        foreach ([...$kept->credentials, ...$duplicate->credentials] as $credential) {
            $existing = $credentials[$credential->value] ?? null;

            if ($existing === null || self::isBetterEvidence($credential, $existing)) {
                $credentials[$credential->value] = $credential;
            }
        }

        return $kept->withCredentials(array_values($credentials));
    }

    /**
     * Which of two findings for the same value to keep. A credential the author
     * named outranks one inferred from the value's shape: `high_entropy` states
     * only that the value looks random, which is true of a password and a token
     * alike, so it must never displace a `Heslo:` label that says which it is.
     */
    private static function isBetterEvidence(Credential $candidate, Credential $existing): bool
    {
        return $existing->label === 'high_entropy' && $candidate->label !== 'high_entropy';
    }
}
