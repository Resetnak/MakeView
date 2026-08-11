<?php

declare(strict_types=1);

namespace Makeview;

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
        'klíč', 'klic', 'kľúč', 'kluc', 'schlüssel', 'schluessel', 'klucz', 'clave', 'clé', 'chiave',
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
        . '|user\s?name|access\s?token|api[\s_-]?key|anmeldename'
        // usernames
        . '|user|login|account|e-?mail|mail'
        . '|uživatel|uzivatel|používateľ|pouzivatel|jméno|jmeno|účet|ucet'
        . '|benutzername|benutzer|nutzer|konto'
        . '|użytkownik|uzytkownik'
        . '|usuario|usuário|utilisateur|utente|identifiant|correo'
        // passwords
        . '|heslo|hesla|password|passwort|kennwort|passwd|pass'
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
     * A quoted value may contain spaces because the quotes delimit it; a bare
     * one may not, because nothing else marks where it ends.
     *
     * A bare value must also end the line, give or take a trailing remark in
     * brackets: "heslo: admin (viz compose)" states a secret, while "Password:
     * ask the team lead for the current shared value" is an instruction whose
     * first word would otherwise be published as the password.
     */
    private const VALUE_PATTERN =
        '(?:`([^`\n]+)`|"([^"\n]+)"|\'([^\'\n]+)\'|([^\s,;)\]]+)(?=\s*(?:[(\[][^\n]*)?$))';

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
        'credentials|credential|login|logins|account|access'
        . '|přihlašovací\s+údaje|prihlasovaci_?\s*udaje|přihlášení|prihlaseni|údaje|udaje|přístup|pristup'
        . '|zugangsdaten|anmeldedaten|dane\s+logowania|credenciales|credenziali|identifiants';

    /**
     * Read a bare `login / password` pair: two values separated by a slash,
     * where the first looks like an account name. No word names either half, so
     * the shape itself has to carry the meaning.
     *
     * Backticks are the strongest evidence of a literal and stand on their own.
     * Without them there is nothing to distinguish an account from a pair of
     * paths or a date, so an unquoted pair is read only when a word announces
     * it. Bold is not that evidence: `**` is stripped before this runs, exactly
     * as `**src** / **tests**` deserves.
     *
     * @return list<Credential>
     */
    public static function slashSeparatedPair(string $line): array
    {
        if (preg_match('/`([^`\s]+)`\s*\/\s*`([^`\s]+)`/u', $line, $m) !== 1) {
            return self::introducedSlashPair($line);
        }

        // Backticks delimit the value, so the minimum length that keeps stray
        // words out of unquoted matches does not apply: a login of `qa` is as
        // deliberate as one of `qa-admin`.
        return self::pairCredentials($m[1], $m[2], quoted: true);
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
        $pattern = '/(?:^|[\s*_`|>\-(\[])(?:' . self::PAIR_INTRODUCER_PATTERN . ')'
            . '[^.:;!?\n]{0,20}[\s:=]\s*'
            . '([^\s\/]+)\s*\/\s*([^\s\/]+)/iu';

        if (preg_match($pattern, $line, $m) !== 1) {
            return [];
        }

        return self::pairCredentials($m[1], $m[2]);
    }

    /**
     * Turn a candidate pair into credentials, or reject it.
     *
     * @param bool $quoted Whether the pair was delimited by the author rather
     *                     than found in running text. Quoted values are taken at
     *                     their stated length; unquoted ones must be long enough
     *                     that they cannot be an ordinary short word.
     *
     * @return list<Credential>
     */
    private static function pairCredentials(string $user, string $password, bool $quoted = false): array
    {
        // The first half must plausibly identify a person: an e-mail address,
        // or a short handle. A pair of paths ("`src/` / `dist/`") must not
        // become a login.
        $minHandle = $quoted ? 1 : self::MIN_UNQUOTED_LENGTH;
        $looksLikeAccount = str_contains($user, '@')
            || preg_match('/^[A-Za-z][A-Za-z0-9._-]{' . ($minHandle - 1) . ',}$/', $user) === 1;

        if (!$looksLikeAccount || str_contains($user, '/') || str_contains($password, '/')) {
            return [];
        }

        $credentials = [];
        foreach ([['user', 'uživatel', $user], ['password', 'heslo', $password]] as [$kind, $label, $value]) {
            if (self::isValueUnusable($value, $quoted)) {
                return [];
            }

            $credentials[] = new Credential($kind, $label, $value, CredentialKeys::isPlaceholder($value));
        }

        return $credentials;
    }

    /**
     * Which of the alternation's capture groups actually matched. Groups 2-5
     * are the separated branch (backtick, double, single, bare); 6-8 the
     * unseparated one, which has no bare form.
     */
    private static function valueFrom(array $match): string
    {
        foreach ([2, 3, 4, 5, 6, 7, 8] as $group) {
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

        return in_array(mb_strtolower($trimmed), self::VALUE_STOPWORDS, true);
    }

    private static function build(string $word, string $value): ?Credential
    {
        $trimmed = trim($value, " \t`\"'");

        if (self::isValueUnusable($trimmed)) {
            return null;
        }

        $kind = self::kindFor($word);

        return new Credential(
            $kind,
            $kind === 'user' ? 'uživatel' : ($kind === 'token' ? 'token' : 'heslo'),
            $trimmed,
            CredentialKeys::isPlaceholder($trimmed),
        );
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
}
