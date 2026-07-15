<?php

namespace Tests\Unit;

use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Support\NotificationRecipientResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationRecipientResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_supply_custodians_for_regional_office_returns_only_regional_custodians(): void
    {
        $regionalOffice = Office::factory()->create(['is_regional_supply' => true]);
        $otherOffice = Office::factory()->create(['is_regional_supply' => false]);

        $regionalCustodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $regionalOffice->id,
        ]);
        User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $otherOffice->id,
        ]);

        $recipients = app(NotificationRecipientResolver::class)->supplyCustodiansForRegionalOffice();

        $this->assertCount(1, $recipients);
        $this->assertTrue($recipients->contains('id', $regionalCustodian->id));
    }

    public function test_supply_custodians_for_office_scopes_to_office(): void
    {
        $officeA = Office::factory()->create();
        $officeB = Office::factory()->create();

        $custodianA = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $officeA->id,
        ]);
        User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $officeB->id,
        ]);

        $recipients = app(NotificationRecipientResolver::class)->supplyCustodiansForOffice($officeA->id);

        $this->assertCount(1, $recipients);
        $this->assertTrue($recipients->contains('id', $custodianA->id));
    }

    public function test_eul_reminder_recipients_include_accountable_user_not_unrelated_custodian(): void
    {
        $regionalOffice = Office::factory()->create(['is_regional_supply' => true]);
        $otherOffice = Office::factory()->create();
        $unrelatedOffice = Office::factory()->create();

        $regionalCustodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $regionalOffice->id,
        ]);
        $otherCustodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $unrelatedOffice->id,
        ]);
        $accountable = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $otherOffice->id,
        ]);

        $category = ItemCategory::factory()->create(['name' => 'Semi']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        $requisition = Requisition::query()->create([
            'office_id' => $otherOffice->id,
            'requested_by' => $accountable->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $issuance = Issuance::query()->create([
            'reference_code' => 'ICS-TEST-1',
            'requisition_id' => $requisition->id,
            'office_id' => $otherOffice->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'issuance_date' => now()->toDateString(),
            'issued_to' => $accountable->id,
            'estimated_useful_life' => '5 yrs',
            'eul_expires_at' => now()->addDays(30),
        ]);

        $recipients = app(NotificationRecipientResolver::class)->eulReminderRecipients($issuance);

        $this->assertTrue($recipients->contains('id', $regionalCustodian->id));
        $this->assertTrue($recipients->contains('id', $accountable->id));
        $this->assertFalse($recipients->contains('id', $otherCustodian->id));
    }
}
