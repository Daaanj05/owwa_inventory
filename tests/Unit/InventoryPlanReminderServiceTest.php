<?php

namespace Tests\Unit;

use App\Models\PhysicalCountSession;
use App\Models\PhysicalInventoryPlan;
use App\Models\PhysicalInventoryPlanLine;
use App\Models\User;
use App\Notifications\InventoryPlanReminderNotification;
use App\Services\InventoryPlanReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InventoryPlanReminderServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
    }

    public function test_sends_d7_notification_seven_days_before(): void
    {
        Notification::fake();

        $today = Carbon::parse('2026-06-01');
        $line = $this->createApprovedLine($today->copy()->addDays(7)->toDateString());

        $result = app(InventoryPlanReminderService::class)->sendDueReminders($today);

        $this->assertSame(1, $result['sent']);
        Notification::assertSentTo(
            User::query()->where('role', User::ROLE_SUPPLY_CUSTODIAN)->get(),
            InventoryPlanReminderNotification::class,
            fn (InventoryPlanReminderNotification $notification): bool => $notification->reminderType === 'd7'
                && $notification->line->is($line),
        );
    }

    public function test_sends_due_notification_on_planned_date(): void
    {
        Notification::fake();

        $today = Carbon::parse('2026-06-15');
        $this->createApprovedLine($today->toDateString());

        $result = app(InventoryPlanReminderService::class)->sendDueReminders($today);

        $this->assertSame(1, $result['sent']);
        Notification::assertSentTo(
            User::query()->where('role', User::ROLE_SUPPLY_CUSTODIAN)->get(),
            InventoryPlanReminderNotification::class,
            fn (InventoryPlanReminderNotification $notification): bool => $notification->reminderType === 'due',
        );
    }

    public function test_sends_overdue_notification_after_planned_date(): void
    {
        Notification::fake();

        $today = Carbon::parse('2026-06-20');
        $this->createApprovedLine($today->copy()->subDays(2)->toDateString());

        $result = app(InventoryPlanReminderService::class)->sendDueReminders($today);

        $this->assertSame(1, $result['sent']);
        Notification::assertSentTo(
            User::query()->where('role', User::ROLE_SUPPLY_CUSTODIAN)->get(),
            InventoryPlanReminderNotification::class,
            fn (InventoryPlanReminderNotification $notification): bool => $notification->reminderType === 'overdue',
        );
    }

    public function test_skips_duplicate_last_reminder_type(): void
    {
        Notification::fake();

        $today = Carbon::parse('2026-06-15');
        $line = $this->createApprovedLine($today->toDateString(), 'due');

        $result = app(InventoryPlanReminderService::class)->sendDueReminders($today);

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['skipped']);
        Notification::assertNothingSent();
        $this->assertSame('due', $line->fresh()->last_reminder_type);
    }

    public function test_skips_lines_on_draft_plans(): void
    {
        Notification::fake();

        $today = Carbon::parse('2026-06-15');
        PhysicalInventoryPlanLine::factory()->create([
            'planned_date' => $today->toDateString(),
            'physical_inventory_plan_id' => PhysicalInventoryPlan::factory()->create([
                'status' => PhysicalInventoryPlan::STATUS_DRAFT,
            ])->id,
        ]);

        $result = app(InventoryPlanReminderService::class)->sendDueReminders($today);

        $this->assertSame(0, $result['sent']);
        Notification::assertNothingSent();
    }

    public function test_skips_lines_with_complete_sessions(): void
    {
        Notification::fake();

        $today = Carbon::parse('2026-06-15');
        $line = PhysicalInventoryPlanLine::factory()->create([
            'planned_date' => $today->toDateString(),
            'physical_inventory_plan_id' => PhysicalInventoryPlan::factory()->approved()->create()->id,
        ]);

        $session = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCI,
            'office_id' => $line->office_id,
            'item_category_id' => $line->item_category_id,
            'count_date' => $today,
            'status' => PhysicalCountSession::STATUS_COMPLETE,
        ]);

        $line->update(['physical_count_session_id' => $session->id]);

        $result = app(InventoryPlanReminderService::class)->sendDueReminders($today);

        $this->assertSame(0, $result['sent']);
        Notification::assertNothingSent();
    }

    protected function createApprovedLine(string $plannedDate, ?string $lastReminderType = null): PhysicalInventoryPlanLine
    {
        return PhysicalInventoryPlanLine::factory()->create([
            'planned_date' => $plannedDate,
            'last_reminder_type' => $lastReminderType,
            'physical_inventory_plan_id' => PhysicalInventoryPlan::factory()->approved()->create()->id,
        ]);
    }
}
