<?php

namespace Tests\Unit;

use App\Filament\Resources\Transfers\TransferCustodyQuery;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransferCustodyQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_custodian_sees_all_category_transfers_regardless_of_office(): void
    {
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $home = Office::factory()->create();
        $other = Office::factory()->create();
        $elsewhere = Office::factory()->create();

        session(['active_item_category_id' => $category->id]);

        $involvingHome = Transfer::query()->create([
            'reference_code' => 'TR-HOME-OUT',
            'item_id' => $item->id,
            'from_office_id' => $home->id,
            'to_office_id' => $other->id,
            'quantity' => 1,
            'transfer_date' => now()->toDateString(),
        ]);

        $involvingHomeIn = Transfer::query()->create([
            'reference_code' => 'TR-HOME-IN',
            'item_id' => $item->id,
            'from_office_id' => $other->id,
            'to_office_id' => $home->id,
            'quantity' => 1,
            'transfer_date' => now()->toDateString(),
        ]);

        $satelliteTransfer = Transfer::query()->create([
            'reference_code' => 'TR-OTHER',
            'item_id' => $item->id,
            'from_office_id' => $other->id,
            'to_office_id' => $elsewhere->id,
            'quantity' => 1,
            'transfer_date' => now()->toDateString(),
        ]);

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $home->id,
        ]);

        $this->actingAs($custodian);

        $visible = TransferCustodyQuery::apply(Transfer::query())->pluck('id')->all();

        $this->assertContains($involvingHome->id, $visible);
        $this->assertContains($involvingHomeIn->id, $visible);
        $this->assertContains($satelliteTransfer->id, $visible);
        $this->assertCount(3, $visible);
    }
}
