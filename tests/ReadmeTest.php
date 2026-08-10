<?php

declare(strict_types=1);

namespace Makeview\Tests;

use Makeview\Readme;
use Makeview\Value\Link;
use PHPUnit\Framework\TestCase;

final class ReadmeTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/' . $name);
    }

    /** @param Link[] $links */
    private function byLabel(array $links, string $label): Link
    {
        foreach ($links as $link) {
            if ($link->label === $label) {
                return $link;
            }
        }

        self::fail("Link {$label} not found");
    }

    /** @param Link[] $links */
    private function credentialValues(array $links, string $label): array
    {
        $out = [];
        foreach ($this->byLabel($links, $label)->credentials as $credential) {
            $out[$credential->kind] = $credential->value;
        }

        return $out;
    }

    // ---- table extractor ----

    public function testTableRowsBecomeLinksWithCredentials(): void
    {
        $links = Readme::parse($this->fixture('readme-table.md'));

        $argo = $this->byLabel($links, 'Argo CD');
        self::assertSame('https://argo.localhost', $argo->url);
        self::assertSame('table', $argo->confidence);
        self::assertSame('Přístupy', $argo->context);
        self::assertSame(['user' => 'admin', 'password' => 'argo-pass'], $this->credentialValues($links, 'Argo CD'));
    }

    public function testEveryTableRowProducesItsOwnLink(): void
    {
        $links = Readme::parse($this->fixture('readme-table.md'));

        self::assertSame(['user' => 'viewer', 'password' => 'graf-pass'], $this->credentialValues($links, 'Grafana'));
    }

    public function testTableWithoutCredentialColumnsIsNotTreatedAsCredentialTable(): void
    {
        $markdown = <<<'MD'
        ## Options

        | Flag | Description |
        |---|---|
        | --verbose | Print more |
        MD;

        self::assertSame([], Readme::parse($markdown));
    }

    // ---- definition lines + proximity ----

    public function testDefinitionLinesPairWithPrecedingLink(): void
    {
        $links = Readme::parse($this->fixture('readme-loose.md'));

        $dashboard = $this->byLabel($links, 'the dashboard');
        self::assertSame('http://localhost:3000', $dashboard->url);
        self::assertSame(['user' => 'admin', 'password' => 'dev-secret'], $this->credentialValues($links, 'the dashboard'));
    }

    public function testCzechAndEnglishCredentialKeywordsBothWork(): void
    {
        $markdown = "## Access\n\n[App](https://app.localhost)\n\nuživatel: karel\npassword: tajne\n";

        $links = Readme::parse($markdown);

        self::assertSame(['user' => 'karel', 'password' => 'tajne'], $this->credentialValues($links, 'App'));
    }

    public function testCredentialsSurviveMarkdownEmphasisAndBackticks(): void
    {
        $markdown = "## Access\n\n[App](https://app.localhost)\n\n**Username:** `admin`\n*Heslo:* `p4ss`\n";

        self::assertSame(['user' => 'admin', 'password' => 'p4ss'], $this->credentialValues(Readme::parse($markdown), 'App'));
    }

    public function testEmDashSeparatorIsSupported(): void
    {
        $markdown = "## Access\n\n[App](https://app.localhost)\n\nUsername — admin\n";

        self::assertSame(['user' => 'admin'], $this->credentialValues(Readme::parse($markdown), 'App'));
    }

    // ---- the key test: no pairing across section boundaries ----

    public function testCredentialsNeverPairAcrossSectionBoundary(): void
    {
        $links = Readme::parse($this->fixture('readme-prod-and-dev.md'));

        self::assertSame(['user' => 'devadmin', 'password' => 'dev-only'], $this->credentialValues($links, 'Dev Argo'));
        self::assertSame(['user' => 'prodadmin', 'password' => 'prod-only'], $this->credentialValues($links, 'Prod Argo'));
    }

    public function testCredentialWithNoPrecedingLinkAttachesToSection(): void
    {
        $markdown = "## Přístupy\n\nuser: admin\nheslo: secret\n";

        $links = Readme::parse($markdown);

        self::assertCount(1, $links);
        self::assertNull($links[0]->url);
        self::assertSame('Přístupy', $links[0]->label);
        self::assertSame('Přístupy', $links[0]->context);
        self::assertSame(['user' => 'admin', 'password' => 'secret'], $this->credentialValues($links, 'Přístupy'));
    }

    public function testCredentialAttachesToNearestPrecedingLinkNotTheFirst(): void
    {
        $markdown = <<<'MD'
        ## Access

        [First](https://first.localhost)

        [Second](https://second.localhost)

        heslo: belongs-to-second
        MD;

        $links = Readme::parse($markdown);

        self::assertSame([], $this->byLabel($links, 'First')->credentials);
        self::assertSame(['password' => 'belongs-to-second'], $this->credentialValues($links, 'Second'));
    }

    // ---- env exports in fenced blocks ----

    public function testFencedEnvExportsBecomeCredentials(): void
    {
        $markdown = <<<'MD'
        ## Setup

        [Argo](https://argo.localhost)

        ```bash
        export ARGOCD_PASSWORD=from-fence
        export UNRELATED=nope
        ```
        MD;

        self::assertSame(['password' => 'from-fence'], $this->credentialValues(Readme::parse($markdown), 'Argo'));
    }

    public function testUrlsInsideFencedBlocksAreIgnored(): void
    {
        $markdown = <<<'MD'
        ## Setup

        ```bash
        curl https://example.com/install.sh | sh
        ```
        MD;

        self::assertSame([], Readme::parse($markdown));
    }

    // ---- link extraction ----

    public function testBareUrlUsesHostnameAsLabel(): void
    {
        $links = Readme::parse("## Docs\n\nSee https://docs.example.com for details.\n");

        self::assertSame('docs.example.com', $links[0]->label);
        self::assertSame('https://docs.example.com', $links[0]->url);
    }

    public function testAngleBracketedUrlIsExtracted(): void
    {
        $links = Readme::parse("## Docs\n\nSee <https://docs.example.com> for details.\n");

        self::assertSame('https://docs.example.com', $links[0]->url);
    }

    public function testNonHttpSchemesAreIgnored(): void
    {
        $markdown = "## Contact\n\n[Mail](mailto:a@b.cz) and [Bad](javascript:alert(1))\n";

        self::assertSame([], Readme::parse($markdown));
    }

    public function testDuplicateUrlsAreDeduplicated(): void
    {
        $markdown = "## Access\n\n[App](https://app.localhost)\n\n[App again](https://app.localhost)\n";

        self::assertCount(1, Readme::parse($markdown));
    }

    // ---- noise filters ----

    public function testPlaceholderCredentialsAreFlaggedNotDiscarded(): void
    {
        $markdown = "## Access\n\n[App](https://app.localhost)\n\nheslo: changeme\n";

        $credentials = $this->byLabel(Readme::parse($markdown), 'App')->credentials;

        self::assertCount(1, $credentials);
        self::assertTrue($credentials[0]->isPlaceholder);
    }

    public function testProseIsNotMistakenForACredential(): void
    {
        $markdown = "## Access\n\n[App](https://app.localhost)\n\nPassword: ask the team lead for the current shared value\n";

        self::assertSame([], $this->byLabel(Readme::parse($markdown), 'App')->credentials);
    }

    public function testOverlongValuesAreDiscarded(): void
    {
        $markdown = "## Access\n\n[App](https://app.localhost)\n\nheslo: " . str_repeat('a', 201) . "\n";

        self::assertSame([], $this->byLabel(Readme::parse($markdown), 'App')->credentials);
    }

    // ---- degenerate input ----

    public function testEmptyReadmeProducesNoLinks(): void
    {
        self::assertSame([], Readme::parse(''));
    }

    public function testReadmeWithoutHeadingsStillExtracts(): void
    {
        $links = Readme::parse("[App](https://app.localhost)\n\nheslo: secret\n");

        self::assertCount(1, $links);
        self::assertSame(['password' => 'secret'], $this->credentialValues($links, 'App'));
    }
}
