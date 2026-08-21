<?php

declare(strict_types=1);

namespace Makeview\Tests;

use Makeview\Project;
use PHPUnit\Framework\TestCase;

/**
 * Project discovery is the one part of the codebase that reads the filesystem,
 * so these tests build a real directory tree rather than mocking it.
 */
final class ProjectScanTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/makeview-scan-' . bin2hex(random_bytes(6));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    private function project(string $path, string $file = 'README.md'): void
    {
        mkdir($this->root . '/' . $path, 0777, true);
        file_put_contents($this->root . '/' . $path . '/' . $file, "# test\n");
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeTree($path) : unlink($path);
        }

        rmdir($dir);
    }

    public function testFindsProjectsDirectlyBelowTheRoot(): void
    {
        $this->project('api');
        $this->project('web', 'Makefile');

        self::assertSame(['api', 'web'], array_keys(Project::scan($this->root)));
    }

    public function testDirectoryWithNeitherMakefileNorReadmeIsSkipped(): void
    {
        mkdir($this->root . '/empty');

        self::assertSame([], Project::scan($this->root));
    }

    /**
     * A directory that only groups other repositories — no Makefile or README of
     * its own — used to hide everything inside it, because the scan stopped at
     * one level. Its children are the real projects and must show up.
     */
    public function testDescendsIntoContainerDirectoriesThatHoldRepositories(): void
    {
        $this->project('dental/clinic-api');
        $this->project('dental/dental-admin', 'Makefile');

        self::assertSame(
            ['dental/clinic-api', 'dental/dental-admin'],
            array_keys(Project::scan($this->root)),
        );
    }

    public function testContainerChildrenAreNamedByTheirPath(): void
    {
        $this->project('dental/clinic-api');

        $projects = Project::scan($this->root);

        self::assertSame('dental/clinic-api', $projects['dental/clinic-api']['name']);
        self::assertSame(
            $this->root . '/dental/clinic-api/README.md',
            $projects['dental/clinic-api']['readme'],
        );
    }

    /**
     * A project is a leaf: once a directory qualifies, its subdirectories are its
     * own contents (docs/, examples/), not separate projects.
     */
    public function testDoesNotDescendIntoADirectoryThatIsItselfAProject(): void
    {
        $this->project('api');
        $this->project('api/docs');

        self::assertSame(['api'], array_keys(Project::scan($this->root)));
    }

    public function testDoesNotDescendBeyondTheSecondLevel(): void
    {
        $this->project('group/sub/deep');

        self::assertSame([], Project::scan($this->root));
    }

    public function testSkipsDotDirectoriesAndVendorTrees(): void
    {
        $this->project('.config/tool');
        $this->project('node_modules/some-package');
        $this->project('vendor/some/lib');

        self::assertSame([], Project::scan($this->root));
    }
}
