<?php

declare(strict_types=1);

namespace Makeview;

use Makeview\Readme\Scorer;
use Makeview\Readme\ShapeDetector;
use Makeview\Value\Credential;

/**
 * Recognising a credential written as prose, rather than as an environment
 * variable. Compose files state credentials as `KEY=value`, which
 * CredentialKeys already covers; READMEs state them in whatever words the
 * author happened to use, in whatever language they write documentation in.
 *
 * The vocabulary below is deliberately multilingual: a README saying
 * "Kennwort", "hasło" or "contraseña" is describing exactly the same thing as
 * one saying "heslo", and a dashboard that only understands two languages is
 * only useful to people who write in them.
 */
final class CredentialPhrases
{
    /**
     * Words introducing a username, per language. Order matters only in that
     * every word here must also appear in WORD_PATTERN.
     */
    private const USER_WORDS = [
        // English
        'user', 'username', 'user name', 'login', 'account', 'email', 'e-mail', 'mail',
        // Czech / Slovak
        'uživatel', 'uzivatel', 'uživatelské jméno', 'uzivatelske jmeno',
        'přihlašovací jméno', 'prihlasovaci jmeno', 'jméno', 'jmeno', 'účet', 'ucet',
        'používateľ', 'pouzivatel',
        // German
        'benutzer', 'benutzername', 'nutzer', 'anmeldename', 'konto',
        // Polish
        'użytkownik', 'uzytkownik', 'nazwa użytkownika', 'nazwa uzytkownika', 'konto',
        // Spanish / Portuguese / French / Italian
        'usuario', 'usuário', 'utilisateur', 'utente', 'identifiant', 'correo',
    ];

    /** Words introducing an API token or key. */
    private const TOKEN_WORDS = [
        'token', 'api key', 'apikey', 'api_key', 'api-key', 'access token', 'accesstoken',
        'access key', 'accesskey', 'access_key', 'access-key',
        'secret key', 'secretkey', 'secret_key', 'secret-key',
        'api secret', 'api_secret', 'client secret', 'client_secret',
        'klíč', 'klic', 'kľúč', 'kluc', 'schlüssel', 'schluessel', 'klucz', 'clave', 'clé', 'chiave',
    ];

    /** Words introducing a password. Mirrors the password branch of WORD_PATTERN. */
    private const PASSWORD_WORDS = [
        'password', 'passwd', 'pass',
        'heslo', 'hesla',
        'passwort', 'kennwort',
        'hasło', 'haslo', 'contraseña', 'contrasena', 'senha',
    ];

    /**
     * Every credential word in every supported language, as a regex
     * alternation. Longer spellings come first so that "uživatelské jméno"
     * wins over the "jméno" nested inside it.
     */
    public const WORD_PATTERN =
        // multi-word forms first — longest alternative must win
        'uživatelsk[éeá]\s+jm[ée]no|uzivatelsk[eea]\s+jm[ee]no'
        . '|přihlašovací\s+jméno|prihlasovaci\s+jmeno'
        . '|nazwa\s+użytkownika|nazwa\s+uzytkownika'
        . '|user\s?name|access[\s_-]?token|access[\s_-]?key|secret[\s_-]?key'
        . '|api[\s_-]?key|api[\s_-]?secret|client[\s_-]?secret|anmeldename'
        // usernames
        . '|user|login|account|e-?mail(?:em|u|y)?|mail(?:em|u|y)?'
        . '|uživatel|uzivatel|používateľ|pouzivatel|jméno|jmeno|účet|ucet'
        . '|benutzername|benutzer|nutzer|konto'
        . '|użytkownik|uzytkownik'
        . '|usuario|usuário|utilisateur|utente|identifiant|correo'
        // passwords
        // Czech and Polish inflect: the same word appears as "heslo", "heslem",
        // "hesla", "hasłem". Enumerating each case by hand is what made the old
        // list grow with every README, so the stem carries an optional ending.
        . '|hesl[oaeuy]m?|hasł[oaeuy]m?|hasl[oaeuy]m?'
        . '|password|passwort|kennwort|passwd|pass'
        . '|hasło|haslo|contraseña|contrasena|senha|mot\s+de\s+passe|palavra-passe'
        // tokens and keys
        . '|token|secret|schlüssel|schluessel|klíč|klic|kľúč|kluc|klucz|clave|clé|chiave';

    /**
     * Words that may introduce a value but describe it rather than name it:
     * "default password", "initial login", "výchozí heslo". Allowing an
     * optional adjective in front is what makes a sentence like
     * "(default password `admin`)" readable.
     */
    private const QUALIFIER_PATTERN =
        'default|initial|temporary|test|demo|dev|admin|standard'
        . '|výchozí|vychozi|počáteční|pocatecni|testovací|testovaci|dočasné|docasne'
        . '|standardmäßig|anfänglich|domyślne|domyslne|inicial|par\s+défaut';

    /**
     * A value must not look like prose. An unquoted value is therefore a
     * single whitespace-free run of at least this many characters — short
     * enough to admit "admin", long enough to reject stray punctuation.
     */
    private const MIN_UNQUOTED_LENGTH = 3;

    /**
     * Words that are never a credential value, however they are written. These
     * appear when a sentence mentions a credential word and then continues:
     * "password rotation", "token store". Without this list, prose about
     * security becomes a wall of false credentials.
     */
    private const VALUE_STOPWORDS = [
        'rotation', 'rotace', 'store', 'storage', 'management', 'handling', 'policy',
        'and', 'or', 'the', 'a', 'an', 'is', 'are', 'was', 'in', 'to', 'for', 'with',
        'via', 'from', 'by', 'as', 'be', 'can', 'must', 'should', 'will',
        'authentication', 'authorization', 'auth', 'login', 'logout', 'access',
        'refresh', 'bearer', 'delivered', 'protected', 'required', 'optional',
        'hash', 'hashed', 'hashing', 'encrypted', 'plaintext',
        'je', 'jsou', 'nebo', 'a', 'se', 'pro', 'pomocí', 'pomoci', 'viz',
    ];

    /**
     * Read every `word: value` pair on one line.
     *
     * The value is matched as a token, not as "the rest of the line": READMEs
     * routinely write `heslo: admin (viz compose)` or state two pairs in one
     * sentence, and a greedy match would swallow the remark into the secret or
     * miss the second pair entirely.
     *
     * Prose does not always write the colon. "uživatel `admin`, heslo `s3cret`"
     * and "(default password `admin`)" state a credential just as plainly as
     * "user: admin" does, so a separator is required only when the value is
     * bare. A quoted value may follow the word directly, because the quotes are
     * what distinguish a literal from the next word of a sentence — without
     * them, "password rotation" and every other mention of a credential word
     * would become a secret.
     *
     * @return list<Credential>
     */
    public static function inLine(string $line): array
    {
        $pattern = '/(?:^|[\s*_`|>\-(\[])'
            . '(?:(?:' . self::QUALIFIER_PATTERN . ')\s+)?'
            . '(' . self::WORD_PATTERN . ')'
            . '(?:' . self::SEPARATOR_PATTERN . self::VALUE_PATTERN
            . '|\s+(?:' . self::COPULA_PATTERN . ')\s+' . self::QUOTED_VALUE_PATTERN
            . '|\s+' . self::QUOTED_VALUE_PATTERN . ')'
            . '/iu';

        if (preg_match_all($pattern, $line, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $credentials = [];
        foreach ($matches as $match) {
            $credential = self::build($match[1], self::valueFrom($match));
            if ($credential !== null) {
                $credentials[] = $credential;
            }
        }

        return $credentials;
    }

    /**
     * What may stand between the word and its value. A colon or equals sign is
     * the common form; a dash is what prose uses when it is setting a term off
     * rather than assigning to it ("Username — admin"), and READMEs use all
     * three spellings of it.
     *
     * A dash must have space on both sides, because an unspaced one belongs to
     * whatever follows: `openssl passwd -apr1` names a command-line flag, not a
     * password of "apr1".
     */
    private const SEPARATOR_PATTERN = '(?:\s*[:=]\s*|\s+[-\x{2013}\x{2014}]\s+)';

    /**
     * Prose states a credential with a verb where a table would use a colon:
     * "the password is `x`", "heslo je `x`", "das Kennwort lautet `x`". Without
     * these the whole sentence form goes unread, and it is how most READMEs
     * introduce a credential in running text rather than in a list.
     *
     * Only a quoted value may follow a copula. "the password is rotated weekly"
     * must stay prose, and the quotes are what tell the two apart.
     */
    private const COPULA_PATTERN =
        'is|are|was|will\s+be|defaults?\s+to|remains'
        . '|je|jsou|bude|zůstává|zustava'
        . '|ist|lautet|sind'
        . '|es|son|est|sono|é';

    /**
     * A quoted value may contain spaces because the quotes delimit it; a bare
     * one may not, because nothing else marks where it ends.
     *
     * A bare value ends at a comma, semicolon, or closing bracket as well as
     * the end of the line: `heslo: admin, port: 8080` states a password and
     * a port, and requiring end-of-line would have meant the whole line read
     * as neither.
     */
    private const VALUE_PATTERN =
        '(?:`([^`\n]+)`|"([^"\n]+)"|\'([^\'\n]+)\'|([^\s,;)\]]+)(?=[,;)\]]|\s*(?:[(\[][^\n]*)?$))';

    /**
     * The same, minus the bare form: used where no colon separates the word
     * from the value and the quotes are the only evidence of a literal.
     */
    private const QUOTED_VALUE_PATTERN =
        '(?:`([^`\n]+)`|"([^"\n]+)"|\'([^\'\n]+)\')';

    /**
     * Read a `"key": "value"` pair out of a JSON payload — the shape of every
     * `curl` login example ever pasted into a README.
     *
     * @return list<Credential>
     */
    public static function inJson(string $line): array
    {
        $pattern = '/"(' . self::WORD_PATTERN . ')"\s*:\s*"([^"]+)"/iu';

        if (preg_match_all($pattern, $line, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $credentials = [];
        foreach ($matches as $match) {
            $credential = self::build($match[1], $match[2]);
            if ($credential !== null) {
                $credentials[] = $credential;
            }
        }

        return $credentials;
    }

    /**
     * Words announcing that a login pair follows, in the languages the rest of
     * this class already speaks. They are what makes an unquoted pair readable:
     * "credentials admin / secret123" states an account, while "src / tests"
     * states two directories.
     */
    private const PAIR_INTRODUCER_PATTERN =
        // Verb phrases announce a pair as plainly as the nouns below do —
        // "Sign in with admin / secret" is how prose states an account.
        'sign\s+in(?:\s+with)?|log\s+in(?:\s+with)?|logga\s+in'
        . '|přihlas(?:te)?\s+se|prihlas(?:te)?\s+se|přihlaš(?:te)?\s+se|prihlas(?:te)?'
        . '|anmelden\s+mit|inicia\s+sesión|inicia\s+sesion|connectez-vous'
        . '|credentials|credential|login|logins|account|access'
        . '|přihlašovací\s+údaje|prihlasovaci_?\s*udaje|přihlášení|prihlaseni|údaje|udaje|přístup|pristup'
        . '|zugangsdaten|anmeldedaten|dane\s+logowania|credenciales|credenziali|identifiants';

    /**
     * Read a bare `login / password` pair: two values separated by a slash,
     * where the first looks like an account name. No word names either half, so
     * the shape itself has to carry the meaning.
     *
     * A word must announce the pair. Backticks were once treated as evidence
     * enough on their own, on the reasoning that an author who delimits both
     * halves means them literally — but a literal is not a credential, and the
     * shape is what READMEs use for menu paths, theme names and file pairs:
     *
     *     - dlouhy stisk ciferniku -> `Customize` / `Complications`
     *
     * That is a tap target, and it was reported as an account at a confirmed
     * score. Nothing inside `a / b` separates a login from a menu path, so the
     * introducing word is the only thing that can carry the meaning.
     *
     * @return list<Credential>
     */
    public static function slashSeparatedPair(string $line): array
    {
        return self::introducedSlashPair($line);
    }

    /**
     * The same pair without backticks, preceded by a word that announces it.
     * The introducer may be several words back — "default credentials are
     * admin / secret" — but must stay on the same line and not be separated by
     * sentence-ending punctuation.
     *
     * @return list<Credential>
     */
    private static function introducedSlashPair(string $line): array
    {
        // Each half may be backticked — "Credentials: `admin` / `s3cret`" is as
        // common as the bare form — so the marks are matched here and trimmed
        // below rather than being left to end up inside the reported value.
        $pattern = '/(?:^|[\s*_`|>\-(\[])(?:' . self::PAIR_INTRODUCER_PATTERN . ')'
            . '[^.:;!?\n]{0,20}[\s:=]\s*'
            . '`?([^\s\/`]+)`?\s*\/\s*`?([^\s\/`]+)`?/iu';

        if (preg_match($pattern, $line, $m) !== 1) {
            return [];
        }

        // "Přihlašovací údaje: admin / secret" names the pair as credentials
        // even though no word names either half on its own. The backticked form
        // counts as quoted, which is what lets a short handle like `qa` through
        // the minimum-length rule that keeps stray words out of bare matches.
        $quoted = str_contains($m[0], '`');

        return self::pairCredentials($m[1], $m[2], quoted: $quoted, introduced: true);
    }

    /**
     * Turn a candidate pair into credentials, or reject it.
     *
     * @param bool $quoted Whether the pair was delimited by the author rather
     *                     than found in running text. Quoted values are taken at
     *                     their stated length; unquoted ones must be long enough
     *                     that they cannot be an ordinary short word.
     * @param bool $introduced Whether a word announced the pair as credentials.
     *                         True only for the introduced form, never for a
     *                         bare backticked pair, which has no such word.
     *
     * @return list<Credential>
     */
    private static function pairCredentials(
        string $user,
        string $password,
        bool $quoted = false,
        bool $introduced = false,
    ): array {
        // The first half must plausibly identify a person: an e-mail address,
        // or a short handle. A pair of paths ("`src/` / `dist/`") must not
        // become a login.
        $minHandle = $quoted ? 1 : self::MIN_UNQUOTED_LENGTH;
        $looksLikeAccount = str_contains($user, '@')
            || preg_match('/^[A-Za-z][A-Za-z0-9._-]{' . ($minHandle - 1) . ',}$/', $user) === 1;

        if (!$looksLikeAccount || str_contains($user, '/') || str_contains($password, '/')) {
            return [];
        }

        // `POST /api/v1/auth/login` is a path, and the slashes inside it are
        // separators to this scan. Rejecting the value in isValueUnusable()
        // was not enough: the line simply fell through to here, where the
        // path got split into a "login pair" of `POST` and `api`.
        if (self::isHttpMethod($user)) {
            return [];
        }

        $credentials = [];
        foreach ([['user', 'uživatel', $user], ['password', 'heslo', $password]] as [$kind, $label, $value]) {
            if (self::isValueUnusable($value, $quoted)) {
                return [];
            }

            // The pair's shape is the evidence: two values in a `a / b` form,
            // with a word announcing them or backticks marking them as
            // literals. No word names either half individually, so `introduced`
            // is not claimed — that is what keeps a pair of paths from
            // outscoring an actual stated credential.
            $evidence = ['structured' => true, 'quoted' => $quoted, 'introduced' => $introduced];

            $credentials[] = new Credential(
                $kind,
                $label,
                $value,
                CredentialKeys::isPlaceholder($value),
                Scorer::score($value, $evidence),
                Scorer::explain($value, $evidence),
            );
        }

        return $credentials;
    }

    /**
     * Which of the alternation's capture groups actually matched. Groups 2-5
     * are the separated branch (backtick, double, single, bare); 6-8 the
     * unseparated one, which has no bare form.
     */
    /**
     * Groups 6-8 are the copula branch and 9-11 the unseparated one, neither of
     * which has a bare form: with no separator, only quotes mark a value's end.
     */
    private static function valueFrom(array $match): string
    {
        foreach ([2, 3, 4, 5, 6, 7, 8, 9, 10, 11] as $group) {
            if (($match[$group] ?? '') !== '') {
                return $match[$group];
            }
        }

        return '';
    }

    /**
     * A value is unusable when it is empty, is noise by the shared rules, or is
     * an ordinary word that happened to follow a credential word.
     *
     * @param bool $quoted The author delimited the value, so its length is a
     *                     statement rather than an accident.
     */
    private static function isValueUnusable(string $value, bool $quoted = false): bool
    {
        $trimmed = trim($value, " \t`\"'");

        if ($trimmed === '' || CredentialKeys::isNoise($trimmed)) {
            return true;
        }

        if (!$quoted && !str_contains($trimmed, ' ') && mb_strlen($trimmed) < self::MIN_UNQUOTED_LENGTH) {
            return true;
        }

        if (self::isLocation($trimmed)) {
            return true;
        }

        return in_array(mb_strtolower($trimmed), self::VALUE_STOPWORDS, true);
    }

    /**
     * A value that names where something lives rather than what it is.
     *
     * API docs write "Login: `POST /api/v1/auth/login`" and "Refresh token:
     * `POST /api/v1/auth/refresh`" — a genuine credential word introducing an
     * endpoint. A URL after "API key" is the page that issues the key, not the
     * key. Both were reported as stated credentials because the word was real
     * and the value was quoted.
     *
     * No secret is shaped like this: a path is what you call, a URL is where
     * you go, and neither is what you authenticate with.
     */
    /** An HTTP verb never names an account. */
    private static function isHttpMethod(string $value): bool
    {
        return preg_match('/^(?:GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)$/i', $value) === 1;
    }

    private static function isLocation(string $value): bool
    {
        // `POST /api/v1/auth/login`, `GET /users/me`
        if (preg_match('#^(?:GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)\s+/#i', $value) === 1) {
            return true;
        }

        // A bare path: `/api/v1/auth/login`. A leading slash and at least one
        // more segment — a lone `/` is not a location worth rejecting.
        if (preg_match('#^/[A-Za-z0-9._~-]+(?:/|$)#', $value) === 1) {
            return true;
        }

        return preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) === 1;
    }

    private static function build(string $word, string $value): ?Credential
    {
        $trimmed = trim($value, " \t`\"'");

        if (self::isValueUnusable($trimmed)) {
            return null;
        }

        $kind = self::resolveKind($word, $trimmed);

        // The author wrote the word, so the finding is introduced by definition.
        // Quoting is recorded separately because it is what distinguishes a
        // literal from the next word of a sentence.
        $evidence = [
            'introduced' => true,
            'quoted' => $value !== $trimmed,
        ];

        return new Credential(
            $kind,
            self::labelFor($kind),
            $trimmed,
            CredentialKeys::isPlaceholder($trimmed),
            Scorer::score($trimmed, $evidence),
            Scorer::explain($trimmed, $evidence),
        );
    }

    /** The human-facing name for a kind, in the dashboard's language. */
    private static function labelFor(string $kind): string
    {
        return match ($kind) {
            'user' => 'uživatel',
            'token' => 'token',
            default => 'heslo',
        };
    }

    /**
     * Decide a credential's kind from the word that introduced it *and* the
     * value itself, in falling order of reliability.
     *
     * A listed word is the strongest evidence there is: the author named the
     * thing. Failing that the value's own shape often settles it outright — an
     * `AKIA…` key is a token and an e-mail address is a username no matter what
     * word preceded them. Only then is the word retried fuzzily, which is what
     * catches inflections ("heslem", "emailem") and typos.
     *
     * The old code skipped the middle two steps and returned `password` for
     * every unlisted word, so every inflected form in every language became a
     * password and the vocabulary had to grow with each new README.
     */
    private static function resolveKind(string $word, string $value): string
    {
        $listed = self::listedKindFor($word);
        if ($listed !== null) {
            return $listed;
        }

        $shaped = self::kindFromValueShape($value);
        if ($shaped !== null) {
            return $shaped;
        }

        return self::fuzzyKindFor($word) ?? 'password';
    }

    /**
     * What the value proves about itself, independent of any word. Only shapes
     * that are unambiguous belong here: a high-entropy string is *not* one,
     * because a generated password and an API token look alike, and guessing
     * between them is what made every password come back as a token.
     */
    private static function kindFromValueShape(string $value): ?string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        // An e-mail address is a login. Nothing else in a README is shaped this
        // way, and it is how most admin panels identify an account.
        if (preg_match('/^[^\s@]+@[A-Za-z0-9._-]+\.[A-Za-z]{2,}$/', $trimmed) === 1) {
            return 'user';
        }

        // A vendor-prefixed key states its own issuer, so it is a token by
        // construction. A bare high-entropy run deliberately does not qualify.
        $shape = ShapeDetector::detect($trimmed);

        return $shape !== null && $shape !== 'high_entropy' ? 'token' : null;
    }

    /** Which kind of credential a listed word introduces, or null if unlisted. */
    private static function listedKindFor(string $word): ?string
    {
        $normalized = preg_replace('/\s+/u', ' ', mb_strtolower(trim($word))) ?? '';

        if (in_array($normalized, self::USER_WORDS, true)) {
            return 'user';
        }
        if (in_array($normalized, self::TOKEN_WORDS, true)) {
            return 'token';
        }
        if (in_array($normalized, self::PASSWORD_WORDS, true)) {
            return 'password';
        }

        return null;
    }

    /** Which kind of credential a word introduces. */
    public static function kindFor(string $word): string
    {
        $normalized = preg_replace('/\s+/u', ' ', mb_strtolower(trim($word))) ?? '';

        if (in_array($normalized, self::USER_WORDS, true)) {
            return 'user';
        }
        if (in_array($normalized, self::TOKEN_WORDS, true)) {
            return 'token';
        }

        return 'password';
    }

    /**
     * Shortest word we will fuzzy-match. Below this, one edit is a large
     * share of the word: `use` would reach `user` and `ass` would reach
     * `pass`, turning ordinary prose into credentials.
     */
    private const MIN_FUZZY_LENGTH = 5;

    /** Edits allowed. One covers a typo or an inflected ending; two invites collisions. */
    private const MAX_EDIT_DISTANCE = 1;

    /**
     * Resolve a word to a credential kind, tolerating a typo or an unlisted
     * inflection. Without this, every spelling of every word in every language
     * has to be enumerated by hand, and the list grows on every new README.
     */
    public static function fuzzyKindFor(string $word): ?string
    {
        $normalized = mb_strtolower(trim($word));

        if ($normalized === '') {
            return null;
        }

        // Exact matches first: an unmodified word never needs distance work,
        // and the fuzzy pass below must not override a word that already fits.
        if (in_array($normalized, self::USER_WORDS, true)) {
            return 'user';
        }
        if (in_array($normalized, self::TOKEN_WORDS, true)) {
            return 'token';
        }
        if (in_array($normalized, self::PASSWORD_WORDS, true)) {
            return 'password';
        }

        if (mb_strlen($normalized) < self::MIN_FUZZY_LENGTH) {
            return null;
        }

        $best = null;
        $bestDistance = self::MAX_EDIT_DISTANCE + 1;

        foreach ([['user', self::USER_WORDS], ['token', self::TOKEN_WORDS], ['password', self::PASSWORD_WORDS]] as [$kind, $words]) {
            foreach ($words as $candidate) {
                if (abs(mb_strlen($candidate) - mb_strlen($normalized)) > self::MAX_EDIT_DISTANCE) {
                    continue;
                }

                $distance = levenshtein($normalized, mb_strtolower($candidate));
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $best = $kind;
                }
            }
        }

        return $bestDistance <= self::MAX_EDIT_DISTANCE ? $best : null;
    }
}
