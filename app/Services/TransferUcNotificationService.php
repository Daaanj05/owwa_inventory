<?php

namespace App\Services;

use App\Models\Transfer;
use App\Models\User;
use App\Notifications\TransferReceivedDatabaseNotification;
use App\Support\RequisitionNotificationRecipients;
use Illuminate\Support\Collection;

class TransferUcNotificationService
{
    public function notifyDestinationUnitConsolidators(Transfer $transfer): void
    {
        $toOfficeId = (int) ($transfer->to_office_id ?? 0);
        if ($toOfficeId <= 0) {
            return;
        }

        $transfer->loadMissing(['item', 'fromOffice', 'toOffice']);

        $recipients = RequisitionNotificationRecipients::unitConsolidatorsForOffice($toOfficeId)
            ->unique('id')
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        $reference = (string) ($transfer->reference_code ?? $transfer->id);
        $itemName = $transfer->item?->name ?? 'Item';
        $fromOffice = $transfer->fromOffice?->name ?? 'another office';
        $qty = (int) ($transfer->quantity ?? 0);

        $title = 'Stock transferred to your office';
        $body = sprintf(
            '%s — %s (qty %d) from %s. Ready to issue from Office Property Registry.',
            $reference,
            $itemName,
            $qty,
            $fromOffice,
        );

        $categoryId = $transfer->item?->item_category_id
            ? (int) $transfer->item->item_category_id
            : null;

        $this->notifyRecipients($recipients, $title, $body, $categoryId, $reference);
    }

    /**
     * @param  Collection<int, User>  $recipients
     */
    protected function notifyRecipients(
        Collection $recipients,
        string $title,
        string $body,
        ?int $categoryId,
        string $referenceCode,
    ): void {
        foreach ($recipients as $user) {
            if (! $user instanceof User) {
                continue;
            }

            $user->notify(new TransferReceivedDatabaseNotification(
                $title,
                $body,
                $categoryId,
                $referenceCode,
            ));
        }
    }
}
