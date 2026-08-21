<?php

declare(strict_types=1);

namespace Makeview\Tests\Readme;

use Makeview\Readme\BlockParser;
use PHPUnit\Framework\TestCase;

final class BlockParserTest extends TestCase
{
    public function testSplitsHeadingsAndParagraphs(): void
    {
        $blocks = BlockParser::parse("# Title\n\nSome prose.\n");

        self::assertSame('heading', $blocks[0]->type);
        self::assertSame('Title', $blocks[0]->text);
        self::assertSame('paragraph', $blocks[1]->type);
    }

    public function testCarriesTheEnclosingHeadingOnEveryBlock(): void
    {
        $blocks = BlockParser::parse("## Development\n\nRun it.\n");

        self::assertSame('Development', $blocks[1]->heading);
    }

    public function testRecordsNestingDepthOfListItems(): void
    {
        $blocks = BlockParser::parse("- Login:\n  - user: admin\n  - password: secret123\n");
        $items = array_values(array_filter($blocks, fn ($b) => $b->type === 'list'));

        self::assertCount(3, $items);
        self::assertSame(0, $items[0]->depth);
        self::assertSame(1, $items[1]->depth);
        self::assertSame(1, $items[2]->depth);
    }

    public function testMarksFencedBlocks(): void
    {
        $blocks = BlockParser::parse("Intro.\n\n```sh\nexport TOKEN=x\n```\n");
        $fences = array_values(array_filter($blocks, fn ($b) => $b->type === 'fence'));

        self::assertCount(1, $fences);
        self::assertStringContainsString('export TOKEN=x', $fences[0]->text);
    }

    public function testUnterminatedFenceDoesNotSwallowTheRestOfTheDocument(): void
    {
        $blocks = BlockParser::parse("```sh\nexport TOKEN=x\n\n## Later\n\nheslo: secret123\n");
        $headings = array_values(array_filter($blocks, fn ($b) => $b->type === 'heading'));

        self::assertNotSame([], $headings, 'an unclosed fence must not hide later sections');
    }

    public function testRecordsLineRanges(): void
    {
        $blocks = BlockParser::parse("# Title\n\nProse.\n");

        self::assertSame(1, $blocks[0]->startLine);
        self::assertSame(3, $blocks[1]->startLine);
    }

    public function testCollectsReferenceLinkDefinitions(): void
    {
        $blocks = BlockParser::parse("See [docs][d].\n\n[d]: https://example.com/docs\n");
        $refs = array_values(array_filter($blocks, fn ($b) => $b->type === 'reference'));

        self::assertCount(1, $refs);
        self::assertStringContainsString('https://example.com/docs', $refs[0]->text);
    }
}
