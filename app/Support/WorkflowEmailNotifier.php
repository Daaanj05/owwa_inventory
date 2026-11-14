<?php

namespace App\Support;

use App\Models\User;
use App\Models\WorkflowEmailNotification;
use App\Notifications\RequisitionRejectedMailNotification;
use App\Notifications\WorkflowUpdateMailNotification;
use Illuminate\Notifications\Notification;
use Throwable;

class WorkflowEmailNotifier
{
    /**
     * @param  array{
     *     requisition_id?: int|null,
     *     property_action_request_id?: int|null,
     *     physical_inventory_plan_id?: int|null,
     *     physical_inventory_plan_line_id?: int|null,
     * }  $subject
     */
    public function queue(
        User $user,
        string $title,
        string $body,
        array $subject = [],
        ?WorkflowEmailContext $context = null,
        ?Notification $mailNotification = null,
    ): WorkflowEmailNotification {
        $log = WorkflowEmailNotification::query()->create([
            'user_id' => $user->id,
            'requisition_id' => $subject['requisition_id'] ?? null,
            'property_action_request_id' => $subject['property_action_request_id'] ?? null,
            'physical_inventory_plan_id' => $subject['physical_inventory_plan_id'] ?? null,
            'physical_inventory_plan_line_id' => $subject['physical_inventory_plan_line_id'] ?? null,
            'title' => $title,
            'body' => $body,
            'status' => WorkflowEmailNotification::STATUS_QUEUED,
        ]);

        if (! filled($user->email)) {
            $log->update([
                'status' => WorkflowEmailNotification::STATUS_SKIPPED,
                'failure_message' => 'Recipient has no email address.',
            ]);

            return $log->fresh();
        }

        $notification = $mailNotification ?? new WorkflowUpdateMailNotification(
            workflowEmailNotificationId: (int) $log->id,
            title: $title,
            body: $body,
            context: $context,
        );

        if ($notification instanceof WorkflowUpdateMailNotification) {
            $notification->workflowEmailNotificationId = (int) $log->id;
        }

        if ($notification instanceof RequisitionRejectedMailNotification) {
            $notification->workflowEmailNotificationId = (int) $log->id;
        }

        $result = MailDelivery::notify($user, $notification);

        if (! $result->success) {
            $log->update([
                'status' => WorkflowEmailNotification::STATUS_FAILED,
                'failure_message' => 'Mail transport failed.',
            ]);

            return $log->fresh();
        }

        if (! $result->wasQueued) {
            $log->update([
                'status' => WorkflowEmailNotification::STATUS_SENT,
                'sent_at' => now(),
            ]);
        }

        return $log->fresh();
    }

    public function markSent(int $workflowEmailNotificationId): void
    {
        $log = WorkflowEmailNotification::query()->find($workflowEmailNotificationId);

        if ($log === null || $log->status === WorkflowEmailNotification::STATUS_SENT) {
            return;
        }

        $log->update([
            'status' => WorkflowEmailNotification::STATUS_SENT,
            'sent_at' => now(),
            'failure_message' => null,
        ]);
    }

    public function markFailed(int $workflowEmailNotificationId, ?Throwable $exception = null): void
    {
        $log = WorkflowEmailNotification::query()->find($workflowEmailNotificationId);

        if ($log === null || $log->status === WorkflowEmailNotification::STATUS_SENT) {
            return;
        }

        $log->update([
            'status' => WorkflowEmailNotification::STATUS_FAILED,
            'failure_message' => $exception?->getMessage() ?? 'Mail delivery failed.',
        ]);
    }
}
