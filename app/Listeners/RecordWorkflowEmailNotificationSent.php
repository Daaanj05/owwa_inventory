<?php

namespace App\Listeners;

use App\Notifications\RequisitionRejectedMailNotification;
use App\Notifications\WorkflowUpdateMailNotification;
use App\Support\WorkflowEmailNotifier;
use Illuminate\Notifications\Events\NotificationSent;

class RecordWorkflowEmailNotificationSent
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'mail') {
            return;
        }

        $notification = $event->notification;
        $logId = null;

        if ($notification instanceof WorkflowUpdateMailNotification) {
            $logId = $notification->workflowEmailNotificationId;
        }

        if ($notification instanceof RequisitionRejectedMailNotification) {
            $logId = $notification->workflowEmailNotificationId;
        }

        if ($logId === null) {
            return;
        }

        app(WorkflowEmailNotifier::class)->markSent($logId);
    }
}
