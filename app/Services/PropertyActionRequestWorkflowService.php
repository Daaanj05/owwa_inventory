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
use App\Support\SupplyOfficeResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PropertyActionRequestWorkflowService
{
    public function submit(PropertyActionRequest $request): void
    {
        if ($request->status !== PropertyActionRequest::STATUS_DRAFT) {
            throw new InvalidArgumentException('Only draft requests can be submitted.');
        }

        app(PropertyReturnService::class)->assertLinesReturnable($request);

        $request->update(['status' => PropertyActionRequest::STATUS_PENDING_UC]);

        $request->loadMissing('requestedBy');

        app(UserActivityLogger::class)->recordForAuthenticated(
            'submitted',
            'Submitted property action '.$request->reference_code,
            $request,
        );

        $this->notifyEmployeeSubmitted($request);
    }

    public function approveByUnitConsolidator(PropertyActionRequest $request, User $uc, ?string $remarks = null): void
    {
        if ($request->status !== PropertyActionRequest::STATUS_PENDING_UC) {
            throw new InvalidArgumentException('Request is not pending UC review.');
        }

        if ($request->compiled_into_property_action_request_id !== null) {
            throw new InvalidArgumentException('This property return has already been compiled.');
        }

        // Phase A (2C): mark as UC-reviewed; stays pending_uc until compiled into a SC batch.
        $request->update([
            'status' => PropertyActionRequest::STATUS_PENDING_UC,
            'uc_approved_by' => $uc->id,
            'uc_approved_at' => now(),
            'uc_remarks' => $remarks,
        ]);

        app(UserActivityLogger::class)->record(
            $uc,
            'approved',
            'UC approved property action '.$request->reference_code.' for compile',
            $request,
        );

        $this->notifyRequester(
            $request,
            'Property return approved for SC',
            sprintf(
                '%s was approved by your Unit Consolidator and is ready to be compiled for Supply Custodian review.',
                $request->reference_code,
            ),
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

        app(UserActivityLogger::class)->record(
            $uc,
            'rejected',
            'UC rejected property action '.$request->reference_code,
            $request,
        );

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

        $attributes = [
            'status' => PropertyActionRequest::STATUS_APPROVED,
            'sc_approved_by' => $custodian->id,
            'sc_approved_at' => now(),
            'sc_remarks' => $remarks,
        ];

        $request->update($attributes);

        $title = 'Property return approved — send item to SC';
        $body = fn (PropertyActionRequest $subject): string => sprintf(
            '%s was approved in the system. Please send the physical item to the Supply Custodian / Regional Office for receipt and routing.',
            $subject->reference_code,
        );

        $this->notifyRequester($request, $title, $body($request));
        $this->notifyAccountableUser($request, $title, $body($request));
        $this->syncCompiledSources($request, $attributes, $title, $body);
    }

    public function rejectBySupplyCustodian(PropertyActionRequest $request, User $custodian, ?string $remarks = null): void
    {
        if ($request->status !== PropertyActionRequest::STATUS_PENDING_SC) {
            throw new InvalidArgumentException('Request is not pending SC approval.');
        }

        $attributes = [
            'status' => PropertyActionRequest::STATUS_REJECTED,
            'sc_approved_by' => $custodian->id,
            'sc_approved_at' => now(),
            'sc_remarks' => $remarks,
        ];

        $request->update($attributes);

        $title = 'Property return rejected';
        $body = fn (PropertyActionRequest $subject): string => sprintf('%s was rejected by Supply Custodian.', $subject->reference_code);

        $this->notifyRequester($request, $title, $body($request));
        $this->syncCompiledSources($request, $attributes, $title, $body);
    }

    /**
     * SC receives the physical item and routes the outcome (Dispose / Under repair / Return to stock).
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

        if (! in_array($resolvedOutcome, PropertyActionRequest::receiveOutcomes(), true)) {
            throw new InvalidArgumentException('Invalid receive-and-route outcome.');
        }

        DB::transaction(function () use ($request, $custodian, $resolvedOutcome, $newEstimatedUsefulLife, $receiptRemarks): void {
            $request->loadMissing(['office', 'lines.issuance.item', 'lines.issuance.issuedTo']);

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

            foreach ($request->lines as $line) {
                $this->executeLine($request, $line, $custodian, $resolvedOutcome);
            }

            if ($resolvedOutcome === PropertyActionRequest::OUTCOME_RETURN_TO_STOCK) {
                $this->applyOptionalEulReset($request, $newEstimatedUsefulLife);
            }

            $attributes = [
                'status' => PropertyActionRequest::STATUS_EXECUTED,
                'executed_at' => now(),
            ];

            $request->update($attributes);

            $title = 'Property return received';
            $body = fn (PropertyActionRequest $subject): string => sprintf(
                '%s was received by Supply Custodian: %s.%s',
                $subject->reference_code,
                PropertyActionRequest::receiveOutcomeLabel($resolvedOutcome),
                $request->action_type === PropertyActionRequest::ACTION_REPLACEMENT
                    ? ' File a new employee requisition when a replacement is needed.'
                    : '',
            );

            $this->notifyRequester($request, $title, $body($request));
            $this->syncCompiledSources($request, $attributes, $title, $body);
        });

        app(InventoryStockService::class)->forgetMovementTotalsCache();
    }

    /**
     * Repaired unit goes back to the regional supply stock (re-issuable to anyone through a requisition).
     */
    public function markRepairedToStock(InventoryUnit $unit, User $custodian): Transfer
    {
        return DB::transaction(function () use ($unit, $custodian): Transfer {
            $locked = $this->lockUnderRepairUnit($unit);
            $transfer = $this->createReturnTransfer(
                $locked->repairSourceIssuance,
                $locked,
                $custodian,
                'Repaired — returned to supply stock',
            );

            app(InventoryStockService::class)->forgetMovementTotalsCache();

            return $transfer;
        });
    }

    /**
     * Repaired unit goes back to the employee it was returned from, on a new ICS/PAR.
     */
    public function reissueRepairedUnit(InventoryUnit $unit, User $custodian): Issuance
    {
        return DB::transaction(function () use ($unit, $custodian): Issuance {
            $locked = $this->lockUnderRepairUnit($unit);
            $source = $locked->repairSourceIssuance;
            $source->loadMissing('item.category');

            $this->createReturnTransfer($source, $locked, $custodian, 'Repaired — re-issued to original employee');

            $supplyOfficeId = (int) $locked->fresh()->office_id;
            $categorySlug = (string) $source->item?->category?->getTemplateSlug();

            $issuance = Issuance::query()->create([
                'reference_code' => app(ReferenceCodeService::class)->forIssuanceBatch($categorySlug),
                'requisition_id' => $source->requisition_id,
                'item_id' => $source->item_id,
                'office_id' => $source->office_id,
                'issued_from_office_id' => $supplyOfficeId,
                'department_id' => $source->department_id,
                'quantity' => 1,
                'unit_cost' => $locked->unit_cost ?? $source->unit_cost,
                'issuance_date' => now()->toDateString(),
                'property_number' => $locked->property_number,
                'issued_property_numbers' => [$locked->property_number],
                'estimated_useful_life' => $source->estimated_useful_life,
                'issued_by' => $custodian->id,
                'issued_to' => $source->issued_to,
                'custodian_printed_name' => $custodian->name,
                'remarks' => 'Re-issued after repair (previously '.$source->controlNumber().').',
            ]);

            $locked->update([
                'status' => InventoryUnit::STATUS_ISSUED,
                'issuance_id' => $issuance->id,
                'repair_source_issuance_id' => null,
            ]);

            app(InventoryStockService::class)->forgetMovementTotalsCache();

            return $issuance;
        });
    }

    /**
     * Repair failed: dispose the unit (it never re-entered stock, so stock is not deducted).
     */
    public function disposeUnrepairableUnit(InventoryUnit $unit, User $custodian, ?string $remarks = null): Disposal
    {
        return DB::transaction(function () use ($unit, $custodian, $remarks): Disposal {
            $locked = $this->lockUnderRepairUnit($unit);

            $disposal = $this->createUnitDisposal(
                $locked->repairSourceIssuance,
                $locked,
                $custodian,
                'Unserviceable — beyond repair',
                $remarks,
            );

            $locked->update(['repair_source_issuance_id' => null]);

            return $disposal;
        });
    }

    protected function executeLine(
        PropertyActionRequest $request,
        PropertyActionRequestLine $line,
        User $custodian,
        string $outcome,
    ): void {
        $issuance = $line->issuance;

        if (! $issuance instanceof Issuance) {
            throw new InvalidArgumentException('Property action requests require a linked issuance on each line.');
        }

        $units = $this->lockLineUnits($line, $issuance);
        $remarks = collect([
            $request->reference_code,
            $request->reasonLabel(),
            $request->reason_detail,
        ])->filter()->implode(' — ');

        foreach ($units as $unit) {
            match ($outcome) {
                PropertyActionRequest::OUTCOME_RETURN_TO_STOCK => $line->transfer_id = $this
                    ->createReturnTransfer($issuance, $unit, $custodian, $remarks, $request->reasonLabel())
                    ->id,
                PropertyActionRequest::OUTCOME_REPAIR => $this->holdUnitForRepair($issuance, $unit),
                PropertyActionRequest::OUTCOME_DISPOSE => $line->disposal_id = $this
                    ->createUnitDisposal($issuance, $unit, $custodian, $request->reasonLabel(), $request->reason_detail)
                    ->id,
            };
        }

        $line->outcome = $outcome;
        $line->save();

        $this->registerReturnedQuantity(
            $issuance,
            $units->count(),
            PropertyActionRequest::custodyEndTypeForOutcome($outcome),
            $request->reference_code,
        );
    }

    /**
     * The line's explicit unit (or, for older lines without one, the issuance's issued units) — still issued
     * on that issuance. Anything already returned or disposed blocks execution instead of silently skipping.
     *
     * @return Collection<int, InventoryUnit>
     */
    protected function lockLineUnits(PropertyActionRequestLine $line, Issuance $issuance): Collection
    {
        $expected = $line->inventory_unit_id !== null ? 1 : max(1, (int) $line->quantity);

        $units = InventoryUnit::query()
            ->where('issuance_id', $issuance->id)
            ->where('status', InventoryUnit::STATUS_ISSUED)
            ->when($line->inventory_unit_id !== null, fn ($query) => $query->whereKey($line->inventory_unit_id))
            ->orderBy('id')
            ->limit($expected)
            ->lockForUpdate()
            ->get();

        if ($units->count() !== $expected) {
            throw new InvalidArgumentException(sprintf(
                'Property on %s was already returned or disposed.',
                $issuance->controlNumber() ?? 'this issuance',
            ));
        }

        return $units;
    }

    protected function createReturnTransfer(
        Issuance $issuance,
        InventoryUnit $unit,
        User $custodian,
        string $remarks,
        ?string $reason = null,
    ): Transfer {
        $toOffice = app(SupplyOfficeResolver::class)->resolve();

        if (! $toOffice) {
            throw new InvalidArgumentException('Return to stock requires a regional supply office.');
        }

        $issuance->loadMissing('issuedTo');

        $transfer = Transfer::withoutEvents(fn (): Transfer => Transfer::query()->create([
            'reference_code' => app(ReferenceCodeService::class)->forTransfer(),
            'transfer_type' => 'return',
            'from_office_id' => $issuance->office_id,
            'to_office_id' => $toOffice,
            'item_id' => $issuance->item_id,
            'inventory_unit_id' => $unit->id,
            'quantity' => 1,
            'unit_cost' => $unit->unit_cost ?? $issuance->unit_cost,
            'transfer_date' => now()->toDateString(),
            'property_number' => $unit->property_number,
            'remarks' => $remarks,
            'reason_for_transfer' => $reason ?? $remarks,
            'from_accountable_officer' => $issuance->issuedTo?->name,
            'to_accountable_officer' => Office::query()->find($toOffice)?->name,
            'recorded_by' => $custodian->id,
        ]));

        app(PropertyReturnService::class)->processReturnTransfer($transfer);
        app(InventoryStockService::class)->forgetMovementTotalsCache();

        return $transfer;
    }

    protected function holdUnitForRepair(Issuance $issuance, InventoryUnit $unit): void
    {
        $supplyOfficeId = app(SupplyOfficeResolver::class)->resolve();

        $unit->update([
            'status' => InventoryUnit::STATUS_UNDER_REPAIR,
            'issuance_id' => null,
            'repair_source_issuance_id' => $issuance->id,
            'office_id' => $supplyOfficeId ?? $unit->office_id,
        ]);
    }

    protected function createUnitDisposal(
        Issuance $issuance,
        InventoryUnit $unit,
        User $custodian,
        string $reason,
        ?string $remarks,
    ): Disposal {
        $disposal = Disposal::query()->create([
            'disposal_type' => 'unserviceable',
            'item_id' => $unit->item_id,
            'office_id' => $unit->office_id,
            'department_id' => $issuance->department_id,
            'quantity' => 1,
            'disposal_date' => now()->toDateString(),
            'reason' => $reason,
            'remarks' => $remarks,
            'property_number' => $unit->property_number,
            'acquisition_cost' => app(DisposalInventoryUnitService::class)->resolveAcquisitionCostForUnit($unit),
            'inventory_unit_id' => $unit->id,
            'par_issuance_id' => $issuance->id,
            'recorded_by' => $custodian->id,
        ]);

        app(DisposalWorkflowService::class)->confirm($disposal->fresh(['batch']));

        return $disposal;
    }

    protected function registerReturnedQuantity(Issuance $issuance, int $quantity, string $custodyEndType, ?string $reference): void
    {
        $returned = min((int) $issuance->quantity, (int) $issuance->returned_quantity + $quantity);

        $issuance->update(['returned_quantity' => $returned]);

        if ($returned < (int) $issuance->quantity || $issuance->custody_ended_at !== null) {
            return;
        }

        $issuance->update([
            'custody_ended_at' => now(),
            'custody_end_type' => $custodyEndType,
            'custody_end_reference' => $reference,
        ]);
    }

    protected function lockUnderRepairUnit(InventoryUnit $unit): InventoryUnit
    {
        $locked = InventoryUnit::query()
            ->with('repairSourceIssuance')
            ->whereKey($unit->id)
            ->lockForUpdate()
            ->first();

        if ($locked?->status !== InventoryUnit::STATUS_UNDER_REPAIR || $locked->repairSourceIssuance === null) {
            throw new InvalidArgumentException('This inventory unit is not under repair.');
        }

        return $locked;
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

    /**
     * Mirror a compiled (UC) request's SC decision onto the employee returns it was built from, and tell each employee.
     *
     * @param  array<string, mixed>  $attributes
     * @param  callable(PropertyActionRequest): string  $body
     */
    protected function syncCompiledSources(PropertyActionRequest $request, array $attributes, string $title, callable $body): void
    {
        $sources = $request->compiledSourcePropertyActionRequests()->with('requestedBy')->get();

        foreach ($sources as $source) {
            $source->update($attributes);
            $this->notifyRequester($source, $title, $body($source));
        }
    }

    protected function notifyRequester(PropertyActionRequest $request, string $title, string $body): void
    {
        $request->loadMissing('requestedBy');
        $requester = $request->requestedBy;

        if ($requester instanceof User) {
            $requester->notify(new RequisitionWorkflowDatabaseNotification(
                $title,
                $body,
                propertyActionRequestId: (int) $request->id,
            ));
        }
    }

    protected function notifyAccountableUser(PropertyActionRequest $request, string $title, string $body): void
    {
        $request->loadMissing('accountableUser');
        $accountable = $request->accountableUser;

        if ($accountable instanceof User && (int) $accountable->id !== (int) $request->requested_by) {
            $accountable->notify(new RequisitionWorkflowDatabaseNotification(
                $title,
                $body,
                propertyActionRequestId: (int) $request->id,
            ));
        }
    }

    public function notifyEmployeeSubmitted(PropertyActionRequest $request): void
    {
        $request->loadMissing('requestedBy');

        $this->notifyOfficeUnitConsolidators(
            $request,
            'Employee property return submitted',
            sprintf(
                '%s from %s — awaiting your review.',
                $request->reference_code,
                $request->requestedBy?->name ?? 'an employee',
            ),
        );
    }

    protected function notifyOfficeUnitConsolidators(PropertyActionRequest $request, string $title, string $body): void
    {
        $recipients = \App\Support\RequisitionNotificationRecipients::unitConsolidatorsForOffice(
            (int) $request->office_id,
            $request->department_id ? (int) $request->department_id : null,
        );

        foreach ($recipients as $user) {
            $user->notify(new RequisitionWorkflowDatabaseNotification(
                $title,
                $body,
                propertyActionRequestId: (int) $request->id,
            ));
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
            $custodian->notify(new RequisitionWorkflowDatabaseNotification(
                $title,
                $body,
                propertyActionRequestId: (int) $request->id,
            ));
        }
    }
}
