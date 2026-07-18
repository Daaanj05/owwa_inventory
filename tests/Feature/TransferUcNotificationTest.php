<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Transfer;
use App\Models\User;
use App\Notifications\TransferReceivedDatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TransferUcNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_transfer_notifies_destination_office_unit_consolidators(): void
    {
        Notification::fake();

        $from = Office::factory()->create(['name' => 'Regional Supply']);
        $to = Office::factory()->create(['name' => 'Satellite A']);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Office Chair',
        ]);
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $to->id,
            'name' => 'UC Receiver',
        ]);
        User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $from->id,
            'name' => 'UC Sender',
        ]);

        $transfer = Transfer::query()->create([
            'reference_code' => 'PTR-NOTIFY-1',
            'item_id' => $item->id,
            'from_office_id' => $from->id,
            'to_office_id' => $to->id,
            'quantity' => 2,
            'transfer_date' => now()->toDateString(),
            'recorded_by' => $custodian->id,
        ]);

        Notification::assertSentTo(
            $uc,
            TransferReceivedDatabaseNotification::class,
            function (TransferReceivedDatabaseNotification $notification) use ($transfer, $category): bool {
                $this->assertSame('Stock transferred to your office', $notification->title);
                $this->assertStringContainsString($transfer->reference_code, $notification->body);
                $this->assertSame((int) $category->id, $notification->categoryId);
                $this->assertSame($transfer->reference_code, $notification->referenceCode);

                return true;
            },
        );

        Notification::assertNotSentTo(
            User::query()->where('name', 'UC Sender')->first(),
            TransferReceivedDatabaseNotification::class,
        );
    }
}
