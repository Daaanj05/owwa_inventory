<?php

namespace Tests\Feature;

use App\Filament\Resources\Distributions\Actions\DistributionViewActions;
use App\Filament\Resources\Requisitions\Actions\RequisitionExportActions;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UcExportRestrictionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unit_consolidator_cannot_export_ris(): void
    {
        $uc = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR]);

        $this->assertFalse(RequisitionExportActions::userCanExportRis($uc));
    }

    public function test_supply_custodian_can_export_ris(): void
    {
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        $this->assertTrue(RequisitionExportActions::userCanExportRis($custodian));
    }

    public function test_list_requisitions_bulk_export_ris_visible_only_for_supply_custodian(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/Requisitions/Pages/ListRequisitions.php'));

        $this->assertStringContainsString('RequisitionExportActions::userCanExportRis', $source);
    }

    public function test_distribution_export_actions_are_hidden(): void
    {
        $this->assertFalse(DistributionViewActions::exportOwwaAction()->isVisible());
        $this->assertFalse(DistributionViewActions::exportPdfAction()->isVisible());
    }

    public function test_distributions_table_has_no_export_actions(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/Distributions/Tables/DistributionsTable.php'));

        $this->assertStringNotContainsString('exportOwwa', $source);
        $this->assertStringNotContainsString('Export OWWA form', $source);
    }
}
