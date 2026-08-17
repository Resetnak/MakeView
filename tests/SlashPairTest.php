<?php

declare(strict_types=1);

namespace Makeview\Tests;

use Makeview\Readme;
use PHPUnit\Framework\TestCase;

/**
 * `a / b` is not an account.
 *
 * Two backticked words separated by a slash was read as a login pair on the
 * strength of the backticks alone. But backticks mark a literal, not a
 * credential, and READMEs use that exact shape for menu paths, theme names,
 * file pairs and option lists:
 *
 *     - dlouhy stisk ciferniku -> `Customize` / `Complications`
 *
 * That line described where to tap on a watch face. It was reported as
 * username `Customize`, password `Complications`, at 0.80 — above the
 * confirmed threshold, so the dashboard showed it as a real account.
 *
 * A pair needs a word announcing it as credentials. The introduced form
 * ("Přihlašovací údaje: admin / secret") stays supported; the bare form does
 * not, because nothing in it distinguishes an account from a menu path.
 */
final class SlashPairTest extends TestCase
{
    /** @return list<string> */
    private function values(string $markdown): array
    {
        $out = [];
        foreach (Readme::parse($markdown) as $link) {
            foreach ($link->credentials as $credential) {
                $out[] = $credential->value;
            }
        }

        return $out;
    }

    public function testMenuPathIsNotAnAccount(): void
    {
        $markdown = "## Komplikace\n\n- dlouhy stisk ciferniku -> `Customize` / `Complications`\n";

        self::assertSame([], $this->values($markdown));
    }

    public function testThemeNamesAreNotAnAccount(): void
    {
        $markdown = "## Motivy\n\n- prepnuti: `blue` / `ice`\n";

        self::assertSame([], $this->values($markdown));
    }

    public function testFilePairIsNotAnAccount(): void
    {
        $markdown = "## Soubory\n\n- kopiruj `watchface.xml` / `watchface_arc_ice.xml`\n";

        self::assertSame([], $this->values($markdown));
    }

    /** A word announcing the pair is what makes it readable as an account. */
    public function testIntroducedPairIsStillRead(): void
    {
        $markdown = "## Admin\n\nPřihlašovací údaje: admin / Tajne.Heslo123\n";

        self::assertSame(['admin', 'Tajne.Heslo123'], $this->values($markdown));
    }

    public function testIntroducedBacktickedPairIsStillRead(): void
    {
        $markdown = "## Admin\n\nCredentials: `admin` / `Tajne.Heslo123`\n";

        self::assertSame(['admin', 'Tajne.Heslo123'], $this->values($markdown));
    }
}
