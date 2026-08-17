<?php

declare(strict_types=1);

namespace Makeview\Readme;

use Makeview\CredentialKeys;
use Makeview\CredentialPhrases;
use Makeview\Value\Credential;

/**
 * Credentials spread across list items.
 *
 * READMEs routinely write a label on one bullet and its value on the next,
 * nested one. A line-at-a-time scanner sees a label with no value and a value
 * with no label, and reports neither.
 */
final class ListDetector
{
    /**
     * Each credential is returned with the heading of the block it came from.
     * The caller groups by that heading, so a production secret can never be
     * emitted under a development link.
     *
     * @param Block[] $blocks
     * @return list<array{credential: Credential, heading: string}>
     */
    public static function detect(array $blocks): array
    {
        $items = array_values(array_filter($blocks, static fn (Block $b) => $b->type === 'list'));
        $found = [];

        foreach ($items as $index => $item) {
            // A bullet that states both halves is already complete.
            $inline = CredentialPhrases::inLine($item->text);
            if ($inline !== []) {
                foreach ($inline as $credential) {
                    $found[] = ['credential' => $credential, 'heading' => $item->heading];
                }
                continue;
            }

            $kind = CredentialPhrases::fuzzyKindFor(trim($item->text, " \t:*_`"));
            if ($kind === null) {
                continue;
            }

            $value = self::valueAfter($blocks, $items, $index, $item);
            if ($value === null) {
                continue;
            }

            // The bullet names the field and its nesting ties the value to it:
            // introduced by a word, and structured by the list itself.
            $evidence = ['introduced' => true, 'structured' => true];

            $found[] = [
                'credential' => new Credential(
                    $kind,
                    $kind === 'user' ? 'uživatel' : ($kind === 'token' ? 'token' : 'heslo'),
                    $value,
                    CredentialKeys::isPlaceholder($value),
                    Scorer::score($value, $evidence),
                    Scorer::explain($value, $evidence),
                ),
                'heading' => $item->heading,
            ];
        }

        return $found;
    }

    /**
     * The value belonging to a bare label. Two shapes carry it:
     *
     * - A nested bullet: the next list item, indented deeper than the label.
     *   Depth is `floor(leadingSpaces / 2)`, so it collapses unevenly-indented
     *   READMEs onto the same level as their parent — a strict "greater than"
     *   comparison is required, never an exact `depth + 1`.
     * - A continuation line: BlockParser has no notion of "inside a list
     *   item," so an indented line directly below a bullet, with no bullet
     *   marker of its own, comes back as a separate paragraph block at
     *   depth 0. It still belongs to the label immediately above it when its
     *   start line is the very next line after the label ends.
     *
     * @param Block[] $blocks All parsed blocks, to find a paragraph continuation.
     * @param list<Block> $items List blocks only, to find a nested bullet.
     */
    private static function valueAfter(array $blocks, array $items, int $index, Block $label): ?string
    {
        $next = $items[$index + 1] ?? null;
        if ($next !== null && $next->depth > $label->depth) {
            return self::asValue($next->text);
        }

        foreach ($blocks as $block) {
            if ($block->type === 'paragraph' && $block->startLine === $label->endLine + 1) {
                return self::asValue($block->text);
            }
        }

        return null;
    }

    /** Reject candidates that are noise, or another label rather than a value. */
    private static function asValue(string $text): ?string
    {
        $value = trim($text, " \t`\"'");

        if ($value === '' || CredentialKeys::isNoise($value)) {
            return null;
        }

        // A complete `user: admin` pair states its own label, so it is that
        // bullet's credential, not this bare label's value. Claiming it here
        // emitted a second, malformed credential whose value was the literal
        // text "user: admin" alongside the correctly parsed one.
        if (CredentialPhrases::inLine($value) !== []) {
            return null;
        }

        // Another label, not a value — the author listed two fields in a row.
        if (CredentialPhrases::fuzzyKindFor($value) !== null) {
            return null;
        }

        return $value;
    }
}
