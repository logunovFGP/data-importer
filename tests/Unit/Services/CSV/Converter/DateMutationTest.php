<?php

declare(strict_types=1);

namespace Tests\Unit\Services\CSV\Converter;

use App\Services\CSV\Converter\Date;
use Tests\TestCase;

/**
 * Test for Fix #50: Date converter mutates $this->dateFormat by prepending '!'.
 *
 * Calling convert() twice used to produce '!!' prefix on the second call
 * because the first call modified $this->dateFormat in place.
 *
 * @internal
 *
 * @coversNothing
 */
final class DateMutationTest extends TestCase
{
    public function testConvertDoesNotDoublePrependBang(): void
    {
        $converter = new Date();
        $converter->setConfiguration('Y-m-d');

        $first  = $converter->convert('2026-03-15');
        $second = $converter->convert('2026-03-15');

        // Both calls should produce the same result.
        $this->assertSame($first, $second, 'Second convert() call produced a different result, format was likely mutated.');
        $this->assertSame('2026-03-15 00:00:00', $first);
    }

    public function testConvertPreservesDateFormatAcrossMultipleCalls(): void
    {
        $converter = new Date();
        $converter->setConfiguration('d/m/Y');

        $first  = $converter->convert('15/03/2026');
        $second = $converter->convert('20/03/2026');

        $this->assertSame('2026-03-15 00:00:00', $first);
        $this->assertSame('2026-03-20 00:00:00', $second);
    }

    public function testSplitLocaleFormat(): void
    {
        $converter = new Date();

        [$locale, $format] = $converter->splitLocaleFormat('de:d.m.Y');
        $this->assertSame('de', $locale);
        $this->assertSame('d.m.Y', $format);

        [$locale, $format] = $converter->splitLocaleFormat('Y-m-d');
        $this->assertSame('en', $locale);
        $this->assertSame('Y-m-d', $format);
    }
}
