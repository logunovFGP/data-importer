<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Shared\Support;

use App\Services\Shared\Support\BooleanParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversDefaultClass \App\Services\Shared\Support\BooleanParser
 */
final class BooleanParserTest extends TestCase
{
    #[DataProvider('trueValuesProvider')]
    public function testParseTrueValues(mixed $input): void
    {
        $this->assertTrue(BooleanParser::parse($input));
    }

    public static function trueValuesProvider(): iterable
    {
        yield 'bool true' => [true];
        yield 'string "1"' => ['1'];
        yield 'string "true"' => ['true'];
        yield 'string "TRUE"' => ['TRUE'];
        yield 'string "True"' => ['True'];
        yield 'string "on"' => ['on'];
        yield 'string "ON"' => ['ON'];
        yield 'string "yes"' => ['yes'];
        yield 'string "YES"' => ['YES'];
        yield 'string " true "' => [' true '];
    }

    #[DataProvider('falseValuesProvider')]
    public function testParseFalseValues(mixed $input): void
    {
        $this->assertFalse(BooleanParser::parse($input));
    }

    public static function falseValuesProvider(): iterable
    {
        yield 'bool false' => [false];
        yield 'string "0"' => ['0'];
        yield 'string "false"' => ['false'];
        yield 'string "FALSE"' => ['FALSE'];
        yield 'string "off"' => ['off'];
        yield 'string "OFF"' => ['OFF'];
        yield 'string "no"' => ['no'];
        yield 'string "NO"' => ['NO'];
        yield 'empty string' => [''];
        yield 'null' => [null];
    }

    /**
     * Unrecognized values fall back to PHP's (bool) cast.
     */
    public function testParseUnrecognizedFallsToBoolCast(): void
    {
        // Non-empty string that is not in the lists -> (bool) 'random' = true
        $this->assertTrue(BooleanParser::parse('random'));
    }

    public function testParseIntegerOne(): void
    {
        // (string) 1 = '1' which is in TRUE_VALUES
        $this->assertTrue(BooleanParser::parse(1));
    }

    public function testParseIntegerZero(): void
    {
        // (string) 0 = '0' which is in FALSE_VALUES
        $this->assertFalse(BooleanParser::parse(0));
    }
}
