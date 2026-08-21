<?php

declare(strict_types=1);

namespace Makeview\Readme;

/**
 * Combine detection signals into one score with a stated reason.
 *
 * A binary verdict has to be bought with a stopword for every false positive
 * and a loosened rule for every false negative, and those two moves fight each
 * other. A score lets a weak finding be shown as weak instead of dropped, which
 * is the behaviour a security dashboard needs: hiding a real password is worse
 * than showing an uncertain one.
 */
final class Scorer
{
    /**
     * A value whose format identifies it — a JWT, an `AKIA…` key — proves its
     * own nature outright, so this alone clears THRESHOLD_CONFIRMED. A bare
     * high-entropy run is weaker and is scored by WEIGHT_ENTROPY instead.
     */
    private const WEIGHT_SHAPE = 0.65;

    /**
     * Randomness is a hint, not a fact: a generated password, an API key and a
     * base64 blob of test data all clear the same bits/char bar. It must not
     * outweigh what the author actually wrote, which is why an entropy match
     * scores well below a named shape.
     */
    private const WEIGHT_ENTROPY = 0.35;

    /**
     * Deliberately not enough on its own. "password rotation" introduces the
     * word `rotation` exactly as `password: hunter2` introduces the secret, so
     * a word alone must stay below THRESHOLD_CONFIRMED and wait for a second
     * signal — quoting, a table column, a recognisable shape.
     */
    private const WEIGHT_INTRODUCED = 0.25;

    /**
     * A table column, a nested bullet or a command flag ties a value to its
     * role by the document's own grammar rather than by proximity. Together
     * with the introducing word that names the role, this is what a stated
     * credential looks like, and the pair must clear THRESHOLD_CONFIRMED:
     * a credential table is the most reliable source a README has.
     */
    private const WEIGHT_STRUCTURED = 0.35;

    private const WEIGHT_QUOTED = 0.10;
    private const WEIGHT_NEAR_LINK = 0.05;

    public const THRESHOLD_CONFIRMED = 0.60;
    public const THRESHOLD_LIKELY = 0.35;

    /** @param array<string, bool> $evidence */
    public static function score(string $value, array $evidence): float
    {
        $score = 0.0;

        $shape = ShapeDetector::detect($value);
        if ($shape !== null) {
            $score += $shape === 'high_entropy' ? self::WEIGHT_ENTROPY : self::WEIGHT_SHAPE;
        }

        foreach ([
            'introduced' => self::WEIGHT_INTRODUCED,
            'structured' => self::WEIGHT_STRUCTURED,
            'quoted' => self::WEIGHT_QUOTED,
            'nearLink' => self::WEIGHT_NEAR_LINK,
        ] as $key => $weight) {
            if ($evidence[$key] ?? false) {
                $score += $weight;
            }
        }

        return min(1.0, $score);
    }

    public static function confidenceFrom(float $score): string
    {
        if ($score >= self::THRESHOLD_CONFIRMED) {
            return 'confirmed';
        }

        return $score >= self::THRESHOLD_LIKELY ? 'likely' : 'uncertain';
    }

    /** @param array<string, bool> $evidence */
    public static function explain(string $value, array $evidence): string
    {
        $reasons = [];

        $shape = ShapeDetector::detect($value);
        if ($shape !== null) {
            $reasons[] = $shape;
        }

        foreach ([
            'introduced' => 'uvozeno klíčovým slovem',
            'structured' => 'strukturovaný zápis',
            'quoted' => 'hodnota v uvozovkách',
            'nearLink' => 'poblíž odkazu',
        ] as $key => $label) {
            if ($evidence[$key] ?? false) {
                $reasons[] = $label;
            }
        }

        return $reasons === [] ? 'bez průkazných signálů' : implode(', ', $reasons);
    }
}
