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

    /**
     * Words that turn whatever they are attached to into an instruction rather
     * than a value. A README writing `sk-your-openai-api-key` or `<my-token>` is
     * telling the reader to substitute their own; the exact spelling varies
     * endlessly, so this matches the possessive word wherever it appears rather
     * than trying to enumerate the results.
     */
    private const PLACEHOLDER_WORD_PATTERN =
        '/(?:^|[^a-z0-9])(your|yours|my|mine|own|here|insert|replace|example|sample|dummy|fake|placeholder|xxxx?)'
        . '(?:[^a-z0-9]|$)/i';

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

        if (in_array(mb_strtolower($trimmed), self::PLACEHOLDERS, true)) {
            return true;
        }

        return preg_match(self::PLACEHOLDER_WORD_PATTERN, $trimmed) === 1;
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
