<?php

declare(strict_types=1);

namespace Makeview\Readme;

/**
 * Split a README once into typed blocks.
 *
 * The old parser split on lines and rebuilt structure with regexes at every
 * call site, which is why a value on the line after its label could never be
 * found. Parsing once, with line ranges, makes that a solved problem for every
 * detector instead of an open one for each.
 */
final class BlockParser
{
    /** @return Block[] */
    public static function parse(string $markdown): array
    {
        $lines = preg_split('/\R/', $markdown) ?: [];
        $closedFences = self::closedFenceRanges($lines);

        $blocks = [];
        $heading = '';
        $paragraph = [];
        $paragraphStart = 0;

        $flush = function () use (&$blocks, &$paragraph, &$paragraphStart, &$heading): void {
            if ($paragraph === []) {
                return;
            }
            $blocks[] = new Block('paragraph', implode("\n", $paragraph), $paragraphStart, $paragraphStart + count($paragraph) - 1, 0, $heading);
            $paragraph = [];
        };

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            $lineNumber = $i + 1;

            if (isset($closedFences[$i])) {
                $flush();
                $end = $closedFences[$i];
                $body = array_slice($lines, $i + 1, $end - $i - 1);
                $blocks[] = new Block('fence', implode("\n", $body), $lineNumber, $end + 1, 0, $heading);
                $i = $end;
                continue;
            }

            if (preg_match('/^#{1,6}\s+(.+?)\s*#*\s*$/', $line, $m) === 1) {
                $flush();
                $heading = trim($m[1]);
                $blocks[] = new Block('heading', $heading, $lineNumber, $lineNumber, 0, $heading);
                continue;
            }

            if (preg_match('/^(\s*)[-*+]\s+(.*)$/', $line, $m) === 1) {
                $flush();
                $depth = (int) floor(mb_strlen($m[1]) / 2);
                $blocks[] = new Block('list', $m[2], $lineNumber, $lineNumber, $depth, $heading);
                continue;
            }

            if (preg_match('/^\s*\[([^\]]+)\]:\s*(\S+)/', $line) === 1) {
                $flush();
                $blocks[] = new Block('reference', trim($line), $lineNumber, $lineNumber, 0, $heading);
                continue;
            }

            if (str_contains($line, '|') && isset($lines[$i + 1]) && preg_match('/^\s*\|?[\s:|-]+\|[\s:|-]*$/', $lines[$i + 1]) === 1) {
                $flush();
                $start = $i;
                $row = $i;
                while ($row < count($lines) && str_contains($lines[$row], '|')) {
                    $row++;
                }
                $blocks[] = new Block('table', implode("\n", array_slice($lines, $start, $row - $start)), $start + 1, $row, 0, $heading);
                $i = $row - 1;
                continue;
            }

            if (trim($line) === '') {
                $flush();
                continue;
            }

            if ($paragraph === []) {
                $paragraphStart = $lineNumber;
            }
            $paragraph[] = $line;
        }

        $flush();

        return $blocks;
    }

    /**
     * Map each fence opener to the line that closes it, per CommonMark: only a
     * same-or-longer marker of the same character closes.
     *
     * An opener with no closer is left out entirely. Treating it as open to the
     * end of file would let one stray backtick hide every credential below it,
     * which is worse than reading a little fence content as prose.
     *
     * @param list<string> $lines
     * @return array<int, int> opener index => closer index
     */
    private static function closedFenceRanges(array $lines): array
    {
        $ranges = [];
        $openIndex = null;
        $openChar = '';
        $openLength = 0;

        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(`{3,}|~{3,})/', $line, $m) !== 1) {
                continue;
            }

            $char = $m[1][0];
            $length = strlen($m[1]);

            if ($openIndex === null) {
                $openIndex = $i;
                $openChar = $char;
                $openLength = $length;
                continue;
            }

            if ($char === $openChar && $length >= $openLength) {
                $ranges[$openIndex] = $i;
                $openIndex = null;
            }
        }

        return $ranges;
    }
}
