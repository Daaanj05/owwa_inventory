<?php

namespace Tests\Unit;

use App\Support\SemiExpendableUsefulLife;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SemiExpendableUsefulLifeTest extends TestCase
{
    public function test_parse_years_from_common_formats(): void
    {
        $this->assertSame(5.0, SemiExpendableUsefulLife::parseToYears('5 yrs'));
        $this->assertSame(3.0, SemiExpendableUsefulLife::parseToYears('3 years'));
        $this->assertSame(1.5, SemiExpendableUsefulLife::parseToYears('18 months'));
        $this->assertSame(3.0, SemiExpendableUsefulLife::parseToYears('36 months'));
    }

    public function test_default_for_property_class_uses_config(): void
    {
        $this->assertSame('5 yrs', SemiExpendableUsefulLife::defaultForPropertyClass('ict'));
        $this->assertSame('5 yrs', SemiExpendableUsefulLife::defaultForPropertyClass('furnitures_fixtures'));
    }

    public function test_assert_eligible_rejects_one_year_or_less(): void
    {
        $this->expectException(ValidationException::class);

        SemiExpendableUsefulLife::assertEligibleForSemi('1 yr');
    }

    public function test_assert_eligible_accepts_more_than_one_year(): void
    {
        SemiExpendableUsefulLife::assertEligibleForSemi('5 yrs');

        $this->assertTrue(true);
    }

    public function test_assert_eligible_rejects_blank_value(): void
    {
        $this->expectException(ValidationException::class);

        SemiExpendableUsefulLife::assertEligibleForSemi(null);
    }
}
