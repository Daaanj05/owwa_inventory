<?php

namespace Database\Seeders;

use App\Models\Acquisition;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PhysicalCountLine;
use App\Models\PhysicalCountSession;
use App\Models\User;
use App\Services\AcquisitionUnitService;
use App\Services\PhysicalCountPreloadService;
use App\Support\ItemPropertyClass;
use App\Support\PhysicalCountPropertyClassResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class PhysicalCountExportFixturesSeeder extends Seeder
{
    public function run(): void
    {
        $office = Office::query()->firstWhere('code', 'OWWA-IVA')
            ?? Office::factory()->create([
                'code' => 'OWWA-IVA',
                'name' => 'OWWA Regional Office IV-A',
                'fund_cluster' => '01',
            ]);

        $office->update([
            'fund_cluster' => '01',
            'accountable_officer_name' => 'Marita C. Ablis',
            'accountable_officer_designation' => 'Supply Officer',
            'supply_custodian_name' => 'Supply Custodian',
            'authorized_officer_name' => 'Roberto Cruz',
        ]);

        $custodian = User::query()->where('email', 'custodian@owwa.gov.ph')->firstOrFail();

        $consumables = ItemCategory::query()->where('name', 'Consumables')->firstOrFail();
        $semi = ItemCategory::query()->where('name', 'Semi-Expendable')->firstOrFail();
        $ppe = ItemCategory::query()->where('name', 'Property, Plant and Equipment')->firstOrFail();

        $this->seedConsumableExportItems($consumables, $office, $custodian);
        $this->seedSemiExportItems($semi, $office, $custodian);
        $this->seedPpeExportUnits($ppe, $office, $custodian);

        $this->seedRpciSession($consumables, $office, $custodian);
        $this->seedRpcspSession($semi, $office, $custodian);
        $this->seedRpcppeSession($ppe, $office, $custodian);
    }

    protected function seedConsumableExportItems(ItemCategory $category, Office $office, User $custodian): void
    {
        for ($index = 1; $index <= 70; $index++) {
            $code = sprintf('CON-EXPORT-%03d', $index);

            $item = Item::updateOrCreate(
                ['item_code' => $code],
                [
                    'item_category_id' => $category->id,
                    'name' => "Export Consumable {$index}",
                    'unit' => 'piece',
                    'reorder_level' => 5,
                ],
            );

            Acquisition::updateOrCreate(
                ['reference_code' => 'ACQ-'.$code],
                [
                    'item_id' => $item->id,
                    'office_id' => $office->id,
                    'quantity' => 20 + ($index % 10),
                    'unit_cost' => 10 + ($index * 2.5),
                    'acquisition_date' => Carbon::parse('2026-06-01'),
                    'recorded_by' => $custodian->id,
                ],
            );
        }
    }

    /**
     * @return array<int, Item>
     */
    protected function seedSemiExportItems(ItemCategory $category, Office $office, User $custodian): array
    {
        $propertyClasses = [
            ItemPropertyClass::Ict,
            ItemPropertyClass::OfficeEquipment,
            ItemPropertyClass::FurnituresFixtures,
            ItemPropertyClass::SportsEquipment,
            ItemPropertyClass::MedicalEquipment,
            ItemPropertyClass::VehicleEquipment,
        ];

        $items = [];
        $counter = 1;

        foreach ($propertyClasses as $propertyClass) {
            $perClass = $propertyClass === ItemPropertyClass::OfficeEquipment ? 25 : 9;

            for ($i = 1; $i <= $perClass; $i++) {
                $code = sprintf('SEM-EXPORT-%03d', $counter);
                $quantity = 3 + ($counter % 5);

                $item = Item::updateOrCreate(
                    ['item_code' => $code],
                    [
                        'item_category_id' => $category->id,
                        'name' => "Export Semi Item {$counter}",
                        'unit' => 'piece',
                        'property_class' => $propertyClass,
                        'reorder_level' => 1,
                    ],
                );

                $acquisition = Acquisition::updateOrCreate(
                    ['reference_code' => 'ACQ-'.$code],
                    [
                        'item_id' => $item->id,
                        'office_id' => $office->id,
                        'quantity' => $quantity,
                        'unit_cost' => 4500 + ($counter * 10),
                        'acquisition_date' => Carbon::parse('2026-06-15'),
                        'recorded_by' => $custodian->id,
                    ],
                );

                app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition->fresh(['item.category', 'office']));

                $items[] = $item->fresh();
                $counter++;
            }
        }

        return $items;
    }

    protected function seedPpeExportUnits(ItemCategory $category, Office $office, User $custodian): void
    {
        $types = [
            ['code' => 'PPE-EXPORT-LAP', 'name' => 'Export Laptop', 'qty' => 30, 'cost' => 55000],
            ['code' => 'PPE-EXPORT-DSK', 'name' => 'Export Office Desk', 'qty' => 25, 'cost' => 75000],
            ['code' => 'PPE-EXPORT-PRT', 'name' => 'Export Printer', 'qty' => 15, 'cost' => 85000],
        ];

        foreach ($types as $type) {
            $item = Item::updateOrCreate(
                ['item_code' => $type['code']],
                [
                    'item_category_id' => $category->id,
                    'name' => $type['name'],
                    'unit' => 'unit',
                    'reorder_level' => 1,
                ],
            );

            $acquisition = Acquisition::updateOrCreate(
                ['reference_code' => 'ACQ-'.$type['code']],
                [
                    'item_id' => $item->id,
                    'office_id' => $office->id,
                    'quantity' => $type['qty'],
                    'unit_cost' => $type['cost'],
                    'acquisition_date' => Carbon::parse('2026-06-10'),
                    'recorded_by' => $custodian->id,
                ],
            );

            app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition->fresh(['item.category', 'office']));
        }
    }

    protected function seedRpciSession(ItemCategory $category, Office $office, User $custodian): void
    {
        $session = PhysicalCountSession::updateOrCreate(
            ['reference_code' => 'PC-EXPORT-RPCI-2026'],
            $this->sessionDefaults($category, $office, $custodian, PhysicalCountSession::TYPE_RPCI, [
                'inventory_type_label' => 'Office Supplies Inventory',
            ]),
        );

        PhysicalCountLine::query()->where('physical_count_session_id', $session->id)->delete();

        $items = Item::query()
            ->where('item_category_id', $category->id)
            ->where('item_code', 'like', 'CON-EXPORT-%')
            ->orderBy('item_code')
            ->get();

        foreach ($items as $index => $item) {
            $balance = 10 + ($index % 8);
            $onHand = match ($index % 7) {
                0 => $balance - 2,
                1 => $balance + 1,
                default => $balance,
            };

            PhysicalCountLine::query()->create([
                'physical_count_session_id' => $session->id,
                'item_id' => $item->id,
                'article' => $item->name,
                'stock_number' => $item->item_code,
                'unit_of_measure' => $item->unit,
                'balance_per_card' => $balance,
                'on_hand_count' => max(0, $onHand),
            ]);
        }
    }

    protected function seedRpcspSession(ItemCategory $category, Office $office, User $custodian): void
    {
        $session = PhysicalCountSession::updateOrCreate(
            ['reference_code' => 'PC-EXPORT-RPCSP-2026'],
            $this->sessionDefaults($category, $office, $custodian, PhysicalCountSession::TYPE_RPCSP, [
                'book_list_loaded' => false,
            ]),
        );

        PhysicalCountLine::query()->where('physical_count_session_id', $session->id)->delete();

        app(PhysicalCountPreloadService::class)->preloadFromCustodyRecords($session->fresh());

        foreach ($session->fresh()->lines as $line) {
            $shortfall = $line->id % 5 === 0 ? 1 : 0;
            $line->update([
                'on_hand_count' => max(0, (int) $line->balance_per_card - $shortfall),
            ]);
        }

        PhysicalCountPropertyClassResolver::syncSession($session->fresh(['lines.item']));
    }

    protected function seedRpcppeSession(ItemCategory $category, Office $office, User $custodian): void
    {
        $session = PhysicalCountSession::updateOrCreate(
            ['reference_code' => 'PC-EXPORT-RPCPPE-2026'],
            $this->sessionDefaults($category, $office, $custodian, PhysicalCountSession::TYPE_RPCPPE, [
                'book_list_loaded' => false,
            ]),
        );

        PhysicalCountLine::query()->where('physical_count_session_id', $session->id)->delete();

        app(PhysicalCountPreloadService::class)->preloadFromCustodyRecords($session->fresh());

        foreach ($session->fresh()->lines as $line) {
            $shortfall = $line->id % 6 === 0 ? 1 : 0;
            $line->update([
                'on_hand_count' => max(0, (int) $line->balance_per_card - $shortfall),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function sessionDefaults(
        ItemCategory $category,
        Office $office,
        User $custodian,
        string $countType,
        array $extra = [],
    ): array {
        return array_merge([
            'count_type' => $countType,
            'status' => PhysicalCountSession::STATUS_COMPLETE,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => Carbon::parse('2026-06-30'),
            'fund_cluster' => '01',
            'accountable_officer_name' => 'Marita C. Ablis',
            'accountable_officer_designation' => 'Supply Officer',
            'date_of_assumption' => Carbon::parse('2026-01-01'),
            'certified_by_printed_name' => 'Maria Santos',
            'approved_by_printed_name' => 'Roberto Cruz',
            'verified_by_printed_name' => 'COA Rep. Ana Reyes',
            'recorded_by' => $custodian->id,
            'completed_at' => now(),
        ], $extra);
    }
}
