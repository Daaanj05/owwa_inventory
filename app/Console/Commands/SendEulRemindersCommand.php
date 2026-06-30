<?php

namespace App\Console\Commands;

use App\Models\Issuance;
use App\Models\ItemCategory;
use App\Models\User;
use App\Notifications\UsefulLifeReminderNotification;
use App\Support\SemiExpendableUsefulLife;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class SendEulRemindersCommand extends Command
{
    protected $signature = 'inventory:eul-reminders';

    protected $description = 'Notify supply custodians and accountable users about semi-expendable useful life nearing or expired.';

    public function handle(): int
    {
        $semiCategoryIds = $this->semiExpendableCategoryIds();
        $sent = 0;

        Issuance::query()
            ->with(['item.category', 'issuedTo'])
            ->whereNotNull('eul_expires_at')
            ->whereHas('item', fn ($query) => $query->whereIn('item_category_id', $semiCategoryIds))
            ->each(function (Issuance $issuance) use (&$sent): void {
                $status = SemiExpendableUsefulLife::statusForIssuance($issuance);

                if (! in_array($status, [SemiExpendableUsefulLife::STATUS_NEARING, SemiExpendableUsefulLife::STATUS_EXPIRED], true)) {
                    return;
                }

                $reminderType = $status === SemiExpendableUsefulLife::STATUS_EXPIRED ? 'expired' : 'warning';

                $recipients = User::query()
                    ->where(function ($query) use ($issuance): void {
                        $query->where('role', User::ROLE_SUPPLY_CUSTODIAN);

                        if ($issuance->issued_to) {
                            $query->orWhere('id', $issuance->issued_to);
                        }
                    })
                    ->get()
                    ->unique('id');

                Notification::send($recipients, new UsefulLifeReminderNotification($issuance, $reminderType));
                $sent += $recipients->count();
            });

        $this->info("Sent {$sent} useful life reminder notification(s).");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, int>
     */
    protected function semiExpendableCategoryIds(): Collection
    {
        return ItemCategory::query()
            ->get()
            ->filter(fn (ItemCategory $category): bool => $category->getTemplateSlug() === 'semi_expendable')
            ->pluck('id');
    }
}
