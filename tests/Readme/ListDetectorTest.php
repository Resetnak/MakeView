<?php

declare(strict_types=1);

namespace Makeview\Tests\Readme;

use Makeview\Readme\BlockParser;
use Makeview\Readme\ListDetector;
use PHPUnit\Framework\TestCase;

final class ListDetectorTest extends TestCase
{
    public function testReadsCredentialsFromNestedBullets(): void
    {
        $blocks = BlockParser::parse(file_get_contents(__DIR__ . '/../fixtures/nested-credentials.md'));
        $credentials = ListDetector::detect($blocks);

        $values = array_map(fn ($f) => $f['credential']->value, $credentials);

        self::assertContains('admin', $values);
        self::assertContains('tajneheslo123', $values);
    }

    public function testReadsAValueFromTheLineBelowItsLabel(): void
    {
        $blocks = BlockParser::parse("- Credentials\n  - password\n    demoPass456\n");
        $credentials = ListDetector::detect($blocks);

        self::assertCount(1, $credentials);
        self::assertSame('password', $credentials[0]['credential']->kind);
        self::assertSame('demoPass456', $credentials[0]['credential']->value);
    }

    public function testPlainListWithoutCredentialWordsYieldsNothing(): void
    {
        $blocks = BlockParser::parse("- src\n- tests\n- docs\n");

        self::assertSame([], ListDetector::detect($blocks));
    }

    /**
     * The spec's motivating shape. `- Login:` is a bare label, and the bullet
     * under it is a complete `user: admin` pair — not a bare value. Treating it
     * as the bare label's value produced a third credential whose value was the
     * literal text "user: admin".
     */
    public function testBareLabelAboveCompletePairsYieldsOnlyThosePairs(): void
    {
        $blocks = BlockParser::parse("- Login:\n  - user: admin\n  - password: s3cr3tPass!\n");
        $found = ListDetector::detect($blocks);

        self::assertCount(2, $found);

        $values = array_map(fn ($f) => $f['credential']->value, $found);
        self::assertSame(['admin', 's3cr3tPass!'], $values);
    }

    /** Each credential must report the heading of the block it came from. */
    public function testCredentialCarriesTheHeadingItWasFoundUnder(): void
    {
        $blocks = BlockParser::parse(
            "# Vývoj\n\n- Heslo:\n  - devSecret111\n\n# Produkce\n\n- Heslo:\n  - prodSecret999\n"
        );

        $byValue = [];
        foreach (ListDetector::detect($blocks) as $found) {
            $byValue[$found['credential']->value] = $found['heading'];
        }

        self::assertSame('Vývoj', $byValue['devSecret111'] ?? null);
        self::assertSame('Produkce', $byValue['prodSecret999'] ?? null);
    }
}
