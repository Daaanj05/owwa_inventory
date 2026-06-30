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

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $today = Carbon::parse('2026-06-15');

        Carbon::setTestNow($today);

        PhysicalInventoryPlanLine::factory()->create([
            'planned_date' => $today->toDateString(),
            'physical_inventory_plan_id' => PhysicalInventoryPlan::factory()->approved()->create()->id,
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
}
