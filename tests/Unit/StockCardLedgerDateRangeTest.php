<?php

namespace Tests\Unit;

use App\Support\StockCardLedgerDateRange;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StockCardLedgerDateRangeTest extends TestCase
{
    #[Test]
    public function fiscal_year_defaults_are_january_through_december_of_current_year(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

        $this->assertSame([
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ], StockCardLedgerDateRange::fiscalYearDefaults());

        Carbon::setTestNow();
    }

    #[Test]
    public function resolve_all_mode_returns_null(): void
    {
        $this->assertNull(StockCardLedgerDateRange::resolve('all', '2026-01-01', '2026-06-30'));
    }

    #[Test]
    public function resolve_without_mode_or_dates_returns_null_for_backward_compatibility(): void
    {
        $this->assertNull(StockCardLedgerDateRange::resolve(null, null, null));
    }

    #[Test]
    public function resolve_range_mode_uses_fiscal_year_when_dates_blank(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15'));

        $resolved = StockCardLedgerDateRange::resolve('range', null, null);

        $this->assertNotNull($resolved);
        $this->assertSame('2026-01-01', $resolved['from']->toDateString());
        $this->assertSame('2026-12-31', $resolved['to']->toDateString());

        Carbon::setTestNow();
    }

    #[Test]
    public function approach_b_prepends_opening_balance_and_keeps_in_range_balances(): void
    {
        $transactions = [
            [
                'office_id' => 1,
                'date' => '2025-12-15',
                'sort_date' => '2025-12-15',
                'reference' => 'OLD-1',
                'receipt_qty' => 10,
                'issue_qty' => null,
                'balance' => 10,
            ],
            [
                'office_id' => 1,
                'date' => '2026-02-01',
                'sort_date' => '2026-02-01',
                'reference' => 'IN-1',
                'receipt_qty' => 5,
                'issue_qty' => null,
                'balance' => 15,
            ],
            [
                'office_id' => 1,
                'date' => '2026-03-01',
                'sort_date' => '2026-03-01',
                'reference' => 'OUT-1',
                'receipt_qty' => null,
                'issue_qty' => 3,
                'balance' => 12,
            ],
            [
                'office_id' => 1,
                'date' => '2027-01-05',
                'sort_date' => '2027-01-05',
                'reference' => 'FUTURE',
                'receipt_qty' => 1,
                'issue_qty' => null,
                'balance' => 13,
            ],
        ];

        $sliced = StockCardLedgerDateRange::applyApproachB(
            $transactions,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-12-31'),
            newestFirst: false,
        );

        $this->assertCount(3, $sliced);
        $this->assertSame('Balance forwarded', $sliced[0]['reference']);
        $this->assertSame(10, $sliced[0]['balance']);
        $this->assertSame('2026-01-01', $sliced[0]['date']);
        $this->assertSame('IN-1', $sliced[1]['reference']);
        $this->assertSame(15, $sliced[1]['balance']);
        $this->assertSame('OUT-1', $sliced[2]['reference']);
        $this->assertSame(12, $sliced[2]['balance']);
    }

    #[Test]
    public function approach_b_newest_first_puts_opening_at_bottom(): void
    {
        $transactions = [
            [
                'office_id' => 1,
                'date' => '2025-12-15',
                'reference' => 'OLD-1',
                'receipt_qty' => 10,
                'issue_qty' => null,
                'balance' => 10,
            ],
            [
                'office_id' => 1,
                'date' => '2026-02-01',
                'reference' => 'IN-1',
                'receipt_qty' => 5,
                'issue_qty' => null,
                'balance' => 15,
            ],
        ];

        $sliced = StockCardLedgerDateRange::applyApproachB(
            $transactions,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-12-31'),
            newestFirst: true,
        );

        $this->assertCount(2, $sliced);
        $this->assertSame('IN-1', $sliced[0]['reference']);
        $this->assertSame('Balance forwarded', $sliced[array_key_last($sliced)]['reference']);
        $this->assertSame(10, $sliced[array_key_last($sliced)]['balance']);
    }

    #[Test]
    public function approach_b_omits_balance_forwarded_when_nothing_was_carried_in(): void
    {
        $transactions = [
            [
                'office_id' => 1,
                'date' => '2026-02-06',
                'reference' => 'IN-1',
                'receipt_qty' => 80,
                'issue_qty' => null,
                'balance' => 80,
            ],
        ];

        $sliced = StockCardLedgerDateRange::applyApproachB(
            $transactions,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-12-31'),
            newestFirst: true,
        );

        $this->assertCount(1, $sliced);
        $this->assertSame('IN-1', $sliced[0]['reference']);
    }

    #[Test]
    public function approach_b_omits_balance_forwarded_when_prior_balance_is_zero(): void
    {
        $transactions = [
            [
                'office_id' => 1,
                'date' => '2025-11-01',
                'reference' => 'OLD-OUT',
                'receipt_qty' => null,
                'issue_qty' => 4,
                'balance' => 0,
            ],
            [
                'office_id' => 1,
                'date' => '2026-03-01',
                'reference' => 'IN-1',
                'receipt_qty' => 6,
                'issue_qty' => null,
                'balance' => 6,
            ],
        ];

        $sliced = StockCardLedgerDateRange::applyApproachB(
            $transactions,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-12-31'),
        );

        $this->assertCount(1, $sliced);
        $this->assertSame('IN-1', $sliced[0]['reference']);
    }
}
