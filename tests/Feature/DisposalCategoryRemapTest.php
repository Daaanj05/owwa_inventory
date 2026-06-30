<?php

namespace Tests\Feature;

use App\Filament\Resources\Disposals\DisposalResource;
use App\Filament\Resources\Disposals\Schemas\DisposalForm;
use App\Filament\Resources\IncidentReports\IncidentReportResource;
use App\Filament\Resources\Transfers\TransferResource;
use App\Models\Disposal;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\OwwaTemplateExportService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisposalCategoryRemapTest extends TestCase
{
    use RefreshDatabase;

    public function test_incident_report_template_uses_shared_folder(): void
    {
        $disposal = new Disposal([
            'disposal_type' => 'lost_stolen_damaged',
        ]);

        $path = app(OwwaTemplateExportService::class)->getDisposalTemplatePath($disposal, 'rlsddp');

        $this->assertSame('Incident report/Appendix 75 - RLSDDP.xls', $path);
    }

    public function test_semi_unserviceable_disposal_uses_semi_iirup_template(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        $disposal = new Disposal([
            'disposal_type' => 'unserviceable',
            'item_id' => $item->id,
        ]);
        $disposal->setRelation('item', $item->load('category'));

        $path = app(OwwaTemplateExportService::class)->getDisposalTemplatePath($disposal);

        $this->assertSame('Semi-Expendable/Disposal/Appendix 74 - IIRUP.xls', $path);
        $this->assertSame('iirup', app(OwwaTemplateExportService::class)->resolveDisposalFormSlug($disposal));
    }

    public function test_disposal_resource_excludes_incident_reports(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $office = Office::factory()->create();
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN, 'office_id' => $office->id]);

        session(['active_item_category_id' => $category->id]);
        $this->actingAs($custodian);

        Disposal::query()->create([
            'reference_code' => '2026-01-0801',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'disposal_date' => now(),
            'disposal_type' => 'unserviceable',
            'recorded_by' => $custodian->id,
        ]);

        Disposal::query()->create([
            'reference_code' => '2026-01-0802',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'disposal_date' => now(),
            'disposal_type' => 'lost_stolen_damaged',
            'property_status' => 'lost',
            'circumstances' => 'Missing.',
            'recorded_by' => $custodian->id,
        ]);

        $this->assertSame(1, DisposalResource::getEloquentQuery()->count());
        $this->assertSame(1, IncidentReportResource::getEloquentQuery()->count());
    }

    public function test_disposal_form_default_type_for_consumables_is_wmr(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        session(['active_item_category_id' => $category->id]);

        $this->assertSame(['waste_sale' => 'Waste or sale (WMR)'], DisposalForm::disposalTypeOptions());
        $this->assertSame('waste_sale', DisposalForm::defaultDisposalType());
    }

    public function test_disposal_form_default_type_for_ppe_is_iirup(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        session(['active_item_category_id' => $category->id]);

        $this->assertSame(['unserviceable' => 'Unserviceable (IIRUP)'], DisposalForm::disposalTypeOptions());
        $this->assertSame('unserviceable', DisposalForm::defaultDisposalType());
    }

    public function test_disposal_form_resolves_category_from_query_when_session_missing(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        session()->forget('active_item_category_id');

        $this->withServerVariables(['QUERY_STRING' => 'category='.$category->id]);
        request()->merge(['category' => $category->id]);

        $this->assertSame('unserviceable', DisposalForm::defaultDisposalType());
    }

    public function test_transfer_resource_blocked_for_consumables_category(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $user = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        session(['active_item_category_id' => $category->id]);
        $this->actingAs($user);
        Filament::auth()->login($user);

        $this->assertFalse(TransferResource::canViewAny());
    }

    public function test_transfer_resource_allowed_for_ppe_category(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $user = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        session(['active_item_category_id' => $category->id]);
        $this->actingAs($user);
        Filament::auth()->login($user);

        $this->assertTrue(TransferResource::canViewAny());
    }
}
