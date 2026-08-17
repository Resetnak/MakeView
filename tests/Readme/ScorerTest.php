<?php

declare(strict_types=1);

namespace Makeview\Tests\Readme;

use Makeview\Readme\Scorer;
use PHPUnit\Framework\TestCase;

final class ScorerTest extends TestCase
{
    public function testStructuredAndIntroducedValueIsConfirmed(): void
    {
        $score = Scorer::score('tajneheslo123', ['introduced' => true, 'structured' => true, 'quoted' => true]);

        self::assertSame('confirmed', Scorer::confidenceFrom($score));
    }

    public function testShapeAloneIsEnoughWithoutAnyIntroducingWord(): void
    {
        $score = Scorer::score('AKIAIOSFODNN7EXAMPLE', []);

        self::assertNotSame('uncertain', Scorer::confidenceFrom($score));
    }

    public function testBareOrdinaryWordIsUncertain(): void
    {
        $score = Scorer::score('rotation', ['introduced' => true]);

        self::assertSame('uncertain', Scorer::confidenceFrom($score));
    }

    public function testScoreIsBounded(): void
    {
        $score = Scorer::score('xK9$mP2vQz7Lw', ['introduced' => true, 'structured' => true, 'quoted' => true, 'nearLink' => true]);

        self::assertLessThanOrEqual(1.0, $score);
        self::assertGreaterThanOrEqual(0.0, $score);
    }

    public function testExplanationNamesTheEvidence(): void
    {
        $reason = Scorer::explain('AKIAIOSFODNN7EXAMPLE', ['introduced' => true]);

        self::assertStringContainsString('aws_key', $reason);
    }
}
