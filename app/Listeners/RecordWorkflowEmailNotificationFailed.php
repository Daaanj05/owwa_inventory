<?php

namespace App\Listeners;

use App\Notifications\RequisitionRejectedMailNotification;
use App\Notifications\WorkflowUpdateMailNotification;
use App\Support\WorkflowEmailNotifier;
use Illuminate\Notifications\Events\NotificationFailed;
use Throwable;

class RecordWorkflowEmailNotificationFailed
{
    public function handle(NotificationFailed $event): void
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

        $exception = $event->data['exception'] ?? null;

        app(WorkflowEmailNotifier::class)->markFailed(
            $logId,
            $exception instanceof Throwable ? $exception : null,
        );
    }
}
