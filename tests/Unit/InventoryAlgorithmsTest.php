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
}
