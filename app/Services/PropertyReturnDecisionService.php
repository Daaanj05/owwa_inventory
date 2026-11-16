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
use App\Support\OfficeSignatoryDefaults;
use App\Support\SupplyOfficeResolver;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PropertyReturnDecisionService
{
    /**
     * SC chooses For transfer: create a draft PTR with no stock movement yet.
     */
    public function decideForTransfer(PropertyActionRequest $request, User $custodian): Transfer
    {
        $this->assertPendingDecision($request);

        return DB::transaction(function () use ($request, $custodian): Transfer {
            $request->loadMissing(['lines.issuance.issuedTo', 'lines.inventoryUnit', 'office', 'requestedBy']);

            $line = $this->primaryLine($request);
            $issuance = $this->lineIssuance($line);
            $unit = $this->lineUnit($line, $issuance);

            $toOfficeId = app(SupplyOfficeResolver::class)->resolve();
            if ($toOfficeId === null) {
                throw new InvalidArgumentException('For transfer requires a regional supply office.');
            }

            $defaults = OfficeSignatoryDefaults::forTransfer(
                (int) $issuance->office_id,
                (int) $toOfficeId,
                $custodian,
            );
            // Plan: Released/Issued by = From officer; Received by = To officer.
            $defaults['released_by_printed_name'] = $defaults['from_accountable_officer']
                ?? $defaults['released_by_printed_name'];
            $defaults['received_by_printed_name'] = $defaults['to_accountable_officer']
                ?? $defaults['received_by_printed_name'];

            $transfer = Transfer::withoutEvents(fn (): Transfer => Transfer::query()->create([
                'reference_code' => app(ReferenceCodeService::class)->forTransfer(),
                'transfer_type' => null,
                'from_office_id' => $issuance->office_id,
                'to_office_id' => $toOfficeId,
                'item_id' => $issuance->item_id,
                'inventory_unit_id' => $unit->id,
                'quantity' => 1,
                'unit_cost' => $unit->unit_cost ?? $issuance->unit_cost,
                'transfer_date' => now()->toDateString(),
                'property_number' => $unit->property_number,
                'remarks' => collect([
                    $request->reference_code,
                    $request->reasonLabel(),
                    $request->reason_detail,
                ])->filter()->implode(' — '),
                'reason_for_transfer' => $request->reasonLabel(),
                'from_accountable_officer' => $issuance->issuedTo?->name
                    ?? $defaults['from_accountable_officer'],
                'to_accountable_officer' => Office::query()->find($toOfficeId)?->name
                    ?? $defaults['to_accountable_officer'],
                'approved_by_printed_name' => $defaults['approved_by_printed_name'],
                'approved_by_designation' => $defaults['approved_by_designation'],
                'released_by_printed_name' => $defaults['released_by_printed_name'],
                'released_by_designation' => $defaults['released_by_designation'],
                'received_by_printed_name' => $defaults['received_by_printed_name'],
                'received_by_designation' => $defaults['received_by_designation'],
                'recorded_by' => $custodian->id,
                'approved_at' => null,
                'exported_at' => null,
            ]));

            $line->update([
                'transfer_id' => $transfer->id,
                'outcome' => PropertyActionRequest::OUTCOME_RETURN_TO_STOCK,
            ]);

            $this->markDecision($request, $custodian, PropertyActionRequest::STATUS_PENDING_TRANSFER);

            return $transfer->fresh();
        });
    }

    /**
     * SC chooses For disposal: create an unconfirmed IIRUP/IIRUSP with no confirm yet.
     */
    public function decideForDisposal(PropertyActionRequest $request, User $custodian): Disposal
    {
        $this->assertPendingDecision($request);

        return DB::transaction(function () use ($request, $custodian): Disposal {
            $request->loadMissing(['lines.issuance', 'lines.inventoryUnit', 'requestedBy', 'office']);

            $line = $this->primaryLine($request);
            $issuance = $this->lineIssuance($line);
            $unit = $this->lineUnit($line, $issuance);

            $unitService = app(DisposalInventoryUnitService::class);

            $disposal = Disposal::query()->create([
                'disposal_type' => 'unserviceable',
                'item_id' => $unit->item_id,
                'office_id' => $unit->office_id,
                'department_id' => $issuance->department_id,
                'quantity' => 1,
                'disposal_date' => now()->toDateString(),
                'reason' => $request->reasonLabel(),
                'remarks' => $request->reason_detail,
                'property_number' => $unit->property_number,
                'acquisition_cost' => $unitService->resolveAcquisitionCostForUnit($unit),
                'inventory_unit_id' => $unit->id,
                'par_issuance_id' => $issuance->id,
                'custodian_printed_name' => $request->requestedBy?->name,
                'accountable_officer_station' => $request->office?->name,
                'recorded_by' => $custodian->id,
                'exported_at' => null,
            ]);

            $line->update([
                'disposal_id' => $disposal->id,
                'outcome' => PropertyActionRequest::OUTCOME_DISPOSE,
            ]);

            $this->markDecision($request, $custodian, PropertyActionRequest::STATUS_PENDING_DISPOSAL);

            return $disposal->fresh(['batch']);
        });
    }

    public function markTransferApproved(Transfer $transfer, User $custodian): void
    {
        if ($transfer->approved_at !== null) {
            throw new InvalidArgumentException('This transfer is already marked as approved.');
        }

        if ($transfer->exported_at === null) {
            throw new InvalidArgumentException('Export the PTR before marking this transfer as approved.');
        }

        if (blank($transfer->transfer_type)) {
            throw new InvalidArgumentException('Choose a transfer type before marking as approved.');
        }

        DB::transaction(function () use ($transfer, $custodian): void {
            $transfer = Transfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if ($transfer->approved_at !== null) {
                throw new InvalidArgumentException('This transfer is already marked as approved.');
            }

            $request = $this->linkedPropertyReturn($transfer);

            match ($transfer->transfer_type) {
                Transfer::TYPE_RELOCATE, Transfer::TYPE_DONATION, Transfer::TYPE_OTHERS => $this->approveRelocateLike($transfer, $request),
                Transfer::TYPE_REASSIGNMENT => $this->approveReassignment($transfer, $request, $custodian),
                Transfer::TYPE_RETURN => $this->approveRelocateLike($transfer, $request),
                default => throw new InvalidArgumentException('Unsupported transfer type.'),
            };

            $transfer->update(['approved_at' => now()]);

            if ($request instanceof PropertyActionRequest) {
                $this->completePropertyReturn($request, $custodian);
            }
        });

        app(InventoryStockService::class)->forgetMovementTotalsCache();
    }

    public function markDisposalApproved(Disposal $disposal, User $custodian): void
    {
        if ($disposal->isConfirmed()) {
            throw new InvalidArgumentException('This disposal is already marked as approved.');
        }

        if ($disposal->exported_at === null) {
            throw new InvalidArgumentException('Export the disposal form before marking as approved.');
        }

        DB::transaction(function () use ($disposal, $custodian): void {
            $disposal = Disposal::query()->with('batch')->whereKey($disposal->id)->lockForUpdate()->firstOrFail();

            if ($disposal->isConfirmed()) {
                throw new InvalidArgumentException('This disposal is already marked as approved.');
            }

            app(DisposalWorkflowService::class)->confirm($disposal);

            $request = $this->linkedPropertyReturnForDisposal($disposal);
            if ($request instanceof PropertyActionRequest) {
                $this->closeIssuanceForDisposal($disposal);
                $this->completePropertyReturn($request, $custodian);
            }
        });

        app(InventoryStockService::class)->forgetMovementTotalsCache();
    }

    public function recordTransferExported(Transfer $transfer): void
    {
        if ($transfer->exported_at !== null) {
            return;
        }

        $transfer->forceFill(['exported_at' => now()])->saveQuietly();
    }

    public function recordDisposalExported(Disposal $disposal): void
    {
        if ($disposal->exported_at !== null) {
            return;
        }

        $disposal->forceFill(['exported_at' => now()])->saveQuietly();
    }

    protected function approveRelocateLike(Transfer $transfer, ?PropertyActionRequest $request): void
    {
        $transfer->loadMissing(['inventoryUnit', 'item.category']);

        $unit = $transfer->inventoryUnit;
        if (! $unit instanceof InventoryUnit) {
            throw new InvalidArgumentException('Transfer is missing its inventory unit.');
        }

        $locked = InventoryUnit::query()->whereKey($unit->id)->lockForUpdate()->firstOrFail();
        if (! in_array($locked->status, [InventoryUnit::STATUS_ISSUED, InventoryUnit::STATUS_UNDER_REPAIR], true)
            && $locked->status !== InventoryUnit::STATUS_IN_STOCK) {
            throw new InvalidArgumentException('Property was already returned or disposed.');
        }

        $issuance = $locked->issuance_id
            ? Issuance::query()->whereKey($locked->issuance_id)->lockForUpdate()->first()
            : null;

        $locked->update([
            'status' => InventoryUnit::STATUS_IN_STOCK,
            'issuance_id' => null,
            'repair_source_issuance_id' => null,
            'office_id' => $transfer->to_office_id,
        ]);

        if ($issuance instanceof Issuance) {
            $this->closeIssuance($issuance, 'transfer', $transfer->reference_code);
        }
    }

    protected function approveReassignment(Transfer $transfer, ?PropertyActionRequest $request, User $custodian): void
    {
        $transfer->loadMissing(['inventoryUnit', 'item.category']);

        $unit = $transfer->inventoryUnit;
        if (! $unit instanceof InventoryUnit) {
            throw new InvalidArgumentException('Transfer is missing its inventory unit.');
        }

        $locked = InventoryUnit::query()
            ->with('issuance')
            ->whereKey($unit->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($locked->status !== InventoryUnit::STATUS_ISSUED || $locked->issuance === null) {
            throw new InvalidArgumentException('Property must still be issued to reassign.');
        }

        $source = $locked->issuance;
        $source->loadMissing('item.category');

        $holderName = trim((string) $transfer->received_by_printed_name);
        if ($holderName === '') {
            throw new InvalidArgumentException('Received by is required for reassignment.');
        }

        $newHolder = User::query()
            ->where('name', $holderName)
            ->orderBy('id')
            ->first();

        if ($newHolder === null) {
            throw new InvalidArgumentException('Received by must match a user account for reassignment.');
        }

        $categorySlug = (string) $source->item?->category?->getTemplateSlug();
        $issuance = Issuance::query()->create([
            'reference_code' => app(ReferenceCodeService::class)->forIssuanceBatch($categorySlug),
            'requisition_id' => null,
            'item_id' => $source->item_id,
            'office_id' => $source->office_id,
            'issued_from_office_id' => $source->issued_from_office_id,
            'department_id' => $source->department_id,
            'quantity' => 1,
            'unit_cost' => $locked->unit_cost ?? $source->unit_cost,
            'issuance_date' => now()->toDateString(),
            'property_number' => $locked->property_number,
            'issued_property_numbers' => [$locked->property_number],
            'estimated_useful_life' => $source->estimated_useful_life,
            'issued_by' => $custodian->id,
            'issued_to' => $newHolder->id,
            'custodian_printed_name' => $custodian->name,
            'remarks' => 'Reassigned via '.$transfer->reference_code
                .($source->controlNumber() ? ' (previously '.$source->controlNumber().')' : ''),
        ]);

        $locked->update([
            'status' => InventoryUnit::STATUS_ISSUED,
            'issuance_id' => $issuance->id,
            'repair_source_issuance_id' => null,
            'office_id' => $source->office_id,
        ]);

        $this->closeIssuance($source, 'transfer', $transfer->reference_code);
    }

    protected function closeIssuanceForDisposal(Disposal $disposal): void
    {
        $disposal->loadMissing('inventoryUnit.issuance', 'parIssuance');

        $issuance = $disposal->parIssuance
            ?? $disposal->inventoryUnit?->issuance;

        if ($issuance instanceof Issuance) {
            $this->closeIssuance($issuance, 'disposal', $disposal->reference_code);
        }
    }

    protected function closeIssuance(Issuance $issuance, string $endType, ?string $reference): void
    {
        $returned = min((int) $issuance->quantity, (int) $issuance->returned_quantity + 1);

        $issuance->update(['returned_quantity' => $returned]);

        if ($returned < (int) $issuance->quantity || $issuance->custody_ended_at !== null) {
            return;
        }

        $issuance->update([
            'custody_ended_at' => now(),
            'custody_end_type' => $endType,
            'custody_end_reference' => $reference,
        ]);
    }

    protected function completePropertyReturn(PropertyActionRequest $request, User $custodian): void
    {
        $attributes = [
            'status' => PropertyActionRequest::STATUS_EXECUTED,
            'executed_at' => now(),
            'sc_approved_by' => $custodian->id,
            'sc_approved_at' => $request->sc_approved_at ?? now(),
        ];

        $request->update($attributes);

        app(PropertyActionRequestWorkflowService::class)->syncCompiledSources(
            $request,
            $attributes,
            'Property return completed',
            fn (PropertyActionRequest $subject): string => sprintf(
                '%s was completed by Supply Custodian.',
                $subject->reference_code,
            ),
        );
    }

    protected function markDecision(PropertyActionRequest $request, User $custodian, string $status): void
    {
        $attributes = [
            'status' => $status,
            'sc_approved_by' => $custodian->id,
            'sc_approved_at' => now(),
        ];

        $request->update($attributes);

        app(PropertyActionRequestWorkflowService::class)->syncCompiledSources(
            $request,
            ['status' => $status, 'sc_approved_by' => $custodian->id, 'sc_approved_at' => now()],
            'Property return decision recorded',
            fn (PropertyActionRequest $subject): string => sprintf(
                '%s is now %s.',
                $subject->reference_code,
                PropertyActionRequest::statusLabelFor($status),
            ),
        );
    }

    protected function assertPendingDecision(PropertyActionRequest $request): void
    {
        if (! $request->isPendingDecision()) {
            throw new InvalidArgumentException('Request is not pending a transfer or disposal decision.');
        }
    }

    protected function primaryLine(PropertyActionRequest $request): PropertyActionRequestLine
    {
        $line = $request->lines->first();

        if (! $line instanceof PropertyActionRequestLine) {
            throw new InvalidArgumentException('Property action requests require at least one property line.');
        }

        if ($line->transfer_id !== null || $line->disposal_id !== null) {
            throw new InvalidArgumentException('This return already has a transfer or disposal.');
        }

        return $line;
    }

    protected function lineIssuance(PropertyActionRequestLine $line): Issuance
    {
        $issuance = $line->issuance;

        if (! $issuance instanceof Issuance) {
            throw new InvalidArgumentException('Property action requests require a linked issuance on each line.');
        }

        return $issuance;
    }

    protected function lineUnit(PropertyActionRequestLine $line, Issuance $issuance): InventoryUnit
    {
        $unit = $line->inventoryUnit;

        if ($unit instanceof InventoryUnit) {
            return $unit;
        }

        $unit = InventoryUnit::query()
            ->where('issuance_id', $issuance->id)
            ->where('status', InventoryUnit::STATUS_ISSUED)
            ->orderBy('id')
            ->first();

        if (! $unit instanceof InventoryUnit) {
            throw new InvalidArgumentException('Property on this issuance was already returned or disposed.');
        }

        return $unit;
    }

    protected function linkedPropertyReturn(Transfer $transfer): ?PropertyActionRequest
    {
        $line = PropertyActionRequestLine::query()
            ->with('propertyActionRequest')
            ->where('transfer_id', $transfer->id)
            ->first();

        return $line?->propertyActionRequest;
    }

    protected function linkedPropertyReturnForDisposal(Disposal $disposal): ?PropertyActionRequest
    {
        $line = PropertyActionRequestLine::query()
            ->with('propertyActionRequest')
            ->where('disposal_id', $disposal->id)
            ->first();

        return $line?->propertyActionRequest;
    }
}
