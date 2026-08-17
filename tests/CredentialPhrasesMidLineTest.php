<?php

declare(strict_types=1);

namespace Makeview\Tests;

use Makeview\CredentialPhrases;
use PHPUnit\Framework\TestCase;

final class CredentialPhrasesMidLineTest extends TestCase
{
    public function testReadsBareValueFollowedByMoreText(): void
    {
        $credentials = CredentialPhrases::inLine('heslo: admin123, port: 8080');

        self::assertCount(1, $credentials);
        self::assertSame('password', $credentials[0]->kind);
        self::assertSame('admin123', $credentials[0]->value);
    }

    public function testReadsBothHalvesOfAMidLinePair(): void
    {
        $credentials = CredentialPhrases::inLine('user: admin, password: secret123');

        self::assertCount(2, $credentials);
        self::assertSame('admin', $credentials[0]->value);
        self::assertSame('secret123', $credentials[1]->value);
    }

    public function testStillReadsValueAtEndOfLine(): void
    {
        $credentials = CredentialPhrases::inLine('heslo: admin123');

        self::assertCount(1, $credentials);
        self::assertSame('admin123', $credentials[0]->value);
    }

    public function testDoesNotSwallowTrailingProseAsTheValue(): void
    {
        $credentials = CredentialPhrases::inLine('password rotation is handled by the operator');

        self::assertSame([], $credentials);
    }
}
