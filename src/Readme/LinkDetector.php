<?php

declare(strict_types=1);

namespace Makeview\Readme;

/**
 * Link shapes the inline-only extractor misses.
 *
 * Reference-style links put the address at the bottom of the file, far from the
 * text that uses it, so a line-local matcher sees a label with no target. Bare
 * `localhost:3000` has no scheme at all, yet it is how most READMEs state the
 * one address the reader most wants to click.
 */
final class LinkDetector
{
    /**
     * Hosts we will complete to a URL without a scheme. Restricted on purpose:
     * any `word:number` would otherwise match, and `PHP 8.3:1` is not an address.
     */
    private const SCHEMELESS_HOSTS = '(?:localhost|127\.0\.0\.1|0\.0\.0\.0|\[::1\])';

    /**
     * @param Block[] $blocks
     * @return list<array{label: string, url: string, heading: string}>
     */
    public static function detect(array $blocks): array
    {
        $definitions = self::referenceDefinitions($blocks);
        $links = [];

        foreach ($blocks as $block) {
            if ($block->type === 'reference') {
                continue;
            }

            $links = array_merge($links, self::referenceUsages($block, $definitions));
            $links = array_merge($links, self::bareHosts($block));
        }

        return $links;
    }

    /**
     * @param array<string, string> $definitions
     * @return list<array{label: string, url: string, heading: string}>
     */
    private static function referenceUsages(Block $block, array $definitions): array
    {
        $links = [];

        if (preg_match_all('/\[([^\]]+)\]\[([^\]]*)\]/', $block->text, $matches, PREG_SET_ORDER) === false) {
            return $links;
        }

        foreach ($matches as $match) {
            // An empty label reuses the text itself: [dashboard][].
            $key = mb_strtolower($match[2] !== '' ? $match[2] : $match[1]);
            if (!isset($definitions[$key])) {
                continue;
            }

            $links[] = ['label' => trim($match[1]), 'url' => $definitions[$key], 'heading' => $block->heading];
        }

        return $links;
    }

    /** @return list<array{label: string, url: string, heading: string}> */
    private static function bareHosts(Block $block): array
    {
        $links = [];

        $pattern = '/(?:^|[\s(])(' . self::SCHEMELESS_HOSTS . ':\d{2,5}(?:\/\S*)?)/u';
        if (preg_match_all($pattern, $block->text, $matches, PREG_SET_ORDER) === false) {
            return $links;
        }

        foreach ($matches as $match) {
            $host = rtrim($match[1], '.,;:');
            $links[] = ['label' => $host, 'url' => 'http://' . $host, 'heading' => $block->heading];
        }

        return $links;
    }

    /**
     * @param Block[] $blocks
     * @return array<string, string>
     */
    private static function referenceDefinitions(array $blocks): array
    {
        $definitions = [];

        foreach ($blocks as $block) {
            if ($block->type !== 'reference') {
                continue;
            }

            if (preg_match('/^\s*\[([^\]]+)\]:\s*<?(\S+?)>?\s*(?:"[^"]*")?\s*$/', $block->text, $m) !== 1) {
                continue;
            }

            if (preg_match('/^https?:\/\//i', $m[2]) !== 1) {
                continue;
            }

            $definitions[mb_strtolower($m[1])] = $m[2];
        }

        return $definitions;
    }
}
