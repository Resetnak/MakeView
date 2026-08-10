<?php

declare(strict_types=1);

namespace Makeview\Tests;

use Makeview\CredentialKeys;
use Makeview\Value\Credential;
use PHPUnit\Framework\TestCase;

final class CredentialKeysTest extends TestCase
{
    public function testMatchesCommonCredentialKeys(): void
    {
        self::assertTrue(CredentialKeys::matches('POSTGRES_PASSWORD'));
        self::assertTrue(CredentialKeys::matches('MYSQL_ROOT_PASSWORD'));
        self::assertTrue(CredentialKeys::matches('ARGOCD_USER'));
        self::assertTrue(CredentialKeys::matches('APP_USERNAME'));
        self::assertTrue(CredentialKeys::matches('GITHUB_TOKEN'));
        self::assertTrue(CredentialKeys::matches('STRIPE_API_KEY'));
        self::assertTrue(CredentialKeys::matches('SESSION_SECRET'));
        self::assertTrue(CredentialKeys::matches('DB_PASS'));
    }

    public function testDoesNotMatchUnrelatedKeys(): void
    {
        self::assertFalse(CredentialKeys::matches('NODE_ENV'));
        self::assertFalse(CredentialKeys::matches('PORT'));
        self::assertFalse(CredentialKeys::matches('POSTGRES_DB'));
        self::assertFalse(CredentialKeys::matches('TZ'));
    }

    public function testMatchingIsCaseInsensitive(): void
    {
        self::assertTrue(CredentialKeys::matches('postgres_password'));
        self::assertTrue(CredentialKeys::matches('Db_User'));
    }

    public function testKindForDistinguishesUserPasswordAndToken(): void
    {
        self::assertSame('user', CredentialKeys::kindFor('POSTGRES_USER'));
        self::assertSame('user', CredentialKeys::kindFor('APP_USERNAME'));
        self::assertSame('token', CredentialKeys::kindFor('GITHUB_TOKEN'));
        self::assertSame('token', CredentialKeys::kindFor('STRIPE_API_KEY'));
        self::assertSame('password', CredentialKeys::kindFor('POSTGRES_PASSWORD'));
        self::assertSame('password', CredentialKeys::kindFor('DB_PASS'));
        self::assertSame('password', CredentialKeys::kindFor('SESSION_SECRET'));
    }

    public function testUserWinsOverPasswordWhenKeyContainsBoth(): void
    {
        // A key like USER_PASSWORD is a password, not a user — password must win here.
        self::assertSame('password', CredentialKeys::kindFor('USER_PASSWORD'));
    }

    public function testDetectsPlaceholderValues(): void
    {
        self::assertTrue(CredentialKeys::isPlaceholder('${DB_PASSWORD}'));
        self::assertTrue(CredentialKeys::isPlaceholder('${DB_PASSWORD:-secret}'));
        self::assertTrue(CredentialKeys::isPlaceholder('<your-password>'));
        self::assertTrue(CredentialKeys::isPlaceholder('changeme'));
        self::assertTrue(CredentialKeys::isPlaceholder('CHANGEME'));
        self::assertTrue(CredentialKeys::isPlaceholder('your-password'));
        self::assertTrue(CredentialKeys::isPlaceholder('xxx'));
        self::assertTrue(CredentialKeys::isPlaceholder('TODO'));
        self::assertTrue(CredentialKeys::isPlaceholder('***'));
        self::assertTrue(CredentialKeys::isPlaceholder('...'));
    }

    /**
     * A possessive word anywhere in the value is an instruction to substitute,
     * however the author spelled the rest of it. No fixed list of spellings
     * would have covered `sk-your-openai-api-key`.
     */
    public function testDetectsPlaceholdersByTheirWording(): void
    {
        self::assertTrue(CredentialKeys::isPlaceholder('your-api-key'));
        self::assertTrue(CredentialKeys::isPlaceholder('sk-your-openai-api-key'));
        self::assertTrue(CredentialKeys::isPlaceholder('my-secret-token'));
        self::assertTrue(CredentialKeys::isPlaceholder('insert-token-here'));
    }

    public function testRealValuesAreNotPlaceholders(): void
    {
        self::assertFalse(CredentialKeys::isPlaceholder('admin'));
        self::assertFalse(CredentialKeys::isPlaceholder('s3cr3t-p4ss'));
        self::assertFalse(CredentialKeys::isPlaceholder('hunter2'));
    }

    /**
     * example.com is the domain reserved for documentation, so a README that
     * lists `test@example.com` is stating the address a reader should actually
     * log in with. Treating the "example" in it as a substitution marker would
     * grey out the one value that is not one.
     */
    public function testDocumentationEmailAddressesAreRealValues(): void
    {
        self::assertFalse(CredentialKeys::isPlaceholder('test@example.com'));
        self::assertFalse(CredentialKeys::isPlaceholder('demo@example.org'));
    }

    public function testDetectsNoiseValues(): void
    {
        self::assertTrue(CredentialKeys::isNoise(str_repeat('a', 201)));
        // whitespace and over 40 chars: a sentence, not a secret
        self::assertTrue(CredentialKeys::isNoise('run the command below to generate a token'));
        self::assertTrue(CredentialKeys::isNoise(''));
    }

    public function testShortValuesWithSpacesAreNotNoise(): void
    {
        // Some passwords legitimately contain a space and stay short.
        self::assertFalse(CredentialKeys::isNoise('correct horse battery'));
        self::assertFalse(CredentialKeys::isNoise('admin'));
    }

    public function testCredentialFromKeyBuildsCorrectDto(): void
    {
        $c = Credential::fromKey('POSTGRES_PASSWORD', 'hunter2');

        self::assertSame('password', $c->kind);
        self::assertSame('POSTGRES_PASSWORD', $c->label);
        self::assertSame('hunter2', $c->value);
        self::assertFalse($c->isPlaceholder);
    }

    public function testCredentialFromKeyFlagsPlaceholder(): void
    {
        $c = Credential::fromKey('POSTGRES_PASSWORD', '${DB_PASSWORD}');

        self::assertTrue($c->isPlaceholder);
        self::assertSame('${DB_PASSWORD}', $c->value);
    }
}
