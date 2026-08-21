<?php

declare(strict_types=1);

namespace Makeview\Tests;

use Makeview\Readme;
use PHPUnit\Framework\TestCase;

/**
 * A URL wrapped in backticks keeps its delimiters.
 *
 * READMEs write `` `http://localhost:8080` `` constantly. The URL scan ends a
 * bare URL at whitespace, so the closing backtick stayed inside the value and
 * reached the dashboard's href — a link that does not resolve, and a label
 * with a stray mark in it.
 */
final class UrlTrimTest extends TestCase
{
    /** @return list<string> */
    private function urls(string $markdown): array
    {
        $out = [];
        foreach (Readme::parse($markdown) as $link) {
            if ($link->url !== null) {
                $out[] = $link->url;
            }
        }

        return $out;
    }

    public function testBacktickedUrlLosesItsDelimiters(): void
    {
        $markdown = "## Quick start\n\nAPI available at `http://localhost:8080`. Authenticate with the header.\n";

        self::assertSame(['http://localhost:8080'], $this->urls($markdown));
    }

    public function testBacktickedUrlInATableCellLosesItsDelimiters(): void
    {
        $markdown = "## Env\n\n| Variable | Description |\n| --- | --- |\n| `SLACK_WEBHOOK_URL` | Must be `https://hooks.slack.com/services/x` |\n";

        foreach ($this->urls($markdown) as $url) {
            self::assertStringNotContainsString('`', $url);
        }
    }

    public function testPlainUrlIsUnaffected(): void
    {
        $markdown = "## Quick start\n\nAPI available at http://localhost:8080 for now.\n";

        self::assertSame(['http://localhost:8080'], $this->urls($markdown));
    }

    public function testMarkdownLinkIsUnaffected(): void
    {
        $markdown = "## Quick start\n\nSee [the API](http://localhost:8080/docs).\n";

        self::assertSame(['http://localhost:8080/docs'], $this->urls($markdown));
    }
}
