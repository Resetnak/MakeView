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

        self::assertEmpty(Readme::parse($markdown));
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

        self::assertEmpty(Readme::parse($markdown));
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

        self::assertEmpty(Readme::parse($markdown));
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

        self::assertEmpty(Readme::parse($markdown));
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

    // ---- real-world credential vocabulary ----

    public function testEmailColumnActsAsTheUsername(): void
    {
        $markdown = <<<'MD'
        ### Testovací přihlašovací údaje

        | Email | Heslo | Role |
        |-------|-------|------|
        | `admin@automarket.cz` | `Admin1234!` | super_admin |
        MD;

        $links = Readme::parse($markdown);

        self::assertSame(
            ['user' => 'admin@automarket.cz', 'password' => 'Admin1234!'],
            $this->credentialValues($links, 'admin@automarket.cz'),
        );
    }

    public function testCredentialTableWithoutServiceOrUrlColumnStillYieldsRows(): void
    {
        $markdown = <<<'MD'
        #### Seed uživatelé

        | Email | Heslo | Role | Portál |
        |-------|-------|------|--------|
        | `dealer@automarket.cz` | `Dealer1234!` | dealer | dealer-portal |
        | `jan.novak@gmail.com` | `password123` | user | web-app |
        MD;

        $links = Readme::parse($markdown);

        // The Portál column names the service, so it wins the label over the
        // account; the account still survives as the user credential.
        self::assertCount(2, $links);
        self::assertSame(
            ['user' => 'jan.novak@gmail.com', 'password' => 'password123'],
            $this->credentialValues($links, 'web-app'),
        );
    }

    public function testBareUrlsOnLocalhostAreLabelledWithPortNotJustHost(): void
    {
        $markdown = "## Příkazy\n\nhttp://localhost:4200 a http://localhost:4201\n";

        $labels = array_map(fn ($link) => $link->label, Readme::parse($markdown));

        self::assertSame(['localhost:4200', 'localhost:4201'], $labels);
    }

    public function testBadgeImagesAreNotListedAsLinks(): void
    {
        $markdown = "# Project\n\n[![CI](https://ci.example.com/badge.svg)](https://ci.example.com/runs)\n";

        $labels = array_map(fn ($link) => $link->label, Readme::parse($markdown));

        self::assertNotContains('!CI', $labels);
        self::assertNotContains('![CI', $labels);
    }

    public function testEnglishAndBoldLabelledDefinitionsAreRead(): void
    {
        $markdown = <<<'MD'
        ## Access

        [Grafana](https://grafana.localhost)

        - **Username**: admin
        - **Password**: grafana-secret
        MD;

        self::assertSame(
            ['user' => 'admin', 'password' => 'grafana-secret'],
            $this->credentialValues(Readme::parse($markdown), 'Grafana'),
        );
    }

    // ---- inline pairs in prose ----

    /**
     * The commonest way a README states a login is one sentence, not a table:
     * "user X, password Y". A trailing remark after the value is normal, so the
     * value must be read as a token rather than "everything to end of line".
     */
    public function testInlinePairOnOneProseLineIsRead(): void
    {
        $markdown = <<<'MD'
        ## Keycloak

        - Admin: uživatel `admin`, heslo `s3cret` (viz docker-compose).
        MD;

        self::assertSame(
            ['user' => 'admin', 'password' => 's3cret'],
            $this->credentialValues(Readme::parse($markdown), 'Keycloak'),
        );
    }

    public function testValueIsNotSwallowedTogetherWithTheRestOfTheSentence(): void
    {
        $markdown = <<<'MD'
        ## App

        Password: hunter2 (change this before deploying).
        MD;

        self::assertSame(
            ['password' => 'hunter2'],
            $this->credentialValues(Readme::parse($markdown), 'App'),
        );
    }

    /** A quoted value may contain spaces; only unquoted values must not. */
    public function testQuotedValueMayContainSpaces(): void
    {
        $markdown = <<<'MD'
        ## App

        Password: "correct horse battery" and then some prose.
        MD;

        self::assertSame(
            ['password' => 'correct horse battery'],
            $this->credentialValues(Readme::parse($markdown), 'App'),
        );
    }

    public function testDescriptivePhrasePrecedingTheValueIsRead(): void
    {
        $markdown = <<<'MD'
        ## Monitoring

        Grafana runs on <http://localhost:3000> (default password `admin`).
        MD;

        self::assertSame(
            ['password' => 'admin'],
            $this->credentialValues(Readme::parse($markdown), 'localhost:3000'),
        );
    }

    /** German and Polish READMEs state the same thing with their own words. */
    public function testGermanAndPolishLabelsAreRead(): void
    {
        $markdown = <<<'MD'
        ## Zugang

        Benutzername: `admin`
        Kennwort: `geheim`
        MD;

        self::assertSame(
            ['user' => 'admin', 'password' => 'geheim'],
            $this->credentialValues(Readme::parse($markdown), 'Zugang'),
        );
    }

    public function testSlashSeparatedPairWithoutLabelsIsRead(): void
    {
        $markdown = <<<'MD'
        ## Local dev

        Sign in with `admin@local.test` / `supersetdev`.
        MD;

        self::assertSame(
            ['user' => 'admin@local.test', 'password' => 'supersetdev'],
            $this->credentialValues(Readme::parse($markdown), 'Local dev'),
        );
    }

    // ---- fenced blocks ----

    /**
     * A plain fence is where people paste the test account. The same
     * `label: value` lines that are read outside a fence must be read inside
     * one too — before this, only KEY=value was.
     */
    public function testDefinitionLinesInsideAPlainFenceAreRead(): void
    {
        $markdown = <<<'MD'
        ## Testovací uživatel

        ```
        Email: admin@test.com
        Heslo: Admin123!
        ```
        MD;

        self::assertSame(
            ['user' => 'admin@test.com', 'password' => 'Admin123!'],
            $this->credentialValues(Readme::parse($markdown), 'Testovací uživatel'),
        );
    }

    public function testNestedYamlKeysInsideAFenceAreRead(): void
    {
        $markdown = <<<'MD'
        ## Konfigurace

        ```yaml
        database:
          user: postgres
          password: postgres
        ```
        MD;

        self::assertSame(
            ['user' => 'postgres', 'password' => 'postgres'],
            $this->credentialValues(Readme::parse($markdown), 'Konfigurace'),
        );
    }

    /**
     * A `curl` login example states an account even though nothing on the line
     * looks like a credential line. The URL inside the fence stays a command
     * argument rather than becoming a link, so the account is filed under the
     * heading it was documented beneath.
     */
    public function testJsonPayloadInsideAFenceIsRead(): void
    {
        $markdown = <<<'MD'
        ## Login

        ```bash
        curl -X POST http://localhost:8090/v1/auth/login \
          -d '{"email":"test@example.com","password":"Test1234!"}'
        ```
        MD;

        self::assertSame(
            ['user' => 'test@example.com', 'password' => 'Test1234!'],
            $this->credentialValues(Readme::parse($markdown), 'Login'),
        );
    }

    // ---- table orientation ----

    /**
     * A two-column table often puts the roles in the first column rather than
     * the header: `| Field | Value |` over rows `Email` / `Password`. Read
     * down the first column when the header itself names no role.
     */
    public function testTwoColumnFieldValueTableIsReadByItsFirstColumn(): void
    {
        $markdown = <<<'MD'
        ## Test account

        | Field    | Value                  |
        |----------|------------------------|
        | Email    | `test@example.com`     |
        | Password | `Test1234!`            |
        MD;

        self::assertSame(
            ['user' => 'test@example.com', 'password' => 'Test1234!'],
            $this->credentialValues(Readme::parse($markdown), 'Test account'),
        );
    }

    // ---- basic-auth URLs ----

    /**
     * Credentials embedded in a URL are credentials. They must also be stripped
     * from the href: the dashboard renders the URL as a clickable link, and a
     * password does not belong in an anchor a click can leak to a referrer.
     */
    public function testBasicAuthCredentialsAreExtractedAndStrippedFromTheUrl(): void
    {
        $markdown = <<<'MD'
        ## Admin

        Otevři http://admin:secret123@localhost:8080/panel
        MD;

        $links = Readme::parse($markdown);
        $link = $this->byLabel($links, 'localhost:8080/panel');

        self::assertSame('http://localhost:8080/panel', $link->url);
        self::assertSame(
            ['user' => 'admin', 'password' => 'secret123'],
            $this->credentialValues($links, 'localhost:8080/panel'),
        );
    }

    // ---- noise resistance ----

    /**
     * Broadening detection must not turn ordinary prose into credentials.
     * These lines all contain a credential word and no secret whatsoever.
     */
    public function testProseMentioningCredentialWordsYieldsNothing(): void
    {
        $markdown = <<<'MD'
        ## Notes

        Run the update for user-scoped plugins only.
        The API is protected by a secret delivered via an HTTP-only cookie.
        See the section about password rotation for details.
        Authentication uses JWT access tokens and refresh tokens.
        MD;

        self::assertEmpty(Readme::parse($markdown));
    }

    public function testEnvVarDocumentationTableIsNotReadAsCredentials(): void
    {
        $markdown = <<<'MD'
        ## Konfigurace

        | Proměnná | Popis |
        |----------|-------|
        | `JWT_SECRET` | Secret pro JWT podpis (min 32 chars) |
        | `API_KEY` | Plaintext API key (hashed at startup) |
        MD;

        self::assertEmpty(Readme::parse($markdown));
    }

    public function testShellCommandThatOnlyNamesAVariableYieldsNothing(): void
    {
        $markdown = <<<'MD'
        ## Setup

        ```bash
        echo $OPENAI_API_KEY
        openssl passwd -apr1
        ```
        MD;

        self::assertEmpty(Readme::parse($markdown));
    }

    /**
     * A setup snippet exports a placeholder rather than a real secret. The
     * value is still reported — placeholders are flagged, not dropped, so that
     * a README documenting `changeme` is visibly documenting `changeme` — but
     * it must be marked as one.
     */
    public function testPlaceholderSecretsInASetupSnippetAreFlagged(): void
    {
        $markdown = <<<'MD'
        ## Setup

        ```bash
        export OPENAI_API_KEY=sk-your-openai-api-key
        ```
        MD;

        $credentials = $this->byLabel(Readme::parse($markdown), 'Setup')->credentials;

        self::assertCount(1, $credentials);
        self::assertSame('sk-your-openai-api-key', $credentials[0]->value);
        self::assertTrue($credentials[0]->isPlaceholder);
    }

    // ---- env tables ----

    /**
     * The most common way to document configuration is a table of variables
     * with their defaults. The variable names are exactly what CredentialKeys
     * already understands, so the rows that name a credential state one — with
     * the default column as its value.
     */
    public function testEnvTableWithDefaultsYieldsTheCredentialRows(): void
    {
        $markdown = <<<'MD'
        ## Konfigurace

        | Variable | Default | Description |
        |----------|---------|-------------|
        | `APP_PORT` | `8080` | HTTP port |
        | `DB_USER` | `postgres` | Database user |
        | `DB_PASSWORD` | `devpass` | Database password |
        MD;

        self::assertSame(
            ['user' => 'postgres', 'password' => 'devpass'],
            $this->credentialValues(Readme::parse($markdown), 'Konfigurace'),
        );
    }

    /**
     * The same table without a value column documents what a variable means,
     * not what it is set to. Nothing there is a credential.
     */
    public function testEnvTableWithoutAValueColumnStaysSilent(): void
    {
        $markdown = <<<'MD'
        ## Konfigurace

        | Proměnná | Popis |
        |----------|-------|
        | `DB_PASSWORD` | Heslo k databázi |
        MD;

        self::assertEmpty(Readme::parse($markdown));
    }

    /**
     * The variable-column words are matched as whole cells. A header reading
     * `API Key` still names a secret, not a configuration key, and the
     * credential table it belongs to must keep working.
     */
    public function testKeyInsideALongerHeaderStillNamesACredentialColumn(): void
    {
        $markdown = <<<'MD'
        ## Služby

        | Služba | URL | API Key |
        |--------|-----|---------|
        | Argo | https://argo.localhost | argo-token-123 |
        MD;

        // The header names the secret, so it decides the kind: an `API Key`
        // column holds tokens. It previously came back as a password, because
        // every secret column was typed the same way regardless of its name.
        self::assertSame(
            ['token' => 'argo-token-123'],
            $this->credentialValues(Readme::parse($markdown), 'Argo'),
        );
    }

    // ---- slash pairs beyond backticks ----

    /**
     * Bold is as common as backticks for marking a literal, and the pair is
     * just as unambiguous when an introducing word announces it.
     */
    public function testBoldSlashSeparatedPairIsRead(): void
    {
        $markdown = <<<'MD'
        ## Staging

        http://staging.local — default credentials **admin** / **secret123**
        MD;

        self::assertSame(
            ['user' => 'admin', 'password' => 'secret123'],
            $this->credentialValues(Readme::parse($markdown), 'staging.local'),
        );
    }

    /**
     * A blockquote is a formatting choice, not a different kind of statement.
     */
    public function testSlashSeparatedPairInsideABlockquoteIsRead(): void
    {
        $markdown = <<<'MD'
        ## QA

        http://qa.local

        > Credentials: `qa` / `qa-2024`
        MD;

        self::assertSame(
            ['user' => 'qa', 'password' => 'qa-2024'],
            $this->credentialValues(Readme::parse($markdown), 'qa.local'),
        );
    }

    /**
     * Two paths separated by a slash are still not a login, with or without
     * markup around them.
     */
    public function testUnintroducedBoldPairIsNotACredential(): void
    {
        $markdown = <<<'MD'
        ## Build

        Sources live in **src** / **tests**.
        MD;

        self::assertEmpty(Readme::parse($markdown));
    }
}
