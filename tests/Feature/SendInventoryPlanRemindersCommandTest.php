<?php

namespace Tests\Feature;

use App\Models\PhysicalInventoryPlan;
use App\Models\PhysicalInventoryPlanLine;
use App\Models\User;
use App\Notifications\InventoryPlanReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendInventoryPlanRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_writes_database_notification_for_custodian(): void
    {
        Notification::fake();

        $today = Carbon::parse('2026-06-15');
        Carbon::setTestNow($today);

        $line = PhysicalInventoryPlanLine::factory()->create([
            'planned_date' => $today->toDateString(),
            'physical_inventory_plan_id' => PhysicalInventoryPlan::factory()->approved()->create()->id,
        ]);

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $line->office_id,
        ]);

        $this->artisan('inventory:send-plan-reminders')
            ->assertSuccessful();

        Notification::assertSentTo(
            $custodian,
            InventoryPlanReminderNotification::class,
            fn (InventoryPlanReminderNotification $notification): bool => $notification->reminderType === 'due',
        );

        Carbon::setTestNow();
    }

    public function test_command_does_not_notify_custodian_from_unrelated_office(): void
    {
        Notification::fake();

        $today = Carbon::parse('2026-06-15');
        Carbon::setTestNow($today);

        $line = PhysicalInventoryPlanLine::factory()->create([
            'planned_date' => $today->toDateString(),
            'physical_inventory_plan_id' => PhysicalInventoryPlan::factory()->approved()->create()->id,
        ]);

        $matchingCustodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $line->office_id,
        ]);
        $otherCustodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => \App\Models\Office::factory()->create()->id,
        ]);

        $this->artisan('inventory:send-plan-reminders')
            ->assertSuccessful();

        Notification::assertSentTo($matchingCustodian, InventoryPlanReminderNotification::class);
        Notification::assertNotSentTo($otherCustodian, InventoryPlanReminderNotification::class);

        Carbon::setTestNow();
    }
}
