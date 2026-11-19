<?php

namespace Tests\Unit;

use App\Support\StockCoverPhrase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StockCoverPhraseTest extends TestCase
{
    #[DataProvider('coverProvider')]
    public function test_from_months_uses_days_weeks_or_whole_months(float $months, string $expected): void
    {
        $this->assertSame($expected, StockCoverPhrase::fromMonths($months));
    }

    /**
     * @return array<string, array{0: float, 1: string}>
     */
    public static function coverProvider(): array
    {
        return [
            'critical weeks' => [0.61, '3 weeks'],
            'under two weeks' => [0.3, '9 days'],
            'weeks' => [1.8, '8 weeks'],
            'whole months' => [3.2, '3 months'],
            'zero' => [0.0, 'less than 1 day'],
        ];
    }

    public function test_rewrite_decimal_months_in_text(): void
    {
        $text = 'Bond Paper A4 has only 0.61 months of cover at about 13 units/mo.';

        $this->assertSame(
            'Bond Paper A4 has only 3 weeks of cover at about 13 units/mo.',
            StockCoverPhrase::rewriteDecimalMonthsInText($text),
        );
    }
}
