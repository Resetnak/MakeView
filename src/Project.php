<?php

declare(strict_types=1);

namespace Makeview;

use Makeview\Value\Link;
use Makeview\Value\Service;
use Symfony\Component\Yaml\Exception\ParseException;

/** Filesystem access. Every read of a project directory happens here. */
final class Project
{
    private const MAKEFILE_NAMES = ['Makefile', 'makefile', 'GNUmakefile'];
    private const README_NAMES = ['README.md', 'readme.md', 'Readme.md'];

    /**
     * Scan a root directory for project subdirectories holding a Makefile or README.
     *
     * @return array<string, array{name: string, makefile: ?string, readme: ?string}>
     */
    public static function scan(string $root): array
    {
        $out = [];
        foreach (glob($root . '/*', GLOB_ONLYDIR) as $dir) {
            $name = basename($dir);
            $mk = self::firstExisting($dir, self::MAKEFILE_NAMES);
            $rd = self::firstExisting($dir, self::README_NAMES);
            if ($mk || $rd) {
                $out[$name] = ['name' => $name, 'makefile' => $mk, 'readme' => $rd];
            }
        }
        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }

    public static function firstExisting(string $dir, array $names): ?string
    {
        foreach ($names as $name) {
            if (is_file("{$dir}/{$name}")) {
                return "{$dir}/{$name}";
            }
        }

        return null;
    }

    /**
     * Cheap metadata read straight off the filesystem — no git binary involved.
     *
     * @return array{branch: ?string, mtime: int, stack: list<string>}
     */
    public static function meta(string $dir): array
    {
        $branch = null;
        if (is_file("{$dir}/.git/HEAD")) {
            $head = trim((string) file_get_contents("{$dir}/.git/HEAD"));
            $branch = str_starts_with($head, 'ref: refs/heads/')
                ? substr($head, 16)
                : substr($head, 0, 7);
        }

        // .git/index mtime approximates last add/commit/checkout; dir mtime as fallback.
        $mtime = is_file("{$dir}/.git/index") ? filemtime("{$dir}/.git/index") : filemtime($dir);

        $stack = [];
        if (is_file("{$dir}/composer.json")) {
            $stack[] = 'PHP';
        }
        if (is_file("{$dir}/package.json")) {
            $stack[] = 'JS';
        }
        if (self::composeFile($dir) !== null || is_file("{$dir}/Dockerfile")) {
            $stack[] = 'Docker';
        }

        return ['branch' => $branch, 'mtime' => (int) $mtime, 'stack' => $stack];
    }

    /**
     * Services from a project's compose files. A malformed file
     * yields an empty list — an exception in one broken project must not
     * take down the whole page.
     *
     * @return Service[]
     */
    public static function services(string $dir): array
    {
        $base = self::composeFile($dir);
        if ($base === null) {
            return [];
        }

        $override = self::firstExisting($dir, Compose::OVERRIDE_FILENAMES);

        try {
            return Compose::parse(
                (string) file_get_contents($base),
                $override !== null ? (string) file_get_contents($override) : '',
            );
        } catch (ParseException) {
            return [];
        }
    }

    /** True when a compose file exists but could not be parsed. */
    public static function composeFailed(string $dir): bool
    {
        $base = self::composeFile($dir);
        if ($base === null) {
            return false;
        }

        $override = self::firstExisting($dir, Compose::OVERRIDE_FILENAMES);

        try {
            Compose::parse(
                (string) file_get_contents($base),
                $override !== null ? (string) file_get_contents($override) : '',
            );

            return false;
        } catch (ParseException) {
            return true;
        }
    }

    /** @return Link[] */
    public static function readmeLinks(string $readmePath): array
    {
        $contents = @file_get_contents($readmePath);

        return $contents === false ? [] : Readme::parse($contents);
    }

    private static function composeFile(string $dir): ?string
    {
        return self::firstExisting($dir, Compose::BASE_FILENAMES);
    }
}
