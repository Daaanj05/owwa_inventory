<?php

namespace Tests\Unit;

use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Support\SemiExpendableUsefulLife;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SemiExpendableUsefulLifeExpiryTest extends TestCase
{
    public function test_compute_expires_at_adds_parsed_months_to_issuance_date(): void
    {
        $date = Carbon::parse('2024-01-01');
        $expires = SemiExpendableUsefulLife::computeExpiresAt($date, '36 months');

        $this->assertNotNull($expires);
        $this->assertSame('2027-01-01', $expires->toDateString());
    }

    public function test_compute_expires_at_still_accepts_legacy_year_strings(): void
    {
        $date = Carbon::parse('2024-01-01');
        $expires = SemiExpendableUsefulLife::computeExpiresAt($date, '3 yrs');

        $this->assertNotNull($expires);
        $this->assertSame('2027-01-01', $expires->toDateString());
    }

    public function test_format_duration_shows_months_and_years(): void
    {
        $this->assertSame('36 months (3 years)', SemiExpendableUsefulLife::formatFromMonths(36));
        $this->assertSame('36 months (3 years)', SemiExpendableUsefulLife::formatDuration('36'));
    }

    public function test_status_for_issuance_is_expired_when_past_eul_date(): void
    {
        Carbon::setTestNow('2026-06-01');

        $category = new ItemCategory(['name' => 'Semi-Expendable']);
        $item = new Item(['name' => 'Chair']);
        $item->setRelation('category', $category);

        $issuance = new Issuance([
            'estimated_useful_life' => '24 months',
            'issuance_date' => Carbon::parse('2023-01-01'),
            'eul_expires_at' => Carbon::parse('2025-01-01'),
        ]);
        $issuance->setRelation('item', $item);

        $this->assertSame(SemiExpendableUsefulLife::STATUS_EXPIRED, SemiExpendableUsefulLife::statusForIssuance($issuance));

        Carbon::setTestNow();
    }

    public function test_status_for_issuance_is_nearing_when_twenty_percent_or_less_remaining(): void
    {
        Carbon::setTestNow('2026-06-01');
        config(['inventory.eul_nearing_percent_remaining' => 20]);

        $category = new ItemCategory(['name' => 'Semi-Expendable']);
        $item = new Item(['name' => 'Chair']);
        $item->setRelation('category', $category);

        // 60-month life: issuance 2021-06-01 → expires 2026-06-01+0... wait
        // Use: issuance 2021-07-01, 60 months → expires 2026-07-01
        // On 2026-06-01 remaining ~30 days of ~1826 days ≈ 1.6% → nearing
        $issuance = new Issuance([
            'estimated_useful_life' => '60 months',
            'issuance_date' => Carbon::parse('2021-07-01'),
            'eul_expires_at' => Carbon::parse('2026-07-01'),
        ]);
        $issuance->setRelation('item', $item);

        $this->assertSame(SemiExpendableUsefulLife::STATUS_NEARING, SemiExpendableUsefulLife::statusForIssuance($issuance));

        Carbon::setTestNow();
    }

    public function test_status_for_issuance_is_ok_when_more_than_twenty_percent_remaining(): void
    {
        Carbon::setTestNow('2024-01-01');
        config(['inventory.eul_nearing_percent_remaining' => 20]);

        $category = new ItemCategory(['name' => 'Semi-Expendable']);
        $item = new Item(['name' => 'Chair']);
        $item->setRelation('category', $category);

        $issuance = new Issuance([
            'estimated_useful_life' => '60 months',
            'issuance_date' => Carbon::parse('2021-01-01'),
            'eul_expires_at' => Carbon::parse('2026-01-01'),
        ]);
        $issuance->setRelation('item', $item);

        $this->assertSame(SemiExpendableUsefulLife::STATUS_OK, SemiExpendableUsefulLife::statusForIssuance($issuance));

        Carbon::setTestNow();
    }

    public function test_status_for_ppe_issuance_is_null(): void
    {
        $category = new ItemCategory(['name' => 'PPE']);
        $item = new Item(['name' => 'Laptop']);
        $item->setRelation('category', $category);

        $issuance = new Issuance([
            'issuance_date' => now(),
            'eul_expires_at' => now()->addYear(),
        ]);
        $issuance->setRelation('item', $item);

        $this->assertNull(SemiExpendableUsefulLife::statusForIssuance($issuance));
    }
}
