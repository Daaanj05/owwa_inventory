<?php

namespace App\Services;

use App\Models\PhysicalInventoryPlan;
use App\Models\PhysicalInventoryPlanLine;
use App\Models\User;
use App\Notifications\InventoryPlanReminderNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

class InventoryPlanReminderService
{
    /**
     * @return array{sent: int, skipped: int}
     */
    public function sendDueReminders(?Carbon $today = null): array
    {
        $today ??= now()->startOfDay();

        $sent = 0;
        $skipped = 0;

        $lines = PhysicalInventoryPlanLine::query()
            ->with(['plan', 'office', 'physicalCountSession'])
            ->whereHas('plan', function ($query): void {
                $query->whereIn('status', [
                    PhysicalInventoryPlan::STATUS_APPROVED,
                    PhysicalInventoryPlan::STATUS_IN_PROGRESS,
                ]);
            })
            ->get();

        $custodians = User::query()
            ->where('role', User::ROLE_SUPPLY_CUSTODIAN)
            ->get();

        if ($custodians->isEmpty()) {
            return ['sent' => 0, 'skipped' => $lines->count()];
        }

        foreach ($lines as $line) {
            $line->loadMissing(['plan', 'office', 'physicalCountSession']);

            if (! in_array($line->plan?->status, [
                PhysicalInventoryPlan::STATUS_APPROVED,
                PhysicalInventoryPlan::STATUS_IN_PROGRESS,
            ], true)) {
                $skipped++;

                continue;
            }

            if ($line->physicalCountSession?->isComplete()) {
                $skipped++;

                continue;
            }

            $reminderType = $this->resolveReminderType($line, $today);

            if ($reminderType === null) {
                $skipped++;

                continue;
            }

            if ($line->last_reminder_type === $reminderType) {
                $skipped++;

                continue;
            }

            Notification::send(
                $custodians,
                new InventoryPlanReminderNotification($line, $reminderType),
            );

            $line->update([
                'last_reminder_type' => $reminderType,
                'last_reminded_at' => now(),
            ]);

            $sent++;
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    protected function resolveReminderType(PhysicalInventoryPlanLine $line, Carbon $today): ?string
    {
        $planned = $line->planned_date?->copy()->startOfDay();

        if ($planned === null) {
            return null;
        }

        if ($planned->equalTo($today->copy()->addDays(7))) {
            return 'd7';
        }

        if ($planned->equalTo($today->copy()->addDay())) {
            return 'd1';
        }

        if ($planned->equalTo($today)) {
            return 'due';
        }

        if ($planned->lessThan($today)) {
            return 'overdue';
        }

        return null;
    }
}
