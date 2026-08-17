<?php

declare(strict_types=1);

namespace Makeview\Readme;

/**
 * Secrets recognisable from their own format, whatever word introduced them.
 *
 * Word-driven detection needs an author to write `token:` before the value.
 * A pasted JWT or `AKIA…` key often has no such word, so this is the class of
 * secret the old parser could not see at all.
 */
final class ShapeDetector
{
    /**
     * Prefixed vendor tokens. Each prefix is paired with the body length the
     * vendor actually issues, so a sentence starting "sk-" cannot match.
     */
    private const PREFIXED = [
        'github_token' => '/^gh[pousr]_[A-Za-z0-9]{36,}$/',
        'openai_key' => '/^sk-(?:proj-)?[A-Za-z0-9_-]{20,}$/',
        'aws_key' => '/^(?:AKIA|ASIA)[A-Z0-9]{16}$/',
        'slack_token' => '/^xox[baprs]-[A-Za-z0-9-]{10,}$/',
    ];

    public static function detect(string $value): ?string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        foreach (self::PREFIXED as $shape => $pattern) {
            if (preg_match($pattern, $trimmed) === 1) {
                return $shape;
            }
        }

        if (self::isJwt($trimmed)) {
            return 'jwt';
        }

        return self::isHighEntropySecret($trimmed) ? 'high_entropy' : null;
    }

    public static function isSecretShape(string $value): bool
    {
        return self::detect($value) !== null;
    }

    /**
     * Shannon entropy alone cannot tell a generated secret from an ordinary
     * sentence, a file path, or a URL — all of those clear the bits/char
     * threshold once they are long and varied enough. A secret is always a
     * single token, so anything containing whitespace, a path separator, or
     * a hostname shape is excluded before entropy is even considered.
     */
    private static function isHighEntropySecret(string $value): bool
    {
        if (!Entropy::isHighEntropy($value)) {
            return false;
        }

        if (preg_match('/\s/', $value) === 1) {
            return false;
        }

        if (str_contains($value, '/') || str_contains($value, '\\')) {
            return false;
        }

        if (self::looksLikeHostname($value)) {
            return false;
        }

        if (self::looksLikeAddress($value)) {
            return false;
        }

        if (self::looksLikeTimestamp($value)) {
            return false;
        }

        if (self::isSeparatorJoinedWords($value)) {
            return false;
        }

        if (self::isNumberPrefixedWord($value)) {
            return false;
        }

        return true;
    }

    /**
     * A hostname/domain is dot-separated labels of letters, digits, and
     * hyphens (e.g. `www.example.com`), which is exactly the shape a mixed
     * alphanumeric secret can otherwise coincidentally share.
     */
    private static function looksLikeHostname(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+$/', $value) === 1;
    }

    /**
     * The two everyday "address" tokens of a README: `host:port` and an e-mail
     * address. Neither has to contain a dot — `redis:6379` and `localhost:3000`
     * are service names — so the dot-separated hostname rule never saw them,
     * and both cleared the entropy bar on their digits alone.
     */
    private static function looksLikeAddress(string $value): bool
    {
        if (preg_match('/^[A-Za-z0-9._-]+:\d{1,5}$/', $value) === 1) {
            return true;
        }

        return preg_match('/^[^\s@]+@[A-Za-z0-9._-]+\.[A-Za-z]{2,}$/', $value) === 1;
    }

    /** An ISO-8601 timestamp is all digits and separators, so entropy rates it highly. */
    private static function looksLikeTimestamp(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)?$/', $value) === 1;
    }

    /**
     * Header names, error constants and slugs (`Content-Type`,
     * `ERR_MODULE_NOT_FOUND`, `make-view-dashboard`, `POSTGRES_PASSWORD`) are
     * real words joined by `-` or `_`. A generated secret has no words in it,
     * so if every separated part is a pronounceable word-like run of letters,
     * this is vocabulary rather than randomness.
     *
     * Guarded on there actually being a separator, and on every part reading as
     * a word: `ghp_abc9x2q…` mixes digits through its letters and stays a
     * secret.
     */
    private static function isSeparatorJoinedWords(string $value): bool
    {
        $parts = preg_split('/[-_]/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) < 2) {
            return false;
        }

        foreach ($parts as $part) {
            if (!self::isWordLike($part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether one part of a separated name reads as vocabulary.
     *
     * Three shapes qualify, and they are what technical prose is made of:
     * a bare count (`10` in `10-stage`), a plain word (`stage`), and a word
     * carrying a trailing number — `mingw32`, `utf8`, `base64`, `x86`, `sha256`.
     * That last shape is the one that mattered: requiring letters-only made
     * `ctype_alpha('mingw32')` false, so a single digit inside a tool name
     * defeated the whole check and `mingw32-make` was reported as a token.
     *
     * A generated secret does not look like this. Its digits are interleaved
     * through the letters rather than trailing them, and its parts are not
     * pronounceable runs.
     *
     * A one-letter stem (`x86`) does not qualify: MIN_WORD_LENGTH exists to
     * keep single characters from reading as vocabulary, and lowering it to
     * admit one architecture name would let real noise through. `x86_64-linux`
     * is therefore still reported — a false positive kept deliberately in
     * preference to a weaker rule.
     */
    private static function isWordLike(string $part): bool
    {
        if (ctype_digit($part)) {
            return true;
        }

        return preg_match('/^[A-Za-zÀ-ž]{' . self::MIN_WORD_LENGTH . ',}\d*$/u', $part) === 1;
    }

    /**
     * A quantity written as one word: `30minutovou`, `10stage`, `4hodinovy`.
     * No separator splits these, so the rule above never sees them, and the
     * digits are exactly what carries such a word over the entropy bar.
     *
     * A generated secret does not begin with a run of digits followed by
     * nothing but letters — `ghp_…` and `AKIA…` are caught by their prefixes
     * long before this, and a random string mixes cases and symbols instead.
     */
    private static function isNumberPrefixedWord(string $value): bool
    {
        return preg_match('/^\d+[A-Za-zÀ-ž]{3,}$/u', $value) === 1;
    }

    /** Shorter letter runs than this are initials or noise, not a word. */
    private const MIN_WORD_LENGTH = 2;

    /**
     * Three base64url segments is the shape, but `www.example.com` has that
     * shape too. The header must actually decode to JSON naming an algorithm,
     * which is what makes this precise enough to act on.
     */
    private static function isJwt(string $value): bool
    {
        if (preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $value) !== 1) {
            return false;
        }

        $header = explode('.', $value)[0];
        $padded = strtr($header, '-_', '+/') . str_repeat('=', (4 - strlen($header) % 4) % 4);
        $decoded = base64_decode($padded, true);

        if ($decoded === false) {
            return false;
        }

        $parsed = json_decode($decoded, true);

        return is_array($parsed) && isset($parsed['alg']);
    }
}
