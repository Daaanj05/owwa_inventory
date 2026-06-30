<?php

namespace Tests\Unit;

use App\Models\Office;
use App\Models\User;
use App\Support\RequisitionNotificationRecipients;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequisitionNotificationRecipientsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_unit_consolidators_for_office_only(): void
    {
        $officeA = Office::factory()->create();
        $officeB = Office::factory()->create();

        $ucA = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $officeA->id,
        ]);
        User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $officeB->id,
        ]);
        User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $officeA->id,
        ]);

        $recipients = RequisitionNotificationRecipients::unitConsolidatorsForOffice($officeA->id);

        $this->assertCount(1, $recipients);
        $this->assertTrue($recipients->contains('id', $ucA->id));
    }

    public function test_returns_all_supply_custodians(): void
    {
        $custodianA = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $custodianB = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $recipients = RequisitionNotificationRecipients::supplyCustodians();

        $this->assertCount(2, $recipients);
        $this->assertTrue($recipients->contains('id', $custodianA->id));
        $this->assertTrue($recipients->contains('id', $custodianB->id));
    }
}
