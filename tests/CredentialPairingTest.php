<?php

declare(strict_types=1);

namespace Makeview\Tests;

use Makeview\Readme;
use Makeview\Value\Credential;
use PHPUnit\Framework\TestCase;

/**
 * Credentials come in pairs. A README states an account by giving a login and
 * the secret that opens it, and the two are only useful together — reporting
 * the password of an account whose username was dropped leaves the reader
 * unable to log in and unable to tell which account was meant.
 *
 * The old extractor searched for each value independently, so whenever a line
 * or a row held two credentials, one of them was silently lost.
 */
final class CredentialPairingTest extends TestCase
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

    /** @return array<string, string> kind => value, for one asserted pair */
    private function assertPair(string $markdown, string $user, string $secret): void
    {
        $values = array_map(
            static fn (Credential $c): string => $c->value,
            $this->credentials($markdown)
        );

        self::assertContains($user, $values, 'username missing from pair');
        self::assertContains($secret, $values, 'secret missing from pair');
    }

    public function testTableRowKeepsBothAccessAndSecretKey(): void
    {
        $markdown = <<<'MD'
            ## MinIO

            | Service | URL | Access Key | Secret Key |
            | --- | --- | --- | --- |
            | MinIO | http://localhost:9001 | minioadmin | miniopassword |
            MD;

        $this->assertPair($markdown, 'minioadmin', 'miniopassword');
    }

    public function testSentenceKeepsBothAccountAndPassword(): void
    {
        $markdown = "## Keycloak\n\nRealm admin account is `kc-admin` with password `KeyCloak#2024`.\n";

        $this->assertPair($markdown, 'kc-admin', 'KeyCloak#2024');
    }

    public function testLabelOnOneLineAndValueOnTheNextIsRead(): void
    {
        $markdown = "## Deploy\n\nUsername:\n    deploy_bot\nPassword:\n    d3pl0y-B0t-Key\n";

        $this->assertPair($markdown, 'deploy_bot', 'd3pl0y-B0t-Key');
    }

    public function testBasicAuthUrlYieldsBothHalves(): void
    {
        $markdown = "## Tools\n\nInterní nástroj: https://ops:Ops-Pass-99@tools.example.com/dashboard\n";

        $this->assertPair($markdown, 'ops', 'Ops-Pass-99');
    }

    public function testEmailAndPasswordInOneSentenceStayTogether(): void
    {
        $markdown = "## pgAdmin\n\nPřihlaste se emailem `admin@example.com` a heslem `pgadmin-pass-42`.\n";

        $this->assertPair($markdown, 'admin@example.com', 'pgadmin-pass-42');
    }

    /**
     * A basic-auth URL must never reach the dashboard's href with the secret
     * still in it: the browser would send it, and it would land in history and
     * in any proxy log along the way.
     */
    public function testBasicAuthCredentialsAreStrippedFromTheLinkUrl(): void
    {
        $markdown = "## Tools\n\nInterní nástroj: https://ops:Ops-Pass-99@tools.example.com/dashboard\n";

        foreach (Readme::parse($markdown) as $link) {
            self::assertStringNotContainsString('Ops-Pass-99', (string) $link->url);
        }
    }
}
