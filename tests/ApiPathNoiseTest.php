<?php

declare(strict_types=1);

namespace Makeview\Tests;

use Makeview\Readme;
use PHPUnit\Framework\TestCase;

/**
 * A credential word followed by something that is plainly not a credential.
 *
 * API docs say "Login: `POST /api/v1/auth/login`" and "Refresh token: `POST
 * /api/v1/auth/refresh`". The word is genuine and the value is quoted, so the
 * vocabulary scan read both as stated credentials — a username of
 * "POST /api/v1/auth/login".
 *
 * The same shape covers a URL after "API key", which is where to *get* the
 * key rather than the key itself.
 */
final class ApiPathNoiseTest extends TestCase
{
    /** @return list<string> */
    private function values(string $markdown): array
    {
        $out = [];
        foreach (Readme::parse($markdown) as $link) {
            foreach ($link->credentials as $credential) {
                $out[] = $credential->value;
            }
        }

        return $out;
    }

    /** @return array<string, array{0: string}> */
    public static function apiPaths(): array
    {
        return [
            'login endpoint' => ['1. Login: `POST /api/v1/auth/login`'],
            'refresh endpoint' => ['4. Refresh token: `POST /api/v1/auth/refresh`'],
            'bare path' => ['Login: `/api/v1/auth/login`'],
            'method and path' => ['Account: `GET /users/me`'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('apiPaths')]
    public function testHttpEndpointIsNotACredential(string $line): void
    {
        self::assertSame([], $this->values("## API\n\n{$line}\n"));
    }

    /**
     * A URL after a credential word says where to obtain the credential, not
     * what it is.
     */
    public function testUrlAfterACredentialWordIsNotTheCredential(): void
    {
        $markdown = "## Config\n\n# USDA API (requires API key — https://fdc.nal.usda.gov/api-key-signup.html)\n";

        self::assertSame([], $this->values($markdown));
    }

    /** The word still works when a real value follows it. */
    public function testRealValueAfterTheSameWordIsStillRead(): void
    {
        $markdown = "## API\n\nAPI key: `sk-proj-Xq9r2LmZt4wPd8Nk3Hs7`\n";

        self::assertSame(['sk-proj-Xq9r2LmZt4wPd8Nk3Hs7'], $this->values($markdown));
    }

    public function testLoginNameAfterTheSameWordIsStillRead(): void
    {
        $markdown = "## API\n\nLogin: `admin@example.com`\n";

        self::assertSame(['admin@example.com'], $this->values($markdown));
    }
}
