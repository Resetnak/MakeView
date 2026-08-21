<?php

declare(strict_types=1);

namespace Makeview\Tests;

use Makeview\Readme;
use PHPUnit\Framework\TestCase;

/**
 * `KEY=VALUE` outside a fenced block.
 *
 * Inside a fence, envCredentialIn() splits the assignment and lets
 * CredentialKeys decide whether the key names a secret. Outside one, no such
 * split happened: the shape scan saw `PG_USERS_HOST_PORT=15432` as a single
 * token, cleared the entropy bar on its digits and its `=`, and reported the
 * whole string — variable name included — as a token.
 *
 * That is the worst kind of false positive for this dashboard. It is not a
 * secret, it is not even a value, and a reader who sees a port number filed
 * under credentials stops trusting the rest of the report.
 */
final class EnvAssignmentTest extends TestCase
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

    public function testPortAssignmentIsNotACredential(): void
    {
        $values = $this->values("## DB\n\nPG_USERS_HOST_PORT=15432\n");

        self::assertSame([], $values);
    }

    public function testHostAssignmentIsNotACredential(): void
    {
        $values = $this->values("## DB\n\nPG_USERS_HOST=db.internal\n");

        self::assertSame([], $values);
    }

    /** The variable name is never part of the value, fence or no fence. */
    public function testUnfencedSecretAssignmentYieldsOnlyTheValue(): void
    {
        $values = $this->values("## DB\n\nPOSTGRES_PASSWORD=s3cr3t-value\n");

        self::assertSame(['s3cr3t-value'], $values);
    }

    /**
     * An assignment need not start its line. READMEs write them inside a
     * sentence ("Set FOO=bar before running") and after a bullet marker, and
     * anchoring the split at the start of the line let both forms fall through
     * to the entropy scan, which reported the whole `KEY=VALUE` string.
     */
    public function testAssignmentInsideASentenceIsNotACredential(): void
    {
        $values = $this->values("## Emulator\n\nSet ANDROID_SERIAL=emulator-5554 before running.\n");

        self::assertSame([], $values);
    }

    public function testAssignmentAfterABulletIsNotACredential(): void
    {
        $values = $this->values("## Emulator\n\n- ANDROID_SERIAL=emulator-5554\n");

        self::assertSame([], $values);
    }

    /** The same two positions must still yield a real secret. */
    public function testSecretAssignmentInsideASentenceIsRead(): void
    {
        $values = $this->values("## DB\n\nSet POSTGRES_PASSWORD=s3cr3t-value before running.\n");

        self::assertSame(['s3cr3t-value'], $values);
    }

    public function testUnfencedExportIsRead(): void
    {
        $values = $this->values("## API\n\nexport API_TOKEN=ghp_wWPw5L4Bd0aQ0LhLNq7HrjHXCzMoTeR1abcd\n");

        self::assertSame(['ghp_wWPw5L4Bd0aQ0LhLNq7HrjHXCzMoTeR1abcd'], $values);
    }
}
