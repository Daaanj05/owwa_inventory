<?php

namespace Tests\Feature;

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
use App\Models\StockOpeningBalance;
use App\Models\User;
use App\Services\InventoryQrLabelService;
use App\Services\InventoryStockService;
use App\Services\OpeningBalanceService;
use App\Services\OwwaItemReportService;
use App\Services\PhysicalCountPreloadService;
use App\Services\PhysicalCountScanService;
use App\Support\InventoryUnitQrPayload;
use App\Support\PhysicalCountScanOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpeningStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_stock_increases_stock_without_acquisition(): void
    {
        [$office, $item, $user] = $this->createConsumableFixtures();

        $result = $this->postOpeningStock(
            item: $item,
            officeId: $office->id,
            quantity: 100,
            unitCost: 10.5,
            recordedBy: $user,
        );

        $this->assertSame(100, $result['opening']->quantity);
        $this->assertSame(0, Acquisition::query()->count());
        $this->assertSame(100, app(InventoryStockService::class)->getStockForUnitCost($item->id, $office->id, 10.5));
        $this->assertDatabaseHas(StockOpeningBalance::class, [
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 100,
        ]);
    }

    public function test_opening_stock_ledger_balance_is_silent_with_no_beginning_row(): void
    {
        [$office, $item, $user] = $this->createConsumableFixtures();

        $this->postOpeningStock(
            item: $item,
            officeId: $office->id,
            quantity: 100,
            unitCost: 10.0,
            recordedBy: $user,
        );

        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => '01',
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => 'REQ-OPEN-0001',
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $user->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'quantity' => 5,
            'unit_cost' => 10.0,
            'issuance_date' => now()->toDateString(),
            'issued_by' => $user->id,
            'issued_to' => $user->id,
            'reference_code' => 'ISS-TEST-0001',
        ]);

        $history = app(OwwaItemReportService::class)->buildTransactionHistory(
            $item,
            $office->id,
            newestFirst: false,
            unitCost: 10.0,
        );

        $this->assertCount(1, $history);
        $this->assertSame('issue', $history[0]['type']);
        $this->assertSame(5, (int) $history[0]['issue_qty']);
        $this->assertSame(95, (int) $history[0]['balance']);
        $this->assertFalse(collect($history)->contains(
            fn (array $row): bool => str_contains(strtolower((string) ($row['reference'] ?? '')), 'opening')
                || str_contains(strtolower((string) ($row['type'] ?? '')), 'beginning'),
        ));
    }

    public function test_ppe_opening_stock_creates_units_with_their_own_property_numbers(): void
    {
        [$office, $item, $user] = $this->createPpeFixtures();

        $result = $this->postOpeningStock(
            item: $item,
            officeId: $office->id,
            quantity: 3,
            unitCost: 75000,
            recordedBy: $user,
        );

        $this->assertCount(3, $result['units']);
        $this->assertSame(3, collect($result['units'])->pluck('property_number')->unique()->count());
        $this->assertTrue(collect($result['units'])->every(
            fn (InventoryUnit $unit): bool => $unit->acquisition_id === null,
        ));
    }

    public function test_duplicate_opening_stock_is_blocked(): void
    {
        [$office, $item, $user] = $this->createConsumableFixtures();

        $this->postOpeningStock(
            item: $item,
            officeId: $office->id,
            quantity: 10,
            unitCost: 5,
            recordedBy: $user,
        );

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->postOpeningStock(
            item: $item,
            officeId: $office->id,
            quantity: 5,
            unitCost: 5,
            recordedBy: $user,
        );
    }

    public function test_two_ppe_units_have_distinct_property_numbers_and_qr_payloads(): void
    {
        [$office, $item, $user] = $this->createPpeFixtures();

        $result = $this->postOpeningStock(
            item: $item,
            officeId: $office->id,
            quantity: 2,
            unitCost: 75000,
            recordedBy: $user,
        );

        $a = $result['units'][0];
        $b = $result['units'][1];

        $this->assertNotSame($a->property_number, $b->property_number);
        $this->assertNotSame(
            InventoryUnitQrPayload::encode($a),
            InventoryUnitQrPayload::encode($b),
        );

        $labels = app(InventoryQrLabelService::class);
        $this->assertNotSame(
            $labels->labelsForUnit($a)->sole()['qr_data_uri'],
            $labels->labelsForUnit($b)->sole()['qr_data_uri'],
        );
    }

    public function test_physical_count_lists_each_ppe_unit_on_its_own_line(): void
    {
        [$office, $item, $user] = $this->createPpeFixtures();

        $result = $this->postOpeningStock(
            item: $item,
            officeId: $office->id,
            quantity: 2,
            unitCost: 75000,
            recordedBy: $user,
        );

        $session = PhysicalCountSession::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $item->item_category_id,
            'count_type' => PhysicalCountSession::TYPE_RPCPPE,
            'count_date' => now()->toDateString(),
            'reference_code' => 'PC-OPEN-001',
            'created_by' => $user->id,
        ]);

        app(PhysicalCountPreloadService::class)->preloadFromCustodyRecords($session);
        $lines = PhysicalCountLine::query()->where('physical_count_session_id', $session->id)->get();
        $this->assertCount(2, $lines);
        $this->assertTrue($lines->every(fn (PhysicalCountLine $line): bool => (int) $line->balance_per_card === 1));

        $scanner = app(PhysicalCountScanService::class);
        $unitA = $result['units'][0];
        $unitB = $result['units'][1];

        $first = $scanner->resolve($session, InventoryUnitQrPayload::encode($unitA));
        $this->assertSame(PhysicalCountScanOutcome::Found, $first->outcome);

        $dupA = $scanner->resolve($session->fresh(), InventoryUnitQrPayload::encode($unitA));
        $this->assertSame(PhysicalCountScanOutcome::Duplicate, $dupA->outcome);

        $second = $scanner->resolve($session->fresh(), InventoryUnitQrPayload::encode($unitB));
        $this->assertSame(PhysicalCountScanOutcome::Found, $second->outcome);
        $this->assertSame(1, (int) $second->line?->fresh()?->on_hand_count);
        $this->assertNotSame($first->line?->id, $second->line?->id);
    }

    public function test_unit_qr_label_reprint_requires_custodian_and_is_logged(): void
    {
        [$office, $item, $user] = $this->createPpeFixtures();

        $unit = $this->postOpeningStock(
            item: $item,
            officeId: $office->id,
            quantity: 1,
            unitCost: 75000,
            recordedBy: $user,
        )['units'][0];

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($employee)
            ->get(route('owwa.qr-labels.unit', $unit))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('owwa.qr-labels.unit', $unit))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertDatabaseHas('user_activity_logs', [
            'user_id' => $user->id,
            'action' => 'reprinted_qr_label',
            'subject_id' => $unit->id,
        ]);
    }

    public function test_disposed_unit_qr_label_cannot_be_reprinted(): void
    {
        [$office, $item, $user] = $this->createPpeFixtures();

        $unit = $this->postOpeningStock(
            item: $item,
            officeId: $office->id,
            quantity: 1,
            unitCost: 75000,
            recordedBy: $user,
        )['units'][0];

        $unit->update(['status' => InventoryUnit::STATUS_DISPOSED]);

        $this->actingAs($user)
            ->get(route('owwa.qr-labels.unit', $unit))
            ->assertNotFound();
    }

    public function test_removed_item_physical_count_and_issuance_qr_routes_are_gone(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('owwa.qr-labels.item'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('owwa.qr-labels.physical-count'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('owwa.qr-labels.issuance'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('owwa.qr-labels.acquisition'));
    }

    public function test_legacy_pn_only_qr_still_resolves_for_physical_count(): void
    {
        [$office, $item, $user] = $this->createPpeFixtures();

        $result = $this->postOpeningStock(
            item: $item,
            officeId: $office->id,
            quantity: 1,
            unitCost: 75000,
            recordedBy: $user,
        );

        $session = PhysicalCountSession::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $item->item_category_id,
            'count_type' => PhysicalCountSession::TYPE_RPCPPE,
            'count_date' => now()->toDateString(),
            'reference_code' => 'PC-LEGACY-001',
            'created_by' => $user->id,
        ]);

        app(PhysicalCountPreloadService::class)->preloadFromCustodyRecords($session);

        $pn = $result['units'][0]->property_number;
        $legacy = 'OWWA|1|pn='.$pn;

        $scan = app(PhysicalCountScanService::class)->resolve($session, $legacy);
        $this->assertSame(PhysicalCountScanOutcome::Found, $scan->outcome);
    }

    /**
     * @return array{opening: StockOpeningBalance, units: array<int, InventoryUnit>}
     */
    protected function postOpeningStock(
        Item $item,
        int $officeId,
        int $quantity,
        ?float $unitCost,
        ?User $recordedBy = null,
    ): array {
        $service = app(OpeningBalanceService::class);
        $draft = $service->createDraftLine(
            item: $item,
            officeId: $officeId,
            quantity: $quantity,
            unitCost: $unitCost,
            recordedBy: $recordedBy,
        );
        $units = $service->mintUnitsForConfirmedLine($draft['opening']);
        app(InventoryStockService::class)->forgetMovementTotalsCache();

        return [
            'opening' => $draft['opening'],
            'units' => $units,
        ];
    }

    /**
     * @return array{0: Office, 1: Item, 2: User}
     */
    protected function createConsumableFixtures(): array
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Bond Paper',
        ]);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        return [$office, $item, $user];
    }

    /**
     * @return array{0: Office, 1: Item, 2: User}
     */
    protected function createPpeFixtures(): array
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Office Table',
            'ppe_property_number' => 'PPE-2026-OPEN',
            'ppe_type' => \App\Support\PpePropertyType::TechnicalScientificEquipment,
        ]);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        return [$office, $item, $user];
    }
}
