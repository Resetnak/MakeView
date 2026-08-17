<?php

declare(strict_types=1);

namespace Makeview\Tests\Readme;

use Makeview\Readme\Entropy;
use PHPUnit\Framework\TestCase;

final class EntropyTest extends TestCase
{
    public function testEmptyValueHasZeroEntropy(): void
    {
        self::assertSame(0.0, Entropy::shannon(''));
    }

    public function testRepeatedCharacterHasZeroEntropy(): void
    {
        self::assertSame(0.0, Entropy::shannon('aaaaaaaa'));
    }

    public function testRandomSecretScoresHigherThanDictionaryWord(): void
    {
        self::assertGreaterThan(Entropy::shannon('admin'), Entropy::shannon('xK9$mP2vQz'));
    }

    public function testDictionaryWordIsNotHighEntropy(): void
    {
        self::assertFalse(Entropy::isHighEntropy('admin'));
        self::assertFalse(Entropy::isHighEntropy('password'));
    }

    public function testGeneratedSecretIsHighEntropy(): void
    {
        self::assertTrue(Entropy::isHighEntropy('xK9$mP2vQz7Lw'));
    }

    public function testShortRandomStringIsNotHighEntropyBecauseLengthIsEvidenceToo(): void
    {
        self::assertFalse(Entropy::isHighEntropy('xK9$'));
    }
}
