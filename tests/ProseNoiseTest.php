<?php

declare(strict_types=1);

namespace Makeview\Tests;

use Makeview\Readme;
use PHPUnit\Framework\TestCase;

/**
 * Ordinary prose that entropy mistakes for a secret.
 *
 * A hyphenated word made of real words is vocabulary, not randomness, and
 * ShapeDetector already refuses those — but only when every part is letters.
 * A leading number defeated the check, so "10-stage sequential pipeline" put
 * `10-stage` on the dashboard as a token.
 */
final class ProseNoiseTest extends TestCase
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

    public function testHyphenatedCountIsNotASecret(): void
    {
        $markdown = "## Worker pipeline\n\n10-stage sequential pipeline per ticker, runs every 4 hours:\n";

        self::assertSame([], $this->values($markdown));
    }

    /** @return array<string, array{0: string}> */
    public static function prosePhrases(): array
    {
        return [
            'count prefix' => ['3-tier architecture keeps the layers apart.'],
            'version suffix' => ['Runs on postgres-16 in production.'],
            'measurement' => ['Allow 30-second timeouts for the worker.'],
            'ratio' => ['Uses a 2-of-3 quorum for writes.'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('prosePhrases')]
    public function testProseIsNotReportedAsASecret(string $sentence): void
    {
        self::assertSame([], $this->values("## Notes\n\n{$sentence}\n"));
    }

    /**
     * Tool and format names carry a trailing number as a matter of course.
     * Requiring every hyphen-separated part to be letters-only made
     * `ctype_alpha('mingw32')` false, so one digit inside a tool name put
     * `mingw32-make` on the dashboard as a token.
     *
     * @return array<string, array{0: string}>
     */
    public static function toolNames(): array
    {
        return [
            'mingw32-make' => ['mingw32-make'],
            'utf8-encode' => ['utf8-encode'],
            'base64-decode' => ['base64-decode'],
            'sha256-sum' => ['sha256-sum'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('toolNames')]
    public function testToolNameIsNotASecret(string $name): void
    {
        self::assertSame([], $this->values("## Notes\n\nRun `{$name}` to build.\n"));
    }

    /**
     * The counterpart: a generated secret interleaves digits through its
     * letters rather than trailing them, and must survive the rule above.
     *
     * @return array<string, array{0: string}>
     */
    public static function realSecrets(): array
    {
        return [
            'github token' => ['ghp_wWPw5L4Bd0aQ0LhLNq7HrjHXCzMoTeR1abcd'],
            'aws key' => ['AKIAIOSFODNN7EXAMPLE'],
            'mixed run' => ['k7Xq2r9fLmZt4wPd'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('realSecrets')]
    public function testGeneratedSecretIsStillFound(string $secret): void
    {
        self::assertSame([$secret], $this->values("## App\n\nToken: `{$secret}`\n"));
    }

    /** A real secret containing digits and a hyphen must still be found. */
    public function testHyphenatedSecretIsStillFound(): void
    {
        $markdown = "## App\n\nHeslo: `k7-Xq2-9fLm-Zt4w`\n";

        self::assertSame(['k7-Xq2-9fLm-Zt4w'], $this->values($markdown));
    }
}
