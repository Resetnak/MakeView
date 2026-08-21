<?php

declare(strict_types=1);

namespace Makeview;

/** Makefile target extraction. */
final class Make
{
    /**
     * Parse make targets. Documented targets look like `target: ## description`.
     *
     * @return array{documented: list<array{target: string, desc: string}>, bare: list<string>}
     */
    public static function parseTargets(string $contents): array
    {
        $documented = [];
        $bare = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            // documented: name: [deps] ## description
            if (preg_match('/^([a-zA-Z0-9][a-zA-Z0-9_.\/-]*)\s*:[^=].*?##\s*(.+)$/', $line, $m) === 1) {
                $documented[] = ['target' => $m[1], 'desc' => trim($m[2])];
                continue;
            }

            // bare target: name: (not :=, ?=, +=; not .PHONY and friends)
            if (preg_match('/^([a-zA-Z0-9][a-zA-Z0-9_.\/-]*)\s*:([^=]|$)/', $line, $m) === 1
                && $m[1][0] !== '.'
            ) {
                $bare[$m[1]] = true; // key dedupes
            }
        }

        foreach ($documented as $d) {
            unset($bare[$d['target']]);
        }

        return ['documented' => $documented, 'bare' => array_keys($bare)];
    }
}
