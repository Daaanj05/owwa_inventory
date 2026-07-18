<?php

namespace Tests\Unit;

use App\Models\Acquisition;
use App\Models\InventoryUnit;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\ProcurementSignatoryName;
use App\Models\Transfer;
use App\Models\User;
use App\Services\AcquisitionUnitService;
use App\Services\TransferInventoryUnitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransferInventoryUnitServiceTest extends TestCase
{
    use RefreshDatabase;

    private TransferInventoryUnitService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TransferInventoryUnitService::class);
    }

    public function test_sync_moves_quantity_of_units_even_when_property_number_is_set(): void
    {
        ['item' => $item, 'from' => $from, 'to' => $to, 'units' => $units, 'user' => $user] = $this->seedUnits('Semi-Expendable', 3, 4500);

        $transfer = Transfer::withoutEvents(fn (): Transfer => Transfer::query()->create([
            'reference_code' => 'TR-QTY-1',
            'item_id' => $item->id,
            'from_office_id' => $from->id,
            'to_office_id' => $to->id,
            'quantity' => 2,
            'property_number' => $units[0]->property_number,
            'transfer_date' => now()->toDateString(),
            'recorded_by' => $user->id,
        ]));

        $this->service->syncUnitsForTransfer($transfer);

        $this->assertSame($to->id, $units[0]->fresh()->office_id);
        $this->assertSame($to->id, $units[1]->fresh()->office_id);
        $this->assertSame($from->id, $units[2]->fresh()->office_id);
    }

    public function test_sync_moves_selected_inventory_unit_by_id_when_quantity_is_one(): void
    {
        ['item' => $item, 'from' => $from, 'to' => $to, 'units' => $units, 'user' => $user] = $this->seedUnits('Semi-Expendable', 3, 4500);
        $selected = $units[1];

        $transfer = Transfer::withoutEvents(fn (): Transfer => Transfer::query()->create([
            'reference_code' => 'TR-UNIT-1',
            'item_id' => $item->id,
            'inventory_unit_id' => $selected->id,
            'from_office_id' => $from->id,
            'to_office_id' => $to->id,
            'quantity' => 1,
            'property_number' => $selected->property_number,
            'transfer_date' => now()->toDateString(),
            'recorded_by' => $user->id,
        ]));

        $this->service->syncUnitsForTransfer($transfer);

        $this->assertSame($to->id, $selected->fresh()->office_id);
        $this->assertSame($from->id, $units[0]->fresh()->office_id);
        $this->assertSame($from->id, $units[2]->fresh()->office_id);
    }

    public function test_saving_transfer_remembers_signatory_names(): void
    {
        ['item' => $item, 'from' => $from, 'to' => $to, 'user' => $user] = $this->seedUnits('Semi-Expendable', 1, 4500);

        Transfer::withoutEvents(function () use ($item, $from, $to, $user): void {
            $transfer = Transfer::query()->create([
                'reference_code' => 'TR-SIG-1',
                'item_id' => $item->id,
                'from_office_id' => $from->id,
                'to_office_id' => $to->id,
                'quantity' => 1,
                'transfer_date' => now()->toDateString(),
                'approved_by_printed_name' => 'Ana Approved',
                'approved_by_designation' => 'Chief',
                'released_by_printed_name' => 'Rita Released',
                'released_by_designation' => 'Custodian',
                'received_by_printed_name' => 'Rex Received',
                'received_by_designation' => 'Officer',
                'from_accountable_officer' => 'From Officer',
                'to_accountable_officer' => 'To Officer',
                'recorded_by' => $user->id,
            ]);

            $transfer->rememberSignatoryNames();
        });

        $this->assertContains('Ana Approved', ProcurementSignatoryName::suggestionsForRole(
            ProcurementSignatoryName::ROLE_TRANSFER_APPROVED,
        ));
        $this->assertContains('Chief', ProcurementSignatoryName::suggestionsForRole(
            ProcurementSignatoryName::ROLE_TRANSFER_APPROVED_DESIGNATION,
        ));
        $this->assertContains('From Officer', ProcurementSignatoryName::suggestionsForRole(
            ProcurementSignatoryName::ROLE_TRANSFER_FROM_ACCOUNTABLE,
        ));
    }

    /**
     * @return array{item: Item, from: Office, to: Office, units: list<InventoryUnit>, user: User}
     */
    private function seedUnits(string $categoryName, int $quantity, float $unitCost): array
    {
        $from = Office::factory()->create(['code' => '01']);
        $to = Office::factory()->create(['code' => '02']);
        $category = ItemCategory::query()->firstOrCreate(
            ['name' => $categoryName],
            ['description' => $categoryName],
        );
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $user = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        $acquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-TR-'.$quantity.'-'.str_replace(' ', '', $categoryName),
            'item_id' => $item->id,
            'office_id' => $from->id,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);

        app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition->fresh(['item.category', 'office']));

        $units = InventoryUnit::query()
            ->where('acquisition_id', $acquisition->id)
            ->orderBy('id')
            ->get()
            ->all();

        $this->assertCount($quantity, $units);

        return [
            'item' => $item,
            'from' => $from,
            'to' => $to,
            'units' => $units,
            'user' => $user,
        ];
    }
}
