<?php

namespace App\Services;

use App\Models\Issuance;
use App\Models\User;
use App\Notifications\IssuanceCreatedDatabaseNotification;

class IssuanceNotificationService
{
    public function handleCreated(Issuance $issuance): void
    {
        $issuance->loadMissing(['item.category', 'issuedTo', 'office', 'requisition']);

        $recipients = collect();

        if ($issuance->issued_to && $issuance->issued_to !== $issuance->issued_by) {
            $issuedTo = $issuance->issuedTo;

            if ($issuedTo instanceof User) {
                $recipients->push($issuedTo);
            }
        }

        if ($recipients->isEmpty()) {
            return;
        }

        $title = 'Stock issued';
        $body = sprintf(
            '%s — %s (%s)',
            $issuance->reference_code ?? 'Issuance',
            $issuance->item?->name ?? 'Item',
            $issuance->office?->name ?? 'Office',
        );

        $categoryId = $issuance->item?->item_category_id;

        foreach ($recipients as $user) {
            $user->notify(new IssuanceCreatedDatabaseNotification(
                $title,
                $body,
                (int) $issuance->id,
                $categoryId !== null ? (int) $categoryId : null,
            ));
        }
    }
}
