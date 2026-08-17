<?php

declare(strict_types=1);

namespace Makeview\Tests;

use Makeview\Readme;
use Makeview\Value\Credential;
use PHPUnit\Framework\TestCase;

/**
 * Credentials stated as a command to run.
 *
 * A README's fenced blocks are where most access details actually live: the
 * author pastes the `psql` line that works, or the `curl` that authenticates,
 * rather than writing "username:" above it. The vocabulary scan cannot see
 * these — there is no introducing word, only a flag — so an entire class of
 * credential went unreported.
 */
final class CommandLineCredentialTest extends TestCase
{
    /** @return array<string, string> value => kind */
    private function credentials(string $markdown): array
    {
        $out = [];
        foreach (Readme::parse($markdown) as $link) {
            foreach ($link->credentials as $credential) {
                $out[$credential->value] = $credential->kind;
            }
        }

        return $out;
    }

    private function fenced(string $command): string
    {
        return "## Database\n\n```\n{$command}\n```\n";
    }

    public function testPsqlUserFlagNamesAUser(): void
    {
        $found = $this->credentials($this->fenced('psql -h db.internal -U appuser -d appdb'));

        self::assertSame('user', $found['appuser'] ?? null);
    }

    public function testMysqlShortFlagsNameBothHalves(): void
    {
        $found = $this->credentials($this->fenced('mysql -u root -pSecretPass123 mydb'));

        self::assertSame('user', $found['root'] ?? null);
        self::assertSame('password', $found['SecretPass123'] ?? null);
    }

    public function testCurlUserFlagSplitsTheColonPair(): void
    {
        $found = $this->credentials($this->fenced('curl -u admin:hunter2 https://api.example.com/v1/status'));

        self::assertSame('user', $found['admin'] ?? null);
        self::assertSame('password', $found['hunter2'] ?? null);
    }

    public function testSshUserAtHostNamesAUser(): void
    {
        $found = $this->credentials($this->fenced('ssh deploy@app.example.com'));

        self::assertSame('user', $found['deploy'] ?? null);
    }

    public function testLongFormFlagsAreRead(): void
    {
        $found = $this->credentials($this->fenced('mysqldump --user=backup --password=B@ckup-2024 mydb'));

        self::assertSame('user', $found['backup'] ?? null);
        self::assertSame('password', $found['B@ckup-2024'] ?? null);
    }

    /**
     * A flag with no value attached takes the next argument. `-p` alone is the
     * interactive form — it prompts — and must not swallow the database name
     * that follows it as a password.
     */
    public function testBareMysqlPasswordFlagIsNotAValue(): void
    {
        $found = $this->credentials($this->fenced('mysql -u root -p mydb'));

        self::assertSame('user', $found['root'] ?? null);
        self::assertArrayNotHasKey('mydb', $found);
    }

    /** A host is not a credential, however it is spelled. */
    public function testHostFlagIsNotReported(): void
    {
        $found = $this->credentials($this->fenced('psql -h db.internal -p 5432 -d appdb'));

        self::assertArrayNotHasKey('db.internal', $found);
        self::assertArrayNotHasKey('5432', $found);
        self::assertArrayNotHasKey('appdb', $found);
    }

    /** A placeholder is reported, but marked, so nobody pastes `<password>`. */
    public function testPlaceholderValueIsMarked(): void
    {
        $credentials = [];
        foreach (Readme::parse($this->fenced('mysql -u root -pYOUR_PASSWORD mydb')) as $link) {
            foreach ($link->credentials as $credential) {
                $credentials[$credential->value] = $credential;
            }
        }

        $found = $credentials['YOUR_PASSWORD'] ?? null;

        self::assertInstanceOf(Credential::class, $found);
        self::assertTrue($found->isPlaceholder);
    }
}
