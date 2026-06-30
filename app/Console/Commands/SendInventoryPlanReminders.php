<?php

namespace App\Console\Commands;

use App\Services\InventoryPlanReminderService;
use Illuminate\Console\Command;

class SendInventoryPlanReminders extends Command
{
    protected $signature = 'inventory:send-plan-reminders';

    protected $description = 'Send database notifications for upcoming and overdue Inventory Schedule counts';

    public function handle(InventoryPlanReminderService $reminderService): int
    {
        $result = $reminderService->sendDueReminders();

        $this->info("Inventory Schedule reminders sent: {$result['sent']}, skipped: {$result['skipped']}.");

        return self::SUCCESS;
    }
}
