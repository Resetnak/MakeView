<?php

declare(strict_types=1);

namespace Makeview\Readme;

use Makeview\CredentialKeys;
use Makeview\CredentialPhrases;
use Makeview\Value\Credential;

/**
 * A label alone on one line, its value on the next.
 *
 *     Username:
 *         deploy_bot
 *     Password:
 *         d3pl0y-B0t-Key
 *
 * This is a layout READMEs use for anything copied out of a terminal, and it
 * defeats every line-at-a-time scanner: the label line has no value on it and
 * the value line has no word introducing it, so both are discarded. It is not
 * a list either — there are no bullets — so ListDetector never sees it, and
 * BlockParser returns the whole run as a single paragraph.
 *
 * Only a bare label counts. A line that already states a complete pair is
 * left to the vocabulary scan, which reads it correctly on its own.
 */
final class AdjacentLineDetector
{
    /**
     * A value may not be arbitrarily far below its label: one line down is a
     * layout, five lines down is a coincidence.
     */
    private const MAX_GAP = 1;

    /**
     * @param Block[] $blocks
     * @return list<array{credential: Credential, heading: string}>
     */
    public static function detect(array $blocks): array
    {
        $found = [];

        foreach ($blocks as $block) {
            // Bulleted layouts belong to ListDetector, which understands their
            // nesting. Reading them here as well reported every list credential
            // a second time.
            if ($block->type !== 'paragraph') {
                continue;
            }

            foreach (self::inText($block->text) as $credential) {
                $found[] = ['credential' => $credential, 'heading' => $block->heading];
            }
        }

        return $found;
    }

    /** @return list<Credential> */
    private static function inText(string $text): array
    {
        $lines = preg_split('/\R/', $text) ?: [];
        $found = [];

        foreach ($lines as $i => $line) {
            $label = trim($line, " \t:*_`-");

            // A line carrying its own value is not a bare label; the ordinary
            // vocabulary scan handles it, and reading it here as well would
            // report the same credential twice.
            if ($label === '' || CredentialPhrases::inLine($line) !== []) {
                continue;
            }

            $kind = CredentialPhrases::fuzzyKindFor($label);
            if ($kind === null) {
                continue;
            }

            $value = self::valueBelow($lines, $i);
            if ($value === null) {
                continue;
            }

            // A word introduced it, but the pairing rests on layout alone —
            // the value is simply the line below. That is weaker than a table
            // column or a nested bullet, so no structural weight is claimed.
            $evidence = ['introduced' => true];

            $found[] = new Credential(
                $kind,
                $label,
                $value,
                CredentialKeys::isPlaceholder($value),
                Scorer::score($value, $evidence),
                Scorer::explain($value, $evidence),
            );
        }

        return $found;
    }

    /**
     * The first usable line below a label, within {@see MAX_GAP}. Another label
     * does not qualify: two labels in a row means the author listed the fields
     * first and the values after, and pairing them by position would be a
     * guess this class has no evidence for.
     *
     * @param list<string> $lines
     */
    private static function valueBelow(array $lines, int $labelIndex): ?string
    {
        for ($offset = 1; $offset <= self::MAX_GAP; $offset++) {
            $candidate = $lines[$labelIndex + $offset] ?? null;
            if ($candidate === null) {
                return null;
            }

            $value = trim($candidate, " \t`\"'*_");
            if ($value === '') {
                continue;
            }

            if (CredentialKeys::isNoise($value) || CredentialPhrases::fuzzyKindFor($value) !== null) {
                return null;
            }

            // A value stands alone on its line. Running prose below a label is
            // a sentence about the field, not the field's value.
            if (str_contains($value, ' ')) {
                return null;
            }

            return $value;
        }

        return null;
    }
}
