<?php

declare(strict_types=1);

namespace Makeview\Tests;

use Makeview\Readme;
use Makeview\Value\Credential;
use PHPUnit\Framework\TestCase;

/**
 * A credential the dashboard reports must be usable as typed. A value that
 * arrives altered — an underscore dropped, a token truncated, a key name left
 * glued to its value — is worse than no finding at all: the reader copies it,
 * it fails, and the whole report loses its credibility.
 */
final class CredentialFidelityTest extends TestCase
{
    /** @return Credential[] */
    private function credentials(string $markdown): array
    {
        $out = [];
        foreach (Readme::parse($markdown) as $link) {
            foreach ($link->credentials as $credential) {
                $out[] = $credential;
            }
        }

        return $out;
    }

    private function findValue(string $markdown, string $value): Credential
    {
        foreach ($this->credentials($markdown) as $credential) {
            if ($credential->value === $value) {
                return $credential;
            }
        }

        $found = array_map(
            static fn (Credential $c): string => $c->kind . '=' . $c->value,
            $this->credentials($markdown)
        );

        self::fail("No credential valued {$value}. Found: " . implode(', ', $found));
    }

    // ---- values must survive extraction unaltered ----

    public function testUnderscoreSurvivesInAUsername(): void
    {
        $markdown = "## Grafana\n\nUživatel: `grafana_admin`\nHeslo: `Gr@fana-2024!`\n";

        $credential = $this->findValue($markdown, 'grafana_admin');

        self::assertSame('user', $credential->kind);
    }

    public function testUnderscoreSurvivesInAnUnquotedValue(): void
    {
        $markdown = "## App\n\nUsername: deploy_bot\nPassword: d3pl0y-B0t-Key\n";

        $credential = $this->findValue($markdown, 'deploy_bot');

        self::assertSame('user', $credential->kind);
    }

    public function testExportedEnvVariableSplitsKeyFromValue(): void
    {
        $markdown = "## API\n\nSet your key first:\n\n    export API_TOKEN=ghp_wWPw5L4Bd0aQ0LhLNq7HrjHXCzMoTeR1abcd\n";

        $credential = $this->findValue($markdown, 'ghp_wWPw5L4Bd0aQ0LhLNq7HrjHXCzMoTeR1abcd');

        self::assertSame('token', $credential->kind);
        self::assertStringNotContainsString('API_TOKEN', $credential->value);
    }

    public function testLongTokenIsNotTruncated(): void
    {
        $token = 'ghp_wWPw5L4Bd0aQ0LhLNq7HrjHXCzMoTeR1abcd';
        $markdown = "## API\n\nToken: `{$token}`\n";

        $credential = $this->findValue($markdown, $token);

        self::assertSame($token, $credential->value);
    }

    // ---- kind must reflect what the value actually is ----

    public function testPasswordWordWinsOverEntropyShape(): void
    {
        $markdown = "## Database\n\nThe password is `s3cr3t-db-pass` (change it in production).\n";

        $credential = $this->findValue($markdown, 's3cr3t-db-pass');

        self::assertSame('password', $credential->kind);
    }

    public function testAwsAccessKeyIsReportedAsAToken(): void
    {
        $markdown = "## S3\n\nAccess key: `AKIAIOSFODNN7EXAMPLE`\n";

        $credential = $this->findValue($markdown, 'AKIAIOSFODNN7EXAMPLE');

        self::assertSame('token', $credential->kind);
    }

    public function testEmailAddressIsReportedAsAUser(): void
    {
        $markdown = "## pgAdmin\n\nPřihlaste se emailem `admin@example.com` a heslem `pgadmin-pass-42`.\n";

        $credential = $this->findValue($markdown, 'admin@example.com');

        self::assertSame('user', $credential->kind);
    }

    public function testPasswordAlongsideAnEmailIsStillAPassword(): void
    {
        $markdown = "## pgAdmin\n\nPřihlaste se emailem `admin@example.com` a heslem `pgadmin-pass-42`.\n";

        $credential = $this->findValue($markdown, 'pgadmin-pass-42');

        self::assertSame('password', $credential->kind);
    }
}
