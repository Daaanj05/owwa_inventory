<?php

namespace App\Services;

use App\Models\Issuance;
use App\Models\User;
use App\Notifications\RequisitionWorkflowDatabaseNotification;

class IssuanceNotificationService
{
    public function handleCreated(Issuance $issuance): void
    {
        $issuance->loadMissing(['item.category', 'issuedTo', 'office', 'requisition']);

        // Requisition fulfillment already notifies via requisition modal URLs.
        // Skip SC-only IssuanceResource deep links (Forbidden for UC/employees).
        if ($issuance->requisition_id !== null) {
            return;
        }

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

        $title = 'Requisition stock updated';
        $body = sprintf(
            '%s — %s (%s). Open Requisitions to track fulfillment.',
            $issuance->reference_code ?? 'Issuance',
            $issuance->item?->name ?? 'Item',
            $issuance->office?->name ?? 'Office',
        );

        foreach ($recipients as $user) {
            $user->notify(new RequisitionWorkflowDatabaseNotification($title, $body));
        }
    }
}
