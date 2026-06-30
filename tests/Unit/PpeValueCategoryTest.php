<?php

namespace Tests\Unit;

use App\Support\PpeValueCategory;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PpeValueCategoryTest extends TestCase
{
    public function test_minimum_threshold_matches_semi_cap(): void
    {
        $this->assertSame(50000.0, PpeValueCategory::minimumThreshold());
    }

    public function test_valid_ppe_unit_cost_passes(): void
    {
        PpeValueCategory::assertMinimumForPpe(50000);
        PpeValueCategory::assertMinimumForPpe(75000);

        $this->assertTrue(true);
    }

    public function test_below_threshold_blocks_ppe_cost(): void
    {
        $this->expectException(ValidationException::class);

        PpeValueCategory::assertMinimumForPpe(49999.99);
    }

    public function test_minimum_rule_summary_documents_threshold(): void
    {
        $this->assertSame(
            'PPE must cost at least ₱50,000 per unit (COA capitalization threshold).',
            PpeValueCategory::minimumRuleSummary(),
        );
    }
}
