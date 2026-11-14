<?php

namespace App\Services;

use App\Models\Requisition;
use App\Models\User;
use App\Notifications\RequisitionWorkflowDatabaseNotification;
use App\Support\EmployeeRequisitionStatus;
use App\Support\NotificationRecipientResolver;
use App\Support\WorkflowEmailContext;
use App\Support\WorkflowEmailNotifier;
use Illuminate\Support\Carbon;

class PartialRequisitionReminderService
{
    /**
     * @return array{sent: int, skipped: int}
     */
    public function sendDueReminders(?Carbon $today = null): array
    {
        $today ??= now()->startOfDay();
        $cutoff = $today->copy()->subDays(7)->endOfDay();

        $sent = 0;
        $skipped = 0;

        $requisitions = Requisition::query()
            ->with(['requestedBy', 'office', 'department'])
            ->whereNull('closed_at')
            ->whereNotNull('compiled_into_requisition_id')
            ->get()
            ->filter(fn (Requisition $requisition): bool => $requisition->isEmployeeRequest());

        $custodians = app(NotificationRecipientResolver::class)->supplyCustodiansForRegionalOffice();

        if ($custodians->isEmpty()) {
            return ['sent' => 0, 'skipped' => $requisitions->count()];
        }

        foreach ($requisitions as $requisition) {
            $target = EmployeeRequisitionStatus::fulfillmentTargetTotal($requisition);
            $issued = EmployeeRequisitionStatus::issuedTotal($requisition);

            if ($issued <= 0 || $issued >= $target) {
                $skipped++;

                continue;
            }

            $latestIssuance = EmployeeRequisitionStatus::latestIssuanceDate($requisition);

            if ($latestIssuance === null || $latestIssuance->greaterThan($cutoff)) {
                $skipped++;

                continue;
            }

            if ($requisition->partial_balance_reminded_at !== null
                && $requisition->partial_balance_reminded_at->greaterThanOrEqualTo($latestIssuance->copy()->startOfDay())) {
                $skipped++;

                continue;
            }

            $summary = EmployeeRequisitionStatus::formatSummary($issued, $target);
            $transaction = $requisition->displayTransactionNumber() ?? $requisition->reference_code ?? 'Requisition';
            $title = 'Partial requisition still open';
            $body = sprintf('%s — %s. The balance is still open.', $transaction, $summary);
            $context = WorkflowEmailContext::fromRequisition($requisition);

            foreach ($custodians as $custodian) {
                if (! $custodian instanceof User) {
                    continue;
                }

                $custodian->notify(new RequisitionWorkflowDatabaseNotification(
                    $title,
                    $body,
                    (int) $requisition->id,
                ));

                app(WorkflowEmailNotifier::class)->queue(
                    $custodian,
                    $title,
                    $body,
                    ['requisition_id' => (int) $requisition->id],
                    $context,
                );
            }

            $requisition->update([
                'partial_balance_reminded_at' => now(),
            ]);

            $sent++;
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }
}
