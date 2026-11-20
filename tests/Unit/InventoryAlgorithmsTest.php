<?php

namespace Tests\Unit;

use App\Services\InventoryAlgorithms;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InventoryAlgorithmsTest extends TestCase
{
    #[Test]
    public function period_over_period_growth_returns_null_when_first_month_is_zero(): void
    {
        $this->assertNull(InventoryAlgorithms::periodOverPeriodGrowth([0, 10, 30]));
        $this->assertNull(InventoryAlgorithms::periodOverPeriodGrowth([0, 0]));
    }

    #[Test]
    public function period_over_period_growth_returns_percent_change_for_normal_series(): void
    {
        $this->assertSame(200.0, InventoryAlgorithms::periodOverPeriodGrowth([10, 20, 30]));
    }

    #[Test]
    public function usage_spike_share_detects_majority_month(): void
    {
        $share = InventoryAlgorithms::usageSpikeShare([1, 1, 20, 1]);

        $this->assertNotNull($share);
        $this->assertEqualsWithDelta(20 / 23, $share, 0.0001);
    }

    #[Test]
    public function usage_spike_share_detects_three_times_median(): void
    {
        // Positive months: 2, 2, 2, 10 — median 2, max 10 >= 6
        $share = InventoryAlgorithms::usageSpikeShare([2, 2, 2, 10, 0, 0]);

        $this->assertNotNull($share);
        $this->assertEqualsWithDelta(10 / 16, $share, 0.0001);
    }

    #[Test]
    public function usage_spike_share_returns_null_for_even_series(): void
    {
        $this->assertNull(InventoryAlgorithms::usageSpikeShare([5, 5, 5, 5]));
        $this->assertNull(InventoryAlgorithms::usageSpikeShare([]));
        $this->assertNull(InventoryAlgorithms::usageSpikeShare([0, 0, 0]));
    }
}
