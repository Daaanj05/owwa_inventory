<?php

namespace Tests\Unit;

use App\Models\Acquisition;
use App\Models\Department;
use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PhysicalCountLine;
use App\Models\PhysicalCountSession;
use App\Models\Requisition;
use App\Models\User;
use App\Services\AcquisitionUnitService;
use App\Services\InventoryQrLabelService;
use App\Services\OwwaItemReportService;
use App\Services\PhysicalCountCompletionService;
use App\Services\PhysicalCountPreloadService;
use App\Services\PhysicalCountScanService;
use App\Support\InventoryUnitQrPayload;
use App\Support\PhysicalCountScanOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class PhysicalCountQrTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_property_number_strips_optional_prefix(): void
    {
        $service = app(PhysicalCountScanService::class);

        $this->assertSame('PPE-2026-0001', $service->normalizePropertyNumber('OWWA:PN:PPE-2026-0001'));
        $this->assertSame('PPE-2026-0001', $service->normalizePropertyNumber('  PPE-2026-0001  '));
    }

    public function test_qr_payload_round_trip(): void
    {
        $unit = new InventoryUnit([
            'property_number' => 'PPE-2026-0099',
            'item_id' => 12,
            'office_id' => 3,
            'stock_number' => 'PPE-001',
        ]);

        $legacy = InventoryUnitQrPayload::encodeLegacy($unit);
        $parsed = InventoryUnitQrPayload::parse($legacy);

        $this->assertNotNull($parsed);
        $this->assertSame('PPE-2026-0099', $parsed->propertyNumber);
        $this->assertSame(12, $parsed->itemId);
        $this->assertSame(3, $parsed->officeId);
        $this->assertSame('PPE-001', $parsed->stockNumber);
        $this->assertSame('PPE-2026-0099', app(PhysicalCountScanService::class)->normalizePropertyNumber($legacy));
    }

    public function test_normalize_property_number_parses_public_asset_url(): void
    {
        config(['inventory.qr_public_lookup' => true]);

        $unit = new InventoryUnit([
            'property_number' => 'PPE-2026-0099',
            'item_id' => 12,
            'office_id' => 3,
        ]);

        $url = InventoryUnitQrPayload::encode($unit);
        $service = app(PhysicalCountScanService::class);

        $this->assertSame('PPE-2026-0099', $service->normalizePropertyNumber($url));
    }

    public function test_acquisition_creates_inventory_units_for_ppe(): void
    {
        [$office, $category, $item, $user] = $this->createPpeFixtures();

        $acquisition = Acquisition::query()->create([
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 3,
            'unit_cost' => 75000,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);

        $units = $acquisition->inventoryUnits()->get();

        $this->assertCount(3, $units);
        $this->assertTrue($units->every(fn (InventoryUnit $unit): bool => $unit->status === InventoryUnit::STATUS_IN_STOCK));
        $this->assertSame(3, $units->pluck('property_number')->unique()->count());
    }

    public function test_preload_creates_expected_lines_from_inventory_units(): void
    {
        [$session, $unit] = $this->createPpeSessionWithUnit();

        $result = app(PhysicalCountPreloadService::class)->preloadFromCustodyRecords($session);

        $this->assertSame(1, $result['created']);
        $session->refresh();
        $this->assertTrue($session->hasBookListLoaded());
        $line = PhysicalCountLine::query()->where('physical_count_session_id', $session->id)->first();
        $this->assertNotNull($line);
        $this->assertSame($unit->property_number, $line->property_number);
        $this->assertSame(1, $line->balance_per_card);
        $this->assertSame(0, $line->on_hand_count);
    }

    public function test_preload_creates_expected_lines_from_issuances_when_no_units(): void
    {
        [$session, $issuance] = $this->createPpeSessionWithIssuance('PPE-2026-0100');

        $result = app(PhysicalCountPreloadService::class)->preloadFromCustodyRecords($session);

        $this->assertSame(1, $result['created']);
        $line = PhysicalCountLine::query()->where('physical_count_session_id', $session->id)->first();
        $this->assertNotNull($line);
        $this->assertSame('PPE-2026-0100', $line->property_number);
        $this->assertSame($issuance->item_id, $line->item_id);
    }

    public function test_scan_marks_expected_property_as_found(): void
    {
        [$session, $unit] = $this->createPpeSessionWithUnit();
        app(PhysicalCountPreloadService::class)->preloadFromCustodyRecords($session);

        $payload = InventoryUnitQrPayload::encode($unit);
        $result = app(PhysicalCountScanService::class)->resolve($session, $payload);

        $this->assertSame(PhysicalCountScanOutcome::Found, $result->outcome);
        $this->assertSame(1, $result->line?->fresh()?->on_hand_count);
    }

    public function test_scan_returns_duplicate_when_property_already_counted(): void
    {
        [$session, $unit] = $this->createPpeSessionWithUnit();
        app(PhysicalCountPreloadService::class)->preloadFromCustodyRecords($session);
        $scanner = app(PhysicalCountScanService::class);
        $scanner->resolve($session, InventoryUnitQrPayload::encode($unit));

        $duplicate = $scanner->resolve($session->fresh(), InventoryUnitQrPayload::encode($unit));

        $this->assertSame(PhysicalCountScanOutcome::Duplicate, $duplicate->outcome);
        $this->assertSame('Already scanned.', $duplicate->message);
    }

    public function test_scan_first_creates_found_line_without_preload(): void
    {
        [$session, $unit] = $this->createPpeSessionWithUnit();

        $result = app(PhysicalCountScanService::class)->resolve($session, InventoryUnitQrPayload::encode($unit));

        $this->assertSame(PhysicalCountScanOutcome::Found, $result->outcome);
        $this->assertSame(1, $result->line?->on_hand_count);
        $this->assertSame(1, $result->line?->balance_per_card);
        $this->assertFalse($session->fresh()->hasBookListLoaded());
    }

    public function test_scan_creates_overage_line_after_book_list_loaded(): void
    {
        [$session, $unit] = $this->createPpeSessionWithUnit();
        app(PhysicalCountPreloadService::class)->preloadFromCustodyRecords($session);

        $extraUnit = InventoryUnit::query()->create([
            'acquisition_id' => $unit->acquisition_id,
            'item_id' => $unit->item_id,
            'office_id' => $unit->office_id,
            'property_number' => 'PPE-2026-9999',
            'stock_number' => $unit->stock_number,
            'status' => InventoryUnit::STATUS_IN_STOCK,
        ]);

        $overage = app(PhysicalCountScanService::class)->resolve($session->fresh(), InventoryUnitQrPayload::encode($extraUnit));

        $this->assertSame(PhysicalCountScanOutcome::Overage, $overage->outcome);
        $this->assertSame(1, $overage->line?->on_hand_count);
        $this->assertSame(0, $overage->line?->balance_per_card);
    }

    public function test_completion_service_blocks_complete_without_book_list(): void
    {
        [$session, $unit] = $this->createPpeSessionWithUnit();
        app(PhysicalCountScanService::class)->resolve($session, InventoryUnitQrPayload::encode($unit));

        $session->update([
            'fund_cluster' => '01',
            'accountable_officer_name' => 'Officer',
            'inventory_type_label' => 'ICT',
            'certified_by_printed_name' => 'A',
            'approved_by_printed_name' => 'B',
            'verified_by_printed_name' => 'C',
        ]);

        $evaluation = app(PhysicalCountCompletionService::class)->evaluate($session->fresh());

        $this->assertFalse($evaluation['can_complete']);
        $this->assertTrue($evaluation['needs_book_list']);
    }

    public function test_completion_service_blocks_complete_with_shortages(): void
    {
        [$session] = $this->createPpeSessionWithUnit();
        app(PhysicalCountPreloadService::class)->preloadFromCustodyRecords($session);

        $session->update([
            'fund_cluster' => '01',
            'accountable_officer_name' => 'Officer',
            'inventory_type_label' => 'ICT',
            'certified_by_printed_name' => 'A',
            'approved_by_printed_name' => 'B',
            'verified_by_printed_name' => 'C',
        ]);

        $evaluation = app(PhysicalCountCompletionService::class)->evaluate($session->fresh());

        $this->assertFalse($evaluation['can_complete']);
        $this->assertTrue($evaluation['has_shortages']);
    }

    public function test_completion_service_marks_complete_when_tally_and_header_ok(): void
    {
        [$session, $unit] = $this->createPpeSessionWithUnit();
        app(PhysicalCountPreloadService::class)->preloadFromCustodyRecords($session);
        app(PhysicalCountScanService::class)->resolve($session, InventoryUnitQrPayload::encode($unit));

        $session->update([
            'fund_cluster' => '01',
            'accountable_officer_name' => 'Officer',
            'inventory_type_label' => 'ICT',
            'certified_by_printed_name' => 'A',
            'approved_by_printed_name' => 'B',
            'verified_by_printed_name' => 'C',
        ]);

        $completed = app(PhysicalCountCompletionService::class)->markComplete($session->fresh());

        $this->assertTrue($completed->isComplete());
        $this->assertNotNull($completed->completed_at);
    }

    public function test_finish_counting_sets_incomplete_status(): void
    {
        [$session] = $this->createPpeSessionWithUnit();

        $finished = app(PhysicalCountCompletionService::class)->finishCounting($session);

        $this->assertTrue($finished->isIncomplete());
    }

    public function test_qr_label_service_returns_png_data_uri(): void
    {
        $dataUri = app(InventoryQrLabelService::class)->qrCodeDataUri('OWWA|1|pn=PPE-2026-0500|item=1|office=1');

        $this->assertStringStartsWith('data:image/png;base64,', $dataUri);
    }

    public function test_rpcsp_export_includes_accountable_officer_and_entity_name(): void
    {
        $office = Office::factory()->create(['name' => 'Regional Office', 'fund_cluster' => '01']);
        $session = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCSP,
            'office_id' => $office->id,
            'count_date' => now(),
            'inventory_type_label' => 'ICT',
            'accountable_officer_name' => 'Officer A',
            'accountable_officer_designation' => 'Supply Officer',
            'date_of_assumption' => now(),
        ]);

        $method = new ReflectionMethod(OwwaItemReportService::class, 'cellValuesForPhysicalCount');
        $values = $method->invoke(app(OwwaItemReportService::class), $session->fresh(['office', 'lines']));

        $this->assertStringContainsString('Regional Office', (string) ($values['A6'] ?? ''));
        $this->assertStringContainsString('Officer A', (string) ($values['B10'] ?? ''));
    }

    public function test_acquisition_unit_service_is_idempotent(): void
    {
        [$office, $category, $item, $user] = $this->createPpeFixtures();

        $acquisition = Acquisition::query()->create([
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 2,
            'unit_cost' => 75000,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);

        app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition);
        app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition->fresh());

        $this->assertSame(2, $acquisition->inventoryUnits()->count());
    }

    /**
     * @return array{0: PhysicalCountSession, 1: Issuance}
     */
    protected function createPpeSessionWithIssuance(string $propertyNumber): array
    {
        [$office, $category, $item, $user] = $this->createPpeFixtures();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => '01',
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-'.fake()->unique()->numerify('####'),
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $user->id,
            'status' => 'pending',
        ]);

        $issuance = Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'property_number' => $propertyNumber,
            'issuance_date' => now(),
            'issued_by' => $user->id,
            'issued_to' => $user->id,
        ]);

        $session = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCPPE,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
            'inventory_type_label' => 'ICT',
        ]);

        return [$session, $issuance];
    }

    /**
     * @return array{0: PhysicalCountSession, 1: InventoryUnit}
     */
    protected function createPpeSessionWithUnit(): array
    {
        [$office, $category, $item, $user] = $this->createPpeFixtures();

        $acquisition = Acquisition::query()->create([
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'unit_cost' => 75000,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);

        $unit = $acquisition->inventoryUnits()->first();
        $this->assertNotNull($unit);

        $session = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCPPE,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
            'inventory_type_label' => 'ICT',
        ]);

        return [$session, $unit];
    }

    /**
     * @return array{0: Office, 1: ItemCategory, 2: Item, 3: User}
     */
    protected function createPpeFixtures(): array
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $user = User::factory()->create();

        return [$office, $category, $item, $user];
    }
}
