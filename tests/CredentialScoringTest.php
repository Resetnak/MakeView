<?php

declare(strict_types=1);

namespace Makeview\Tests;

use Makeview\Readme;
use Makeview\Readme\Scorer;
use Makeview\Value\Credential;
use PHPUnit\Framework\TestCase;

/**
 * Every finding carries a score, and the score has to mean something.
 *
 * A dashboard that reports each credential at 1.00 is telling the reader that a
 * value scraped out of prose by its entropy is as certain as one stated in a
 * credential table. The score exists so a weak finding can be shown as weak
 * rather than dropped — hiding a real password is worse than showing an
 * uncertain one — and that only works if the number varies with the evidence.
 */
final class CredentialScoringTest extends TestCase
{
    private function findValue(string $markdown, string $value): Credential
    {
        $seen = [];

        foreach (Readme::parse($markdown) as $link) {
            foreach ($link->credentials as $credential) {
                if ($credential->value === $value) {
                    return $credential;
                }
                $seen[] = $credential->value;
            }
        }

        self::fail("No credential valued {$value}. Found: " . implode(', ', $seen));
    }

    public function testTableCredentialIsConfirmed(): void
    {
        $markdown = <<<'MD'
            ## Služby

            | Služba | URL | Uživatel | Heslo |
            | --- | --- | --- | --- |
            | Argo | https://argo.localhost | argo-admin | Str0ng-Pass |
            MD;

        $credential = $this->findValue($markdown, 'Str0ng-Pass');

        self::assertGreaterThanOrEqual(Scorer::THRESHOLD_CONFIRMED, $credential->score);
    }

    public function testCredentialIntroducedByAWordIsConfirmed(): void
    {
        $markdown = "## App\n\nHeslo: `Str0ng-Pass`\n";

        $credential = $this->findValue($markdown, 'Str0ng-Pass');

        self::assertGreaterThanOrEqual(Scorer::THRESHOLD_CONFIRMED, $credential->score);
    }

    /**
     * A bare high-entropy run is the weakest thing the parser reports: nothing
     * but its own randomness suggests it is a secret, and randomness is equally
     * true of a base64 blob of test data. It must score below a value the
     * author introduced by name.
     *
     * A *named* shape — a JWT, an `AKIA…` key — is the opposite case and is
     * covered by testNamedShapeOutscoresAWordAlone().
     */
    public function testBareEntropyMatchScoresBelowAWordIntroducedOne(): void
    {
        $introduced = $this->findValue("## App\n\nHeslo: `Str0ng-Pass`\n", 'Str0ng-Pass');
        $bare = $this->findValue(
            "## App\n\nDeployment uses xK9mP2vQz7LwR4tY8nB3sF6h for auth.\n",
            'xK9mP2vQz7LwR4tY8nB3sF6h'
        );

        self::assertLessThan($introduced->score, $bare->score);
    }

    /**
     * A value whose format identifies it proves its own nature: a JWT decodes
     * to a header naming an algorithm, an `AKIA…` key is issued by one vendor
     * in one shape. That outranks a word, which prose can supply by accident.
     */
    public function testNamedShapeOutscoresAWordAlone(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';

        $named = $this->findValue("## App\n\nDeployment uses {$jwt} for auth.\n", $jwt);

        self::assertGreaterThanOrEqual(Scorer::THRESHOLD_CONFIRMED, $named->score);
    }

    /**
     * The dashboard renders a weak finding differently — greyed, flagged `?`,
     * with its reason on hover — and that branch is only reachable if some real
     * README actually produces a score below the threshold. It did not for a
     * long time: the minimum any detector could emit was 0.55, so the branch
     * and Credential::isUncertain() were dead code and every guess reached the
     * reader dressed as a confirmed secret.
     */
    public function testAWeakFindingIsMarkedUncertain(): void
    {
        $markdown = "## RabbitMQ\n\n- **user**: guest\n- **pass**: guest\n";

        $credential = $this->findValue($markdown, 'guest');

        self::assertTrue(
            $credential->isUncertain(),
            "score {$credential->score} should be below " . Scorer::THRESHOLD_LIKELY
        );
    }

    /** Every reported score must be a real probability, not a placeholder. */
    public function testEveryScoreIsWithinRange(): void
    {
        $markdown = (string) file_get_contents(__DIR__ . '/fixtures/readme-variants.md');

        $scores = [];
        foreach (Readme::parse($markdown) as $link) {
            foreach ($link->credentials as $credential) {
                $scores[] = $credential->score;
                self::assertGreaterThan(0.0, $credential->score, "score for {$credential->value}");
                self::assertLessThanOrEqual(1.0, $credential->score, "score for {$credential->value}");
            }
        }

        self::assertNotSame([], $scores);
    }

    /** A finding with no stated reason cannot be judged by the reader. */
    public function testEveryCredentialExplainsItself(): void
    {
        $markdown = (string) file_get_contents(__DIR__ . '/fixtures/readme-variants.md');

        foreach (Readme::parse($markdown) as $link) {
            foreach ($link->credentials as $credential) {
                self::assertNotSame('', $credential->evidence, "no evidence for {$credential->value}");
            }
        }
    }
}
