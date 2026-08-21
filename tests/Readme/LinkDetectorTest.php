<?php

declare(strict_types=1);

namespace Makeview\Tests\Readme;

use Makeview\Readme\BlockParser;
use Makeview\Readme\LinkDetector;
use PHPUnit\Framework\TestCase;

final class LinkDetectorTest extends TestCase
{
    public function testResolvesReferenceStyleLinks(): void
    {
        $blocks = BlockParser::parse("See the [dashboard][db].\n\n[db]: https://example.com/dash\n");
        $links = LinkDetector::detect($blocks);

        self::assertCount(1, $links);
        self::assertSame('dashboard', $links[0]['label']);
        self::assertSame('https://example.com/dash', $links[0]['url']);
    }

    public function testAddsSchemeToBareLocalhost(): void
    {
        $blocks = BlockParser::parse("Open localhost:3000 in a browser.\n");
        $links = LinkDetector::detect($blocks);

        self::assertCount(1, $links);
        self::assertSame('http://localhost:3000', $links[0]['url']);
    }

    public function testIgnoresUnresolvedReference(): void
    {
        $blocks = BlockParser::parse("See the [dashboard][missing].\n");

        self::assertSame([], LinkDetector::detect($blocks));
    }

    public function testIgnoresVersionNumbersThatLookLikeHostPortPairs(): void
    {
        $blocks = BlockParser::parse("Requires PHP 8.3:1 or newer.\n");

        self::assertSame([], LinkDetector::detect($blocks));
    }
}
