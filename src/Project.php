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
     * Directories that group code without ever being projects themselves. Both
     * hold hundreds of nested packages with their own READMEs, and descending
     * into them would bury the real projects.
     */
    private const SKIP_DIRS = ['node_modules', 'vendor', 'target', 'build', 'dist', '.git'];

    /**
     * Scan a root directory for projects: subdirectories holding a Makefile or a
     * README. A subdirectory with neither is not discarded outright — it may be a
     * container that merely groups repositories (a `dental/` holding nine apps),
     * so its own children are checked too. The descent stops there: two levels
     * covers grouping without turning a deep tree into a crawl.
     *
     * @return array<string, array{name: string, makefile: ?string, readme: ?string}>
     */
    public static function scan(string $root): array
    {
        $out = [];

        foreach (self::subdirectories($root) as $dir) {
            $project = self::projectAt($dir, basename($dir));

            // A project is a leaf: once a directory qualifies, its subdirectories
            // are its own contents (docs/, examples/), not separate projects.
            if ($project !== null) {
                $out[$project['name']] = $project;
                continue;
            }

            foreach (self::subdirectories($dir) as $child) {
                $name = basename($dir) . '/' . basename($child);
                $nested = self::projectAt($child, $name);
                if ($nested !== null) {
                    $out[$name] = $nested;
                }
            }
        }

        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }

    /**
     * Immediate subdirectories worth looking at: no dotfiles, no vendor trees.
     *
     * @return list<string>
     */
    private static function subdirectories(string $dir): array
    {
        $out = [];

        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $path) {
            $name = basename($path);
            if (str_starts_with($name, '.') || in_array($name, self::SKIP_DIRS, true)) {
                continue;
            }

            $out[] = $path;
        }

        return $out;
    }

    /** @return array{name: string, makefile: ?string, readme: ?string}|null */
    private static function projectAt(string $dir, string $name): ?array
    {
        $mk = self::firstExisting($dir, self::MAKEFILE_NAMES);
        $rd = self::firstExisting($dir, self::README_NAMES);

        if (!$mk && !$rd) {
            return null;
        }

        return ['name' => $name, 'makefile' => $mk, 'readme' => $rd];
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
