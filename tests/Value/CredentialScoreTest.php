<?php

declare(strict_types=1);

namespace Makeview\Tests\Value;

use Makeview\Value\Credential;
use PHPUnit\Framework\TestCase;

final class CredentialScoreTest extends TestCase
{
    public function testDefaultsToFullScoreSoExistingCallersAreUnaffected(): void
    {
        $credential = new Credential('password', 'heslo', 'secret123', false);

        self::assertSame(1.0, $credential->score);
        self::assertSame('', $credential->evidence);
    }

    public function testCarriesScoreAndEvidenceWhenGiven(): void
    {
        $credential = new Credential('password', 'heslo', 'secret123', false, 0.4, 'uvozeno klíčovým slovem');

        self::assertSame(0.4, $credential->score);
        self::assertSame('uvozeno klíčovým slovem', $credential->evidence);
    }

    public function testIsUncertainBelowThreshold(): void
    {
        $weak = new Credential('password', 'heslo', 'secret123', false, 0.2, 'x');
        $strong = new Credential('password', 'heslo', 'secret123', false, 0.9, 'x');

        self::assertTrue($weak->isUncertain());
        self::assertFalse($strong->isUncertain());
    }
}
