<?php

declare(strict_types=1);

namespace Makeview\Tests;

use Makeview\CredentialPhrases;
use PHPUnit\Framework\TestCase;

final class CredentialPhrasesFuzzyTest extends TestCase
{
    public function testExactWordStillResolves(): void
    {
        self::assertSame('password', CredentialPhrases::fuzzyKindFor('heslo'));
        self::assertSame('user', CredentialPhrases::fuzzyKindFor('username'));
        self::assertSame('token', CredentialPhrases::fuzzyKindFor('token'));
    }

    public function testSingleCharacterTypoResolves(): void
    {
        self::assertSame('password', CredentialPhrases::fuzzyKindFor('pasword'));
        self::assertSame('user', CredentialPhrases::fuzzyKindFor('usrname'));
    }

    public function testCaseIsIgnored(): void
    {
        self::assertSame('password', CredentialPhrases::fuzzyKindFor('HESLO'));
    }

    public function testUnrelatedWordDoesNotResolve(): void
    {
        self::assertNull(CredentialPhrases::fuzzyKindFor('rotation'));
        self::assertNull(CredentialPhrases::fuzzyKindFor('database'));
    }

    public function testShortWordDoesNotFuzzyMatchBecauseOneEditIsTooMuchOfIt(): void
    {
        self::assertNull(CredentialPhrases::fuzzyKindFor('use'));
        self::assertNull(CredentialPhrases::fuzzyKindFor('ass'));
    }
}
