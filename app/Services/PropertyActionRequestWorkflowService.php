<?php

namespace App\Services;

use App\Models\Disposal;
use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\Office;
use App\Models\PropertyActionRequest;
use App\Models\PropertyActionRequestLine;
use App\Models\Transfer;
use App\Models\User;
use App\Notifications\RequisitionWorkflowDatabaseNotification;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PropertyActionRequestWorkflowService
{
    public function submit(PropertyActionRequest $request): void
    {
        if ($request->status !== PropertyActionRequest::STATUS_DRAFT) {
            throw new InvalidArgumentException('Only draft requests can be submitted.');
        }

        $request->update(['status' => PropertyActionRequest::STATUS_PENDING_UC]);

        $this->notifyAccountableUser(
            $request,
            'Property return submitted',
            sprintf('%s — awaiting your review.', $request->reference_code),
        );
    }

    public function approveByUnitConsolidator(PropertyActionRequest $request, User $uc, ?string $remarks = null): void
    {
        if ($request->status !== PropertyActionRequest::STATUS_PENDING_UC) {
            throw new InvalidArgumentException('Request is not pending UC review.');
        }

        $request->update([
            'status' => PropertyActionRequest::STATUS_PENDING_SC,
            'uc_approved_by' => $uc->id,
            'uc_approved_at' => now(),
            'uc_remarks' => $remarks,
        ]);

        $this->notifyRegionalCustodians(
            $request,
            'Property return awaiting SC approval',
            sprintf('%s — awaiting Supply Custodian review.', $request->reference_code),
        );
    }

    public function rejectByUnitConsolidator(PropertyActionRequest $request, User $uc, ?string $remarks = null): void
    {
        if ($request->status !== PropertyActionRequest::STATUS_PENDING_UC) {
            throw new InvalidArgumentException('Request is not pending UC review.');
        }

        $request->update([
            'status' => PropertyActionRequest::STATUS_REJECTED,
            'uc_approved_by' => $uc->id,
            'uc_approved_at' => now(),
            'uc_remarks' => $remarks,
        ]);

        $this->notifyRequester(
            $request,
            'Property return rejected',
            sprintf('%s was rejected by your Unit Consolidator.', $request->reference_code),
        );
    }

    public function approveBySupplyCustodian(PropertyActionRequest $request, User $custodian, ?string $remarks = null): void
    {
        if ($request->status !== PropertyActionRequest::STATUS_PENDING_SC) {
            throw new InvalidArgumentException('Request is not pending SC approval.');
        }

        $request->update([
            'status' => PropertyActionRequest::STATUS_APPROVED,
            'sc_approved_by' => $custodian->id,
            'sc_approved_at' => now(),
            'sc_remarks' => $remarks,
        ]);

        $shipMessage = sprintf(
            '%s was approved in the system. Please send the physical item to the Supply Custodian / Regional Office for receipt and routing.',
            $request->reference_code,
        );

        $this->notifyRequester($request, 'Property return approved — send item to SC', $shipMessage);
        $this->notifyAccountableUser($request, 'Property return approved — send item to SC', $shipMessage);
    }

    public function rejectBySupplyCustodian(PropertyActionRequest $request, User $custodian, ?string $remarks = null): void
    {
        if ($request->status !== PropertyActionRequest::STATUS_PENDING_SC) {
            throw new InvalidArgumentException('Request is not pending SC approval.');
        }

        $request->update([
            'status' => PropertyActionRequest::STATUS_REJECTED,
            'sc_approved_by' => $custodian->id,
            'sc_approved_at' => now(),
            'sc_remarks' => $remarks,
        ]);

        $this->notifyRequester(
            $request,
            'Property return rejected',
            sprintf('%s was rejected by Supply Custodian.', $request->reference_code),
        );
    }

    /**
     * SC receives the physical item and routes the outcome (Dispose / Transfer / Return to stock).
     */
    public function receiveAndRoute(
        PropertyActionRequest $request,
        User $custodian,
        string $outcome,
        ?string $newEstimatedUsefulLife = null,
        ?string $receiptRemarks = null,
    ): void {
        $this->execute($request, $custodian, $outcome, $newEstimatedUsefulLife, $receiptRemarks);
    }

    public function execute(
        PropertyActionRequest $request,
        User $custodian,
        ?string $outcome = null,
        ?string $newEstimatedUsefulLife = null,
        ?string $receiptRemarks = null,
    ): void {
        if ($request->status !== PropertyActionRequest::STATUS_APPROVED) {
            throw new InvalidArgumentException('Request must be approved before execution.');
        }

        $resolvedOutcome = $outcome ?: $request->suggestedReceiveOutcome();

        if (! in_array($resolvedOutcome, [
            PropertyActionRequest::OUTCOME_DISPOSE,
            PropertyActionRequest::OUTCOME_TRANSFER,
            PropertyActionRequest::OUTCOME_RETURN_TO_STOCK,
        ], true)) {
            throw new InvalidArgumentException('Invalid receive-and-route outcome.');
        }

        DB::transaction(function () use ($request, $custodian, $resolvedOutcome, $newEstimatedUsefulLife, $receiptRemarks): void {
            $request->loadMissing(['lines.issuance.item', 'lines.inventoryUnit']);

            if ($request->lines->isEmpty()) {
                throw new InvalidArgumentException('Property action requests require at least one property line.');
            }

            if (filled($receiptRemarks)) {
                $existing = trim((string) $request->sc_remarks);
                $request->update([
                    'sc_remarks' => $existing === ''
                        ? $receiptRemarks
                        : $existing."\n".$receiptRemarks,
                ]);
            }

            match ($resolvedOutcome) {
                PropertyActionRequest::OUTCOME_DISPOSE => $this->executeDisposal($request, $custodian),
                PropertyActionRequest::OUTCOME_TRANSFER => $this->executeReturn(
                    $request,
                    $custodian,
                    custodyEndType: 'transfer',
                ),
                PropertyActionRequest::OUTCOME_RETURN_TO_STOCK => $this->executeReturnToStock(
                    $request,
                    $custodian,
                    $newEstimatedUsefulLife,
                ),
                default => throw new InvalidArgumentException('Unsupported outcome.'),
            };

            $request->update([
                'status' => PropertyActionRequest::STATUS_EXECUTED,
                'executed_at' => now(),
            ]);
        });
    }

    protected function executeReturnToStock(
        PropertyActionRequest $request,
        User $custodian,
        ?string $newEstimatedUsefulLife,
    ): void {
        $this->executeReturn($request, $custodian, custodyEndType: 'return');
        $this->applyOptionalEulReset($request, $newEstimatedUsefulLife);

        if ($request->action_type === PropertyActionRequest::ACTION_REPLACEMENT) {
            $this->notifyRequester(
                $request,
                'Item returned to stock — file a new requisition',
                sprintf(
                    '%s was received and returned to stock. File a new employee requisition when a replacement is needed.',
                    $request->reference_code,
                ),
            );
        }
    }

    protected function applyOptionalEulReset(PropertyActionRequest $request, ?string $newEstimatedUsefulLife): void
    {
        if (blank($newEstimatedUsefulLife)) {
            return;
        }

        $request->loadMissing('lines.issuance.item');

        foreach ($request->lines as $line) {
            $item = $line->issuance?->item;

            if ($item === null) {
                continue;
            }

            $item->update(['estimated_useful_life' => $newEstimatedUsefulLife]);
        }
    }

    protected function executeDisposal(PropertyActionRequest $request, User $custodian): void
    {
        foreach ($request->lines as $line) {
            $this->executeDisposalLine($request, $line, $custodian);
        }
    }

    protected function executeDisposalLine(
        PropertyActionRequest $request,
        PropertyActionRequestLine $line,
        User $custodian,
    ): void {
        $issuance = $line->issuance;

        if (! $issuance instanceof Issuance) {
            throw new InvalidArgumentException('Disposal requests require a linked issuance on each line.');
        }

        $lineQuantity = max(1, (int) ($line->quantity ?: $issuance->quantity ?: 1));
        $issuanceQuantity = max(1, (int) ($issuance->quantity ?? 1));

        $disposal = Disposal::query()->create([
            'disposal_type' => 'unserviceable',
            'item_id' => $issuance->item_id,
            'office_id' => $request->office_id,
            'department_id' => $request->department_id,
            'quantity' => $lineQuantity,
            'disposal_date' => now()->toDateString(),
            'reason' => $request->reasonLabel(),
            'remarks' => $request->reason_detail,
            'property_number' => $issuance->property_number,
            'acquisition_cost' => $issuance->amount,
            'inventory_unit_id' => $line->inventory_unit_id,
            'par_issuance_id' => $issuance->id,
            'recorded_by' => $custodian->id,
            'iirup_disposal_mode' => $request->reason_code,
        ]);

        $line->update(['disposal_id' => $disposal->id]);

        if ($lineQuantity >= $issuanceQuantity) {
            $this->endIssuanceCustody(
                $issuance,
                'disposal',
                $disposal->reference_code ?? (string) $request->reference_code,
            );
        } else {
            $issuance->update(['quantity' => $issuanceQuantity - $lineQuantity]);
        }

        if ($line->inventory_unit_id) {
            InventoryUnit::query()
                ->whereKey($line->inventory_unit_id)
                ->update(['status' => InventoryUnit::STATUS_DISPOSED]);
        } elseif ($lineQuantity > 0 && filled($issuance->property_number)) {
            InventoryUnit::query()
                ->where('property_number', $issuance->property_number)
                ->where('item_id', $issuance->item_id)
                ->where('status', InventoryUnit::STATUS_ISSUED)
                ->orderBy('id')
                ->limit($lineQuantity)
                ->update(['status' => InventoryUnit::STATUS_DISPOSED]);
        }
    }

    protected function executeReturn(
        PropertyActionRequest $request,
        User $custodian,
        string $custodyEndType = 'return',
    ): void {
        $request->loadMissing(['office', 'lines.issuance.issuedTo', 'lines.issuance.office']);

        foreach ($request->lines as $line) {
            $this->executeReturnLine($request, $line, $custodian, $custodyEndType);
        }
    }

    protected function executeReturnLine(
        PropertyActionRequest $request,
        PropertyActionRequestLine $line,
        User $custodian,
        string $custodyEndType = 'return',
    ): void {
        $issuance = $line->issuance;

        if (! $issuance instanceof Issuance) {
            throw new InvalidArgumentException('Return requests require a linked issuance on each line.');
        }

        if (blank($issuance->property_number)) {
            throw new InvalidArgumentException('Return requests require a property number on each issuance.');
        }

        $toOffice = $request->office_id;
        if (! $toOffice) {
            throw new InvalidArgumentException('Return requests require an office on the property action request.');
        }

        $remarks = collect([
            $request->reference_code,
            $request->reasonLabel(),
            $request->reason_detail,
        ])->filter()->implode(' — ');

        $lineQuantity = max(1, (int) ($line->quantity ?: $issuance->quantity ?: 1));
        $issuanceQuantity = max(1, (int) ($issuance->quantity ?? 1));

        $transfer = Transfer::withoutEvents(function () use ($request, $issuance, $toOffice, $remarks, $custodian, $lineQuantity): Transfer {
            $transfer = Transfer::query()->create([
                'reference_code' => app(ReferenceCodeService::class)->forTransfer(),
                'transfer_type' => 'return',
                'from_office_id' => $issuance->office_id,
                'to_office_id' => $toOffice,
                'item_id' => $issuance->item_id,
                'quantity' => $lineQuantity,
                'unit_cost' => $issuance->unit_cost,
                'transfer_date' => now()->toDateString(),
                'property_number' => $issuance->property_number,
                'remarks' => $remarks,
                'reason_for_transfer' => $request->reasonLabel(),
                'from_accountable_officer' => $issuance->issuedTo?->name,
                'to_accountable_officer' => $request->office?->name ?? Office::query()->find($toOffice)?->name,
                'recorded_by' => $custodian->id,
            ]);

            app(PropertyReturnService::class)->processReturnTransfer($transfer);

            return $transfer;
        });

        $line->update(['transfer_id' => $transfer->id]);

        if ($lineQuantity >= $issuanceQuantity) {
            $this->endIssuanceCustody($issuance, $custodyEndType, $request->reference_code);
        } else {
            $issuance->update(['quantity' => $issuanceQuantity - $lineQuantity]);
        }
    }

    protected function endIssuanceCustody(Issuance $issuance, string $type, ?string $reference): void
    {
        if ($issuance->custody_ended_at !== null) {
            return;
        }

        $issuance->update([
            'custody_ended_at' => now(),
            'custody_end_type' => $type,
            'custody_end_reference' => $reference,
        ]);
    }

    protected function notifyRequester(PropertyActionRequest $request, string $title, string $body): void
    {
        $request->loadMissing('requestedBy');
        $requester = $request->requestedBy;

        if ($requester instanceof User) {
            $requester->notify(new RequisitionWorkflowDatabaseNotification($title, $body));
        }
    }

    protected function notifyAccountableUser(PropertyActionRequest $request, string $title, string $body): void
    {
        $request->loadMissing('accountableUser');
        $accountable = $request->accountableUser;

        if ($accountable instanceof User) {
            $accountable->notify(new RequisitionWorkflowDatabaseNotification($title, $body));
        }
    }

    public function sendToSupplyCustodian(PropertyActionRequest $request, User $uc, ?string $remarks = null): void
    {
        if ($request->status !== PropertyActionRequest::STATUS_DRAFT) {
            throw new InvalidArgumentException('Only draft requests can be sent to SC.');
        }

        $request->loadMissing('lines');

        if ($request->lines->isEmpty()) {
            throw new InvalidArgumentException('Property return requests require at least one property line.');
        }

        $request->update([
            'status' => PropertyActionRequest::STATUS_PENDING_SC,
            'uc_approved_by' => $uc->id,
            'uc_approved_at' => now(),
            'uc_remarks' => $remarks,
        ]);

        $this->notifyRegionalCustodians(
            $request,
            'Property return awaiting SC approval',
            sprintf('%s — awaiting Supply Custodian review.', $request->reference_code),
        );
    }

    protected function notifyRegionalCustodians(PropertyActionRequest $request, string $title, string $body): void
    {
        $custodians = app(\App\Support\NotificationRecipientResolver::class)->supplyCustodiansForRegionalOffice();

        foreach ($custodians as $custodian) {
            $custodian->notify(new RequisitionWorkflowDatabaseNotification($title, $body));
        }
    }
}
