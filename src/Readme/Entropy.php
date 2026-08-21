<?php

declare(strict_types=1);

namespace Makeview\Readme;

/**
 * Shannon entropy over a candidate value. A generated secret spreads its
 * characters near-evenly and scores high; a word someone typed does not.
 *
 * This is what separates a real secret from an ordinary word that merely
 * happened to follow `password:`, which a stopword list can only ever chase
 * one word at a time.
 */
final class Entropy
{
    /** Bits per character at or above which a value looks generated. */
    public const THRESHOLD_BITS = 3.0;

    /**
     * Short values cannot be judged by entropy: `xK9$` scores high on four
     * characters while being far too short to be a generated secret. Length
     * is evidence in its own right, so both must hold.
     */
    public const MIN_LENGTH = 8;

    public static function shannon(string $value): float
    {
        $length = mb_strlen($value);
        if ($length === 0) {
            return 0.0;
        }

        $counts = [];
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($value, $i, 1);
            $counts[$char] = ($counts[$char] ?? 0) + 1;
        }

        $bits = 0.0;
        foreach ($counts as $count) {
            $probability = $count / $length;
            $bits -= $probability * log($probability, 2);
        }

        return $bits;
    }

    public static function isHighEntropy(string $value): bool
    {
        return mb_strlen($value) >= self::MIN_LENGTH
            && self::shannon($value) >= self::THRESHOLD_BITS;
    }
}
