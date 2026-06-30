<?php

namespace Tests\Unit;

use App\Support\SemiExpendableValueCategory;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SemiExpendableValueCategoryTest extends TestCase
{
    public function test_low_unit_cost_maps_to_splv(): void
    {
        $this->assertSame(SemiExpendableValueCategory::PREFIX_LOW, SemiExpendableValueCategory::prefixForUnitCost(4500));
        $this->assertSame(SemiExpendableValueCategory::VALUE_LOW, SemiExpendableValueCategory::valueTypeForUnitCost(4500));
        $this->assertSame(SemiExpendableValueCategory::PREFIX_LOW, SemiExpendableValueCategory::prefixForUnitCost(5000));
        $this->assertSame(SemiExpendableValueCategory::VALUE_LOW, SemiExpendableValueCategory::valueTypeForUnitCost(5000));
    }

    public function test_high_unit_cost_maps_to_sphv(): void
    {
        $this->assertSame(SemiExpendableValueCategory::PREFIX_HIGH, SemiExpendableValueCategory::prefixForUnitCost(5001));
        $this->assertSame(SemiExpendableValueCategory::VALUE_HIGH, SemiExpendableValueCategory::valueTypeForUnitCost(5001));
        $this->assertSame(SemiExpendableValueCategory::PREFIX_HIGH, SemiExpendableValueCategory::prefixForUnitCost(12000));
        $this->assertSame(SemiExpendableValueCategory::VALUE_HIGH, SemiExpendableValueCategory::valueTypeForUnitCost(49999.99));
    }

    public function test_cap_threshold_blocks_semi_cost(): void
    {
        $this->expectException(ValidationException::class);

        SemiExpendableValueCategory::assertWithinSemiCap(50000);
    }

    public function test_tier_rule_summary_documents_boundaries(): void
    {
        $this->assertSame(
            'SPLV ≤ ₱5,000 per unit; SPHV > ₱5,000 and < ₱50,000 per unit',
            SemiExpendableValueCategory::tierRuleSummary(),
        );
    }
}
