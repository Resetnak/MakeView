<?php

declare(strict_types=1);

namespace Makeview\Tests;

use Makeview\Readme;
use Makeview\Readme\Scorer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadmeIntegrationTest extends TestCase
{
    public function testFindsCredentialsInNestedBullets(): void
    {
        $links = Readme::parse("## Admin\n\n- Přihlašovací údaje:\n  - uživatel: admin\n  - heslo: tajneheslo123\n");

        $values = [];
        foreach ($links as $link) {
            foreach ($link->credentials as $credential) {
                $values[] = $credential->value;
            }
        }

        self::assertContains('admin', $values);
        self::assertContains('tajneheslo123', $values);
    }

    public function testFindsShapeIdentifiedSecretWithNoIntroducingWord(): void
    {
        $links = Readme::parse("## Deploy\n\nUse AKIAIOSFODNN7EXAMPLE to upload.\n");

        $values = [];
        foreach ($links as $link) {
            foreach ($link->credentials as $credential) {
                $values[] = $credential->value;
            }
        }

        self::assertContains('AKIAIOSFODNN7EXAMPLE', $values);
    }

    public function testFindsReferenceStyleLink(): void
    {
        $links = Readme::parse("## Docs\n\nSee [dashboard][db].\n\n[db]: https://example.com/dash\n");

        $urls = array_map(fn ($l) => $l->url, $links);

        self::assertContains('https://example.com/dash', $urls);
    }

    public function testFindsMidLineCredential(): void
    {
        $links = Readme::parse("## App\n\nheslo: admin123, port: 8080\n");

        $values = [];
        foreach ($links as $link) {
            foreach ($link->credentials as $credential) {
                $values[] = $credential->value;
            }
        }

        self::assertContains('admin123', $values);
    }

    public function testFindsLetterOnlyGeneratedSecretWithNoIntroducingWord(): void
    {
        $links = Readme::parse("## Upload\n\nUse xKmPvQzLwnR for upload.\n");

        $values = [];
        foreach ($links as $link) {
            foreach ($link->credentials as $credential) {
                $values[] = $credential->value;
            }
        }

        self::assertContains('xKmPvQzLwnR', $values);
    }

    public function testOrdinaryCapitalizedWordsInProseYieldNoCredential(): void
    {
        $links = Readme::parse(
            "## Overview\n\nDescription: this section covers Authentication for the "
            . "user-scoped API and its rotation policy.\n"
        );

        $values = [];
        foreach ($links as $link) {
            foreach ($link->credentials as $credential) {
                $values[] = $credential->value;
            }
        }

        self::assertNotContains('Description', $values);
        self::assertNotContains('Authentication', $values);
        self::assertNotContains('user-scoped', $values);
    }

    public function testCamelCaseIdentifiersInProseYieldNoCredential(): void
    {
        $links = Readme::parse(
            "## Internals\n\nCall getUserName to fetch the value. "
            . "Call parseHtmlResponse on the client. "
            . "The isPlaceholder check runs first.\n"
        );

        $values = [];
        foreach ($links as $link) {
            foreach ($link->credentials as $credential) {
                $values[] = $credential->value;
            }
        }

        self::assertNotContains('getUserName', $values);
        self::assertNotContains('parseHtmlResponse', $values);
        self::assertNotContains('isPlaceholder', $values);
    }

    public function testEnvVarNameMentionedInProseYieldsNoCredential(): void
    {
        $links = Readme::parse(
            "## Config\n\nThe POSTGRES_PASSWORD variable is user-scoped and read at startup.\n"
        );

        $values = [];
        foreach ($links as $link) {
            foreach ($link->credentials as $credential) {
                $values[] = $credential->value;
            }
        }

        self::assertNotContains('POSTGRES_PASSWORD', $values);
    }

    public function testFindsMixedGeneratedSecretWithNoIntroducingWord(): void
    {
        $links = Readme::parse("## Upload\n\nUse xK9\$mP2vQz7Lw!nR4 for upload.\n");

        $values = [];
        foreach ($links as $link) {
            foreach ($link->credentials as $credential) {
                $values[] = $credential->value;
            }
        }

        self::assertContains('xK9$mP2vQz7Lw!nR4', $values);
    }

    /** @return list<string> */
    private static function valuesIn(string $markdown): array
    {
        $values = [];
        foreach (Readme::parse($markdown) as $link) {
            foreach ($link->credentials as $credential) {
                $values[] = $credential->value;
            }
        }

        return $values;
    }

    /**
     * The reported value is what the dashboard's copy button hands the user. An
     * underscore stripped out of a token makes it useless for authentication
     * while still presenting as a working secret.
     */
    public function testGithubTokenKeepsItsUnderscore(): void
    {
        $values = self::valuesIn("## Deploy\n\nUse ghp_abcdefghijklmnopqrstuvwxyz0123456789ab to push.\n");

        self::assertContains('ghp_abcdefghijklmnopqrstuvwxyz0123456789ab', $values);
    }

    /** The underscore also has to survive as the shape that identifies it. */
    public function testGithubTokenIsReportedAsAGithubToken(): void
    {
        $links = Readme::parse("## Deploy\n\nUse ghp_abcdefghijklmnopqrstuvwxyz0123456789ab to push.\n");

        $labels = [];
        foreach ($links as $link) {
            foreach ($link->credentials as $credential) {
                $labels[] = $credential->label;
            }
        }

        self::assertContains('github_token', $labels);
    }

    /**
     * The load-bearing invariant: a production secret must never be shown under
     * a development heading. List credentials from the whole document were being
     * collapsed onto one link labelled with the first block's heading.
     */
    public function testListCredentialsStayUnderTheirOwnHeading(): void
    {
        $links = Readme::parse(
            "# Vývoj\n\n- Heslo:\n  - devSecret111\n\n# Produkce\n\n- Heslo:\n  - prodSecret999\n"
        );

        $byHeading = [];
        foreach ($links as $link) {
            foreach ($link->credentials as $credential) {
                $byHeading[$link->context][] = $credential->value;
            }
        }

        self::assertSame(['devSecret111'], $byHeading['Vývoj'] ?? []);
        self::assertSame(['prodSecret999'], $byHeading['Produkce'] ?? []);
    }

    public function testBareLabelListYieldsExactlyTheTwoStatedCredentials(): void
    {
        $values = self::valuesIn("## Access\n\n- Login:\n  - user: admin\n  - password: s3cr3tPass!\n");

        self::assertSame(['admin', 's3cr3tPass!'], $values);
    }

    /**
     * A bare shape match with no other evidence is a guess. Reporting it at
     * `confirmed` put ordinary prose behind the dashboard's "real secret"
     * widget, and left the uncertain branch unreachable in production.
     */
    public function testBareShapeMatchIsLikelyRatherThanConfirmed(): void
    {
        $links = Readme::parse("## Upload\n\nUse xKmPvQzLwnR for upload.\n");

        $scores = [];
        foreach ($links as $link) {
            foreach ($link->credentials as $credential) {
                $scores[$credential->value] = $credential->score;
            }
        }

        self::assertArrayHasKey('xKmPvQzLwnR', $scores);
        self::assertLessThan(Scorer::THRESHOLD_CONFIRMED, $scores['xKmPvQzLwnR']);
        self::assertGreaterThanOrEqual(Scorer::THRESHOLD_LIKELY, $scores['xKmPvQzLwnR']);
    }

    /**
     * Hosts, ports, timestamps and header names are the everyday furniture of a
     * README. None of them may reach the UI as a secret.
     *
     * @return list<array{0: string}>
     */
    public static function proseTokenProvider(): array
    {
        return [
            ['redis:6379'],
            ['localhost:3000'],
            ['example.com:443'],
            ['user@example.com'],
            ['2026-08-13T15:00:00Z'],
            ['Content-Type'],
            ['ERR_MODULE_NOT_FOUND'],
            ['make-view-dashboard'],
            ['POSTGRES_PASSWORD'],
            ['Description'],
            ['Authentication'],
            ['user-scoped'],
            ['getUserName'],
            ['parseHtmlResponse'],
            ['isPlaceholder'],
        ];
    }

    #[DataProvider('proseTokenProvider')]
    public function testOrdinaryProseTokenIsNeverReportedAsACredential(string $token): void
    {
        self::assertNotContains($token, self::valuesIn("## Notes\n\nSee $token in the guide.\n"));
    }
}
