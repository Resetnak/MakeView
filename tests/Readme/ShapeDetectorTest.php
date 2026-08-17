<?php

declare(strict_types=1);

namespace Makeview\Tests\Readme;

use Makeview\Readme\ShapeDetector;
use PHPUnit\Framework\TestCase;

final class ShapeDetectorTest extends TestCase
{
    public function testDetectsJwtWithDecodableHeader(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dBjftJeZ4CVPmB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        self::assertSame('jwt', ShapeDetector::detect($jwt));
    }

    public function testRejectsDottedStringThatIsNotAJwt(): void
    {
        self::assertNull(ShapeDetector::detect('www.example.com'));
        self::assertNull(ShapeDetector::detect('a.b.c'));
    }

    public function testDetectsGithubToken(): void
    {
        self::assertSame('github_token', ShapeDetector::detect('ghp_aB3dE5fG7hJ9kL1mN3pQ5rS7tU9vW1xY3zA5'));
    }

    public function testDetectsOpenAiKey(): void
    {
        self::assertSame('openai_key', ShapeDetector::detect('sk-aB3dE5fG7hJ9kL1mN3pQ5rS7tU9vW1xY3zA5bC7d'));
    }

    public function testDetectsAwsAccessKey(): void
    {
        self::assertSame('aws_key', ShapeDetector::detect('AKIAIOSFODNN7EXAMPLE'));
    }

    /**
     * Assembled from parts rather than written as one literal. The value is
     * invented — the digits count up and the letters are the alphabet — but it
     * has the shape of a Slack token by construction, which is the whole point
     * of the test and also what makes a secret scanner reject the file. Joining
     * the pieces here keeps the test honest without shipping a string that
     * every scanner has to treat as a live credential.
     */
    public function testDetectsSlackToken(): void
    {
        $token = implode('-', ['xoxb', '123456789012', '1234567890123', 'aBcDeFgHiJkLmNoPqRsTuVwX']);

        self::assertSame('slack_token', ShapeDetector::detect($token));
    }

    public function testFallsBackToHighEntropyForUnknownGeneratedSecret(): void
    {
        self::assertSame('high_entropy', ShapeDetector::detect('xK9$mP2vQz7Lw!nR4'));
    }

    public function testOrdinaryWordHasNoShape(): void
    {
        self::assertNull(ShapeDetector::detect('admin'));
        self::assertNull(ShapeDetector::detect('rotation'));
    }

    public function testOrdinaryProseSentenceHasNoShape(): void
    {
        self::assertNull(ShapeDetector::detect(
            'This long ordinary english sentence about deployment steps configuration details app'
        ));
        self::assertNull(ShapeDetector::detect(
            'The quick brown fox jumps over lazy dog repeatedly forever'
        ));
    }

    public function testFilePathHasNoShape(): void
    {
        self::assertNull(ShapeDetector::detect('src/Controller/UserController.php'));
        self::assertNull(ShapeDetector::detect('./bin/dev vendor/bin/phpunit tests/Readme/ShapeDetectorTest.php'));
    }

    public function testHostnameOrUrlHasNoShape(): void
    {
        self::assertNull(ShapeDetector::detect('github.com/foo/bar-baz-qux-repository-name-that-is-long'));
        self::assertNull(ShapeDetector::detect('www.example.com'));
    }

    public function testIsSecretShapeMirrorsDetect(): void
    {
        self::assertTrue(ShapeDetector::isSecretShape('AKIAIOSFODNN7EXAMPLE'));
        self::assertFalse(ShapeDetector::isSecretShape('admin'));
    }

    /**
     * `host:port` is the single most common non-secret token in a README. The
     * hostname guard only understood dot-separated labels, so a service name
     * with a port — which has no dot at all — fell through to entropy.
     */
    public function testHostPortPairHasNoShape(): void
    {
        self::assertNull(ShapeDetector::detect('redis:6379'));
        self::assertNull(ShapeDetector::detect('localhost:3000'));
        self::assertNull(ShapeDetector::detect('example.com:443'));
    }

    public function testEmailAddressHasNoShape(): void
    {
        self::assertNull(ShapeDetector::detect('user@example.com'));
    }

    public function testIsoTimestampHasNoShape(): void
    {
        self::assertNull(ShapeDetector::detect('2026-08-13T15:00:00Z'));
    }

    /**
     * Header names, error constants and kebab-case slugs are words joined by a
     * separator. Splitting on that separator shows every part is a real word,
     * which no generated secret can be.
     */
    public function testSeparatorJoinedWordsHaveNoShape(): void
    {
        self::assertNull(ShapeDetector::detect('Content-Type'));
        self::assertNull(ShapeDetector::detect('ERR_MODULE_NOT_FOUND'));
        self::assertNull(ShapeDetector::detect('make-view-dashboard'));
        self::assertNull(ShapeDetector::detect('POSTGRES_PASSWORD'));
    }

    /** The regression suite's positives must survive every new guard. */
    public function testGeneratedSecretsStillDetectedAfterProseGuards(): void
    {
        self::assertSame('high_entropy', ShapeDetector::detect('xKmPvQzLwnR'));
        self::assertSame('high_entropy', ShapeDetector::detect('xK9$mP2vQz7Lw!nR4'));
        self::assertSame('aws_key', ShapeDetector::detect('AKIAIOSFODNN7EXAMPLE'));
        self::assertSame(
            'github_token',
            ShapeDetector::detect('ghp_abcdefghijklmnopqrstuvwxyz0123456789ab'),
        );
    }
}
