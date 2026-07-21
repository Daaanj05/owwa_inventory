<?php

namespace Tests\Feature;

use App\Filament\Resources\Disposals\Actions\DisposalViewActions;
use App\Filament\Resources\IncidentReports\Actions\IncidentReportViewActions;
use App\Filament\Resources\Issuances\Actions\IssuanceViewActions;
use App\Filament\Resources\Transfers\Actions\TransferViewActions;
use App\Models\Disposal;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Transfer;
use App\Models\User;
use App\Services\OwwaTemplateExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class OwwaExportAutoSelectTest extends TestCase
{
    use RefreshDatabase;

    public function test_print_preview_urls_use_pdf_export_routes(): void
    {
        $office = Office::factory()->create();
        $user = User::factory()->create(['office_id' => $office->id]);
        $ppe = ItemCategory::factory()->create(['name' => 'PPE']);
        $item = Item::factory()->create(['item_category_id' => $ppe->id]);

        $transfer = Transfer::query()->create([
            'item_id' => $item->id,
            'from_office_id' => $office->id,
            'to_office_id' => $office->id,
            'quantity' => 1,
            'transfer_date' => now(),
            'recorded_by' => $user->id,
        ]);
        $transfer->setRelation('item', $item->load('category'));

        $transferUrl = $this->resolveActionUrl(TransferViewActions::printViewAction(), $transfer);
        $this->assertStringContainsString('/reports/owwa/transfer/'.$transfer->id, $transferUrl);
        $this->assertStringContainsString('format=pdf', $transferUrl);

        $issuance = new Issuance(['item_id' => $item->id]);
        $issuance->id = 99;
        $issuance->exists = true;
        $issuance->setRelation('item', $item->load('category'));

        $issuanceUrl = $this->resolveActionUrl(IssuanceViewActions::printViewAction(), $issuance);
        $this->assertStringContainsString('/reports/owwa/issuance/99', $issuanceUrl);
        $this->assertStringContainsString('format=pdf', $issuanceUrl);

        $disposal = Disposal::query()->create([
            'reference_code' => '2026-01-0901',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'disposal_date' => now(),
            'disposal_type' => 'unserviceable',
            'recorded_by' => $user->id,
        ]);
        $disposal->setRelation('item', $item->load('category'));

        $disposalUrl = $this->resolveActionUrl(DisposalViewActions::printViewAction(), $disposal);
        $this->assertStringContainsString('/reports/owwa/disposal/'.$disposal->id, $disposalUrl);
        $this->assertStringContainsString('format=pdf', $disposalUrl);
        $this->assertStringContainsString('form=iirup', $disposalUrl);

        $incidentUrl = $this->resolveActionUrl(IncidentReportViewActions::printViewAction(), $disposal);
        $this->assertStringContainsString('form=rlsddp', $incidentUrl);
        $this->assertStringContainsString('format=pdf', $incidentUrl);
    }

    public function test_semi_disposal_print_uses_iirusp_form_slug(): void
    {
        $office = Office::factory()->create();
        $user = User::factory()->create(['office_id' => $office->id]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $disposal = Disposal::query()->create([
            'reference_code' => '2026-01-0902',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'disposal_date' => now(),
            'disposal_type' => 'unserviceable',
            'recorded_by' => $user->id,
        ]);
        $disposal->setRelation('item', $item->load('category'));

        $this->assertSame('iirusp', app(OwwaTemplateExportService::class)->resolveDisposalFormSlug($disposal));

        $url = $this->resolveActionUrl(DisposalViewActions::printViewAction(), $disposal);
        $this->assertStringContainsString('form=iirusp', $url);
    }

    protected function resolveActionUrl(\Filament\Actions\Action $action, object $record): string
    {
        $action->record($record);

        $url = $action->getUrl();
        if (is_string($url) && $url !== '') {
            return $url;
        }

        $method = new ReflectionMethod($action, 'getUrl');
        $result = $method->invoke($action);

        $this->assertIsString($result);

        return $result;
    }
}
