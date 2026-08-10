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

    public function testDistinctUrlLessTableRowsInTheSameSectionAreNotDeduplicatedAway(): void
    {
        // Two rows with no URL column both fall back to a null url, but they are
        // still two distinct credentials (different services). Deduplicating on
        // url+context alone collapses them into one and silently drops a secret.
        $markdown = <<<'MD'
        ## Access

        | Service | User | Password |
        |---|---|---|
        | Argo | admin | argo-pass |

        | Service | User | Password |
        |---|---|---|
        | Grafana | viewer | graf-pass |
        MD;

        $links = Readme::parse($markdown);

        self::assertCount(2, $links);
        self::assertSame(['user' => 'admin', 'password' => 'argo-pass'], $this->credentialValues($links, 'Argo'));
        self::assertSame(['user' => 'viewer', 'password' => 'graf-pass'], $this->credentialValues($links, 'Grafana'));
    }

    // ---- definition lines + proximity ----

    public function testDefinitionLinesPairWithPrecedingLink(): void
    {
        $links = Readme::parse($this->fixture('readme-loose.md'));

        $dashboard = $this->byLabel($links, 'the dashboard');
        self::assertSame('http://localhost:3000', $dashboard->url);
        self::assertSame(['user' => 'admin', 'password' => 'dev-secret'], $this->credentialValues($links, 'the dashboard'));
    }

    public function testDefinitionLineCredentialsAreMarkedAsRead(): void
    {
        $markdown = "## Access\n\n[App](https://app.localhost)\n\nuser: admin\nheslo: p4ss\n";

        self::assertSame('definition', $this->byLabel(Readme::parse($markdown), 'App')->confidence);
    }

    public function testFencedEnvCredentialsAreMarkedAsRead(): void
    {
        $markdown = "## Access\n\n[App](https://app.localhost)\n\n```sh\nexport APP_PASSWORD=p4ss\n```\n";

        self::assertSame('env', $this->byLabel(Readme::parse($markdown), 'App')->confidence);
    }

    public function testMixedCredentialSourcesDegradeToProximity(): void
    {
        // Definition line and env export in one group: neither reading is more
        // trustworthy than a plain guess, so the pair must not claim either.
        $markdown = "## Access\n\n[App](https://app.localhost)\n\nuser: admin\n\n```sh\nexport APP_PASSWORD=p4ss\n```\n";

        self::assertSame('proximity', $this->byLabel(Readme::parse($markdown), 'App')->confidence);
    }

    public function testLinkWithoutCredentialsStaysProximity(): void
    {
        $markdown = "## Access\n\n[App](https://app.localhost)\n";

        self::assertSame('proximity', $this->byLabel(Readme::parse($markdown), 'App')->confidence);
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

    public function testCredentialOnTheSameLineAsTheLinkIsCaptured(): void
    {
        // A credential word does not have to start its own line — it can follow a
        // markdown link on the same line. Losing this pairing is a false negative:
        // a real secret sitting right next to its link would go unreported.
        $markdown = "## Access\n\n[App](https://app.local) heslo: inline-secret\n";

        self::assertSame(['password' => 'inline-secret'], $this->credentialValues(Readme::parse($markdown), 'App'));
    }

    // ---- the key test: no pairing across section boundaries ----

    public function testCredentialsNeverPairAcrossSectionBoundary(): void
    {
        $links = Readme::parse($this->fixture('readme-prod-and-dev.md'));

        self::assertSame(['user' => 'devadmin', 'password' => 'dev-only'], $this->credentialValues($links, 'Dev Argo'));
        self::assertSame(['user' => 'prodadmin', 'password' => 'prod-only'], $this->credentialValues($links, 'Prod Argo'));
    }

    public function testUnterminatedFenceDoesNotSwallowFollowingSections(): void
    {
        // A fence opened but never closed (running to EOF) must not suppress
        // heading detection for the rest of the document — otherwise a later
        // section's credentials silently merge into the earlier section's group,
        // and a production secret can end up attached to a development link.
        $markdown = <<<'MD'
        ## Development

        [Dev Argo](https://argo.dev.localhost)

        ```sh
        export DEV_PASSWORD=dev-only
        ## Production

        [Prod Argo](https://argo.example.com)

        export PROD_PASSWORD=prod-only
        MD;

        $links = Readme::parse($markdown);

        $devArgo = $this->byLabel($links, 'Dev Argo');
        $prodArgo = $this->byLabel($links, 'Prod Argo');

        self::assertSame('Development', $devArgo->context);
        self::assertSame('Production', $prodArgo->context);

        // The dev link must never carry the production credential, whatever the
        // dev link's own credentials end up being.
        foreach ($devArgo->credentials as $credential) {
            self::assertNotSame('prod-only', $credential->value);
        }

        // Nor may a link parsed out of the "Production" heading text carry the
        // dev-only credential.
        foreach ($prodArgo->credentials as $credential) {
            self::assertNotSame('dev-only', $credential->value);
        }
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

    public function testCommentLineInsideClosedFenceDoesNotSwallowLaterCredential(): void
    {
        $markdown = <<<'MD'
        ## Setup

        [Argo](https://argo.localhost)

        ```sh
        # this is a shell comment, not a heading
        export SETUP=1
        ```

        heslo: after-fence-secret
        MD;

        self::assertSame(
            ['password' => 'after-fence-secret'],
            $this->credentialValues(Readme::parse($markdown), 'Argo')
        );
    }

    public function testUrlInsideClosedFenceWithHashLineIsIgnored(): void
    {
        $markdown = <<<'MD'
        ## Setup

        ```sh
        # comment before the command
        curl https://example.com/install.sh | sh
        ```
        MD;

        self::assertSame([], Readme::parse($markdown));
    }

    public function testHashLineInsideFenceIsNotTreatedAsHeading(): void
    {
        $markdown = <<<'MD'
        ## Setup

        ```sh
        # not a real heading
        export SETUP=1
        ```

        ## Next

        [Next Link](https://next.localhost)
        MD;

        $links = Readme::parse($markdown);

        self::assertSame('Next', $this->byLabel($links, 'Next Link')->context);
        foreach ($links as $link) {
            self::assertNotSame('not a real heading', $link->context);
        }
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

    public function testSameUrlInDifferentSectionsKeepsEachSectionsCredentials(): void
    {
        // Dropping a duplicate link entirely is fine; silently keeping the WRONG
        // section's credentials for it is not — a dev section and a prod section
        // linking the same URL must not let one section's secret hide the other's.
        $markdown = <<<'MD'
        ## Development

        [App](https://app.local)

        heslo: dev-secret

        ## Production

        [App](https://app.local)

        heslo: prod-secret
        MD;

        $links = Readme::parse($markdown);

        $devLinks = array_values(array_filter($links, fn ($link) => $link->context === 'Development'));
        $prodLinks = array_values(array_filter($links, fn ($link) => $link->context === 'Production'));

        self::assertCount(1, $devLinks);
        self::assertCount(1, $prodLinks);
        self::assertSame(['password' => 'dev-secret'], $this->credentialValues($devLinks, 'App'));
        self::assertSame(['password' => 'prod-secret'], $this->credentialValues($prodLinks, 'App'));
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
