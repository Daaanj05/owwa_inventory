<?php

namespace App\Console\Commands;

use App\Services\PartialRequisitionReminderService;
use Illuminate\Console\Command;

class RemindPartialRequisitions extends Command
{
    protected $signature = 'inventory:remind-partial-requisitions';

    protected $description = 'Remind Supply Custodians about partially issued requisitions still awaiting a balance';

    public function handle(PartialRequisitionReminderService $reminderService): int
    {
        $result = $reminderService->sendDueReminders();

        $this->info("Partial requisition reminders sent: {$result['sent']}, skipped: {$result['skipped']}.");

        return self::SUCCESS;
    }
}
