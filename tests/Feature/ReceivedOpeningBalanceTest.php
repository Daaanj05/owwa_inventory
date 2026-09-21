<?php

namespace Tests\Feature;

use App\Filament\Resources\Acquisitions\Pages\ListReceivedAcquisitions;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\StockOpeningBalance;
use App\Models\StockOpeningBalanceBatch;
use App\Models\User;
use App\Services\InventoryStockService;
use App\Services\StockOpeningBalanceBatchService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ReceivedOpeningBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_save_draft_then_confirm_posts_stock_and_stamps_date(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $itemA = Item::factory()->create([
            'item_category_id' => $category->id,
            'base_name' => 'Bond Paper',
            'sub_item' => 'A4',
            'name' => 'Bond Paper A4',
            'unit' => 'ream',
        ]);
        $itemB = Item::factory()->create([
            'item_category_id' => $category->id,
            'base_name' => 'Ballpen',
            'sub_item' => 'Blue',
            'name' => 'Ballpen Blue',
            'unit' => 'piece',
        ]);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);
        session(['active_item_category_id' => $category->id]);

        $batch = app(StockOpeningBalanceBatchService::class)->saveDraft(
            lines: [
                [
                    'item_id' => $itemA->id,
                    'quantity' => 40,
                    'unit_cost' => 12.5,
                ],
                [
                    'item_id' => $itemB->id,
                    'quantity' => 100,
                    'unit_cost' => null,
                ],
            ],
            memo: 'Physical count sheet #12',
            itemCategoryId: $category->id,
            recordedBy: $user,
        );

        $this->assertSame($office->id, $batch->office_id);
        $this->assertSame($category->id, $batch->item_category_id);
        $this->assertSame('Physical count sheet #12', $batch->reference);
        $this->assertSame($user->id, $batch->recorded_by);
        $this->assertTrue($batch->isDraft());
        $this->assertNull($batch->recorded_on);
        $this->assertNull($batch->confirmed_at);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{4}$/', (string) $batch->reference_code);
        $this->assertSame(2, $batch->lines()->count());

        $stock = app(InventoryStockService::class);
        $this->assertSame(0, $stock->getStockForUnitCost($itemA->id, $office->id, 12.5));
        $this->assertSame(0, $stock->getStockForUnitCost($itemB->id, $office->id, null));

        $confirmed = app(StockOpeningBalanceBatchService::class)->confirm($batch);

        $this->assertTrue($confirmed->isConfirmed());
        $this->assertNotNull($confirmed->confirmed_at);
        $this->assertTrue($confirmed->recorded_on->isSameDay(now()));

        $this->assertDatabaseHas(StockOpeningBalance::class, [
            'batch_id' => $confirmed->id,
            'item_id' => $itemA->id,
            'office_id' => $office->id,
            'quantity' => 40,
            'unit_cost' => 12.5,
        ]);
        $this->assertDatabaseHas(StockOpeningBalance::class, [
            'batch_id' => $confirmed->id,
            'item_id' => $itemB->id,
            'office_id' => $office->id,
            'quantity' => 100,
        ]);

        $this->assertSame(40, $stock->getStockForUnitCost($itemA->id, $office->id, 12.5));
        $this->assertSame(100, $stock->getStockForUnitCost($itemB->id, $office->id, null));

        Livewire::withQueryParams(['category' => (string) $category->id])
            ->test(ListReceivedAcquisitions::class)
            ->set('showingOpeningBalances', true)
            ->assertCanSeeTableRecords([$confirmed]);
    }

    public function test_confirm_twice_is_rejected(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Folder Long',
            'unit' => 'piece',
        ]);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $batch = app(StockOpeningBalanceBatchService::class)->saveDraft(
            lines: [
                [
                    'item_id' => $item->id,
                    'quantity' => 5,
                    'unit_cost' => 2,
                ],
            ],
            memo: null,
            itemCategoryId: $category->id,
            recordedBy: $user,
        );

        app(StockOpeningBalanceBatchService::class)->confirm($batch);

        $this->expectException(ValidationException::class);
        app(StockOpeningBalanceBatchService::class)->confirm($batch->fresh());
    }

    public function test_record_opening_balance_modal_saves_draft_from_received_page(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Folder Long',
            'unit' => 'piece',
        ]);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);
        session(['active_item_category_id' => $category->id]);

        $undoRepeaterFake = Repeater::fake();

        Livewire::withQueryParams(['category' => (string) $category->id])
            ->test(ListReceivedAcquisitions::class)
            ->set('showingOpeningBalances', true)
            ->callAction('recordOpeningBalance', [
                'reference' => 'Physical count',
                'lines' => [
                    [
                        'item_id' => $item->id,
                        'quantity' => 15,
                        'unit_cost' => 5,
                    ],
                ],
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $undoRepeaterFake();

        $batch = StockOpeningBalanceBatch::query()
            ->where('reference', 'Physical count')
            ->where('office_id', $office->id)
            ->first();

        $this->assertNotNull($batch);
        $this->assertTrue($batch->isDraft());
        $this->assertNull($batch->recorded_on);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{4}$/', (string) $batch->reference_code);
        $this->assertDatabaseHas(StockOpeningBalance::class, [
            'batch_id' => $batch->id,
            'item_id' => $item->id,
            'quantity' => 15,
            'unit_cost' => 5,
        ]);
        $this->assertSame(0, app(InventoryStockService::class)->getStockForUnitCost($item->id, $office->id, 5));
    }

    public function test_confirm_action_from_opening_balances_table_posts_stock(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Folder Long',
            'unit' => 'piece',
        ]);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);
        session(['active_item_category_id' => $category->id]);

        $batch = app(StockOpeningBalanceBatchService::class)->saveDraft(
            lines: [
                [
                    'item_id' => $item->id,
                    'quantity' => 15,
                    'unit_cost' => 5,
                ],
            ],
            memo: 'Sheet #3',
            itemCategoryId: $category->id,
            recordedBy: $user,
        );

        Livewire::withQueryParams(['category' => (string) $category->id])
            ->test(ListReceivedAcquisitions::class)
            ->set('showingOpeningBalances', true)
            ->callAction(TestAction::make('confirm')->table($batch))
            ->assertNotified();

        $batch->refresh();
        $this->assertTrue($batch->isConfirmed());
        $this->assertTrue($batch->recorded_on->isSameDay(now()));
        $this->assertSame(15, app(InventoryStockService::class)->getStockForUnitCost($item->id, $office->id, 5));
    }

    public function test_record_opening_balance_action_hidden_on_received_view(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);
        session(['active_item_category_id' => $category->id]);

        Livewire::withQueryParams(['category' => (string) $category->id])
            ->test(ListReceivedAcquisitions::class)
            ->assertSet('showingOpeningBalances', false)
            ->assertActionHidden('recordOpeningBalance')
            ->set('showingOpeningBalances', true)
            ->assertActionVisible('recordOpeningBalance');
    }

    public function test_eligible_item_options_exclude_items_with_opening_stock(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $eligible = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Eligible Item',
            'unit' => 'piece',
        ]);
        $ineligible = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Already Opened',
            'unit' => 'piece',
        ]);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $batch = app(StockOpeningBalanceBatchService::class)->saveDraft(
            lines: [
                [
                    'item_id' => $ineligible->id,
                    'quantity' => 3,
                    'unit_cost' => 1,
                ],
            ],
            memo: null,
            itemCategoryId: $category->id,
            recordedBy: $user,
        );
        app(StockOpeningBalanceBatchService::class)->confirm($batch);

        $options = \App\Filament\Resources\Acquisitions\Tables\OpeningBalanceBatchesTable::eligibleItemOptions(
            (int) $category->id,
        );

        $this->assertArrayHasKey($eligible->id, $options);
        $this->assertArrayNotHasKey($ineligible->id, $options);
    }

    public function test_save_draft_rejects_item_with_existing_opening_stock(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Folder Long',
            'unit' => 'piece',
        ]);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $first = app(StockOpeningBalanceBatchService::class)->saveDraft(
            lines: [
                [
                    'item_id' => $item->id,
                    'quantity' => 5,
                    'unit_cost' => 2,
                ],
            ],
            memo: null,
            itemCategoryId: $category->id,
            recordedBy: $user,
        );
        app(StockOpeningBalanceBatchService::class)->confirm($first);

        $this->expectException(ValidationException::class);

        app(StockOpeningBalanceBatchService::class)->saveDraft(
            lines: [
                [
                    'item_id' => $item->id,
                    'quantity' => 1,
                    'unit_cost' => 2,
                ],
            ],
            memo: null,
            itemCategoryId: $category->id,
            recordedBy: $user,
        );
    }
}
