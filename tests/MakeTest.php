<?php

declare(strict_types=1);

namespace Makeview\Tests;

use Makeview\Make;
use PHPUnit\Framework\TestCase;

final class MakeTest extends TestCase
{
    public function testExtractsDocumentedTargets(): void
    {
        $makefile = <<<'MAKE'
        build: ## Compile the thing
        test: ## Run the suite
        MAKE;

        $result = Make::parseTargets($makefile);

        self::assertSame(
            [
                ['target' => 'build', 'desc' => 'Compile the thing'],
                ['target' => 'test', 'desc' => 'Run the suite'],
            ],
            $result['documented'],
        );
    }

    public function testExtractsDocumentedTargetsWithDependencies(): void
    {
        $result = Make::parseTargets('deploy: build test ## Ship it');

        self::assertSame([['target' => 'deploy', 'desc' => 'Ship it']], $result['documented']);
    }

    public function testExtractsBareTargets(): void
    {
        $makefile = <<<'MAKE'
        build: ## Compile
        clean:
        	rm -rf dist
        install:
        MAKE;

        $result = Make::parseTargets($makefile);

        self::assertSame(['clean', 'install'], $result['bare']);
    }

    public function testBareListExcludesDocumentedTargets(): void
    {
        $makefile = <<<'MAKE'
        build: ## Compile
        build:
        MAKE;

        $result = Make::parseTargets($makefile);

        self::assertSame([], $result['bare']);
    }

    public function testIgnoresVariableAssignments(): void
    {
        $makefile = <<<'MAKE'
        CC := gcc
        FLAGS ?= -O2
        EXTRA += -g
        build:
        MAKE;

        $result = Make::parseTargets($makefile);

        self::assertSame(['build'], $result['bare']);
    }

    public function testIgnoresSpecialTargets(): void
    {
        $makefile = <<<'MAKE'
        .PHONY: build test
        .DEFAULT_GOAL := build
        build:
        MAKE;

        $result = Make::parseTargets($makefile);

        self::assertSame(['build'], $result['bare']);
    }

    public function testDeduplicatesRepeatedBareTargets(): void
    {
        $makefile = <<<'MAKE'
        build:
        build:
        MAKE;

        $result = Make::parseTargets($makefile);

        self::assertSame(['build'], $result['bare']);
    }

    public function testHandlesEmptyInput(): void
    {
        $result = Make::parseTargets('');

        self::assertSame(['documented' => [], 'bare' => []], $result);
    }

    public function testHandlesCrlfLineEndings(): void
    {
        $result = Make::parseTargets("build: ## Compile\r\ntest:\r\n");

        self::assertSame([['target' => 'build', 'desc' => 'Compile']], $result['documented']);
        self::assertSame(['test'], $result['bare']);
    }
}
