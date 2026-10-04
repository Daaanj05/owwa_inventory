<?php

namespace App\Services;

use App\Models\PropertyActionRequest;
use App\Models\PropertyActionRequestLine;
use App\Models\User;
use App\Notifications\RequisitionWorkflowDatabaseNotification;
use App\Support\NotificationRecipientResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PropertyActionRequestCompileService
{
    /**
     * @return array<int, string>
     */
    public function eligibleEmployeePropertyReturnOptions(
        User $unitConsolidator,
        ?int $officeId = null,
        ?int $departmentId = null,
    ): array {
        if (! $unitConsolidator->isUnitConsolidator()) {
            return [];
        }

        return $this->eligibleEmployeePropertyReturnsQuery($unitConsolidator, $officeId, $departmentId)
            ->orderByDesc('created_at')
            ->get()
            ->mapWithKeys(fn (PropertyActionRequest $request): array => [
                $request->id => $this->employeePropertyReturnOptionLabel($request),
            ])
            ->all();
    }

    public function employeePropertyReturnOptionLabel(PropertyActionRequest $request): string
    {
        $request->loadMissing(['requestedBy']);

        if (! isset($request->lines_count)) {
            $request->loadCount('lines');
        }

        $reference = $request->reference_code ?? '#'.$request->id;
        $employeeName = $request->requestedBy?->name ?? 'Employee';
        $lineCount = (int) $request->lines_count;
        $lineLabel = Str::plural('item', $lineCount);
        $reason = $request->reasonLabel();

        return "{$reference} — {$employeeName} · {$lineCount} {$lineLabel} · {$reason}";
    }

    public function eligibleEmployeePropertyReturnsQuery(
        User $unitConsolidator,
        ?int $officeId = null,
        ?int $departmentId = null,
    ): Builder {
        $query = PropertyActionRequest::query()
            ->where('status', PropertyActionRequest::STATUS_PENDING_UC)
            ->whereNotNull('uc_approved_at')
            ->whereNull('compiled_into_property_action_request_id')
            ->whereNull('archived_at')
            ->whereHas('requestedBy', fn (Builder $query): Builder => $query->where('role', User::ROLE_EMPLOYEE))
            ->with(['requestedBy', 'office', 'department'])
            ->withCount('lines');

        if ($officeId !== null && $officeId > 0) {
            $query->where('office_id', $officeId);
        }

        if ($departmentId !== null && $departmentId > 0) {
            $query->where('department_id', $departmentId);
        }

        $assignments = $unitConsolidator->relationLoaded('assignments')
            ? $unitConsolidator->assignments
            : $unitConsolidator->assignments()->get();

        if ($assignments->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $scope) use ($assignments): void {
            foreach ($assignments as $assignment) {
                $scope->orWhere(function (Builder $row) use ($assignment): void {
                    $row->where('office_id', (int) $assignment->office_id)
                        ->where('department_id', (int) $assignment->department_id);
                });
            }
        });
    }

    /**
     * @param  Collection<int, PropertyActionRequest>|SupportCollection<int, PropertyActionRequest>|array<int, int|PropertyActionRequest>  $sources
     */
    public function createCompiledSubmission(
        User $unitConsolidator,
        Collection|SupportCollection|array $sources,
        ?string $remarks = null,
        ?string $actionType = null,
        ?string $reasonCode = null,
    ): PropertyActionRequest {
        if (! $unitConsolidator->isUnitConsolidator()) {
            throw new InvalidArgumentException('Only Unit Consolidators can compile property returns.');
        }

        $eligible = $this->resolveEligibleSources($unitConsolidator, $sources);

        if ($eligible->isEmpty()) {
            throw new InvalidArgumentException('Select at least one UC-approved employee property return that has not been compiled yet.');
        }

        $officeIds = $eligible->pluck('office_id')->unique()->filter()->values();
        $departmentIds = $eligible->pluck('department_id')->unique()->filter()->values();

        if ($officeIds->count() !== 1) {
            throw new InvalidArgumentException('All selected property returns must belong to the same office.');
        }

        $officeId = (int) $officeIds->first();

        if ($departmentIds->count() !== 1) {
            throw new InvalidArgumentException('All selected property returns must belong to the same department.');
        }

        $departmentId = (int) $departmentIds->first();

        if (! $unitConsolidator->coversOfficeDepartment($officeId, $departmentId)) {
            throw new InvalidArgumentException('Selected property returns are outside your office/department coverage.');
        }

        return DB::transaction(function () use ($unitConsolidator, $eligible, $officeId, $departmentId, $remarks, $actionType, $reasonCode): PropertyActionRequest {
            $actionTypes = $eligible->pluck('action_type')->unique()->values();
            $reasonCodes = $eligible->pluck('reason_code')->unique()->values();

            $resolvedActionType = filled($actionType)
                ? (string) $actionType
                : (string) ($actionTypes->count() === 1
                    ? $actionTypes->first()
                    : PropertyActionRequest::ACTION_RETURN);

            if (filled($reasonCode)) {
                $resolvedReasonCode = (string) $reasonCode;
            } elseif ($reasonCodes->count() === 1) {
                $resolvedReasonCode = (string) $reasonCodes->first();
            } else {
                $resolvedReasonCode = $resolvedActionType === PropertyActionRequest::ACTION_RETURN
                    ? 'good_condition'
                    : (string) ($reasonCodes->first() ?? 'good_condition');
            }

            $batch = PropertyActionRequest::query()->create([
                'action_type' => $resolvedActionType,
                'reason_code' => $resolvedReasonCode,
                'reason_detail' => $this->buildCompiledReasonDetail($eligible, $remarks),
                'requested_by' => $unitConsolidator->id,
                'accountable_user_id' => $unitConsolidator->id,
                'office_id' => $officeId,
                'department_id' => $departmentId,
                'status' => PropertyActionRequest::STATUS_PENDING_SC,
                'uc_approved_by' => $unitConsolidator->id,
                'uc_approved_at' => now(),
                'uc_remarks' => $remarks,
            ]);

            $sort = 0;

            foreach ($eligible as $source) {
                $source->loadMissing('lines');

                foreach ($source->lines as $line) {
                    PropertyActionRequestLine::query()->create([
                        'property_action_request_id' => $batch->id,
                        'issuance_id' => $line->issuance_id,
                        'inventory_unit_id' => $line->inventory_unit_id,
                        'quantity' => $line->quantity,
                        'sort_order' => $sort++,
                    ]);
                }

                $source->update([
                    'compiled_into_property_action_request_id' => $batch->id,
                    'uc_approved_by' => $unitConsolidator->id,
                    'uc_approved_at' => $source->uc_approved_at ?? now(),
                    'uc_remarks' => $remarks ?? $source->uc_remarks,
                    'status' => PropertyActionRequest::STATUS_PENDING_SC,
                ]);
            }

            app(UserActivityLogger::class)->record(
                $unitConsolidator,
                'compiled',
                'Compiled property return '.$batch->reference_code.' from '.$eligible->count().' employee return'.($eligible->count() === 1 ? '' : 's'),
                $batch,
                [
                    'source_count' => $eligible->count(),
                    'line_count' => $sort,
                ],
            );

            $custodians = app(NotificationRecipientResolver::class)->supplyCustodiansForRegionalOffice();

            foreach ($custodians as $custodian) {
                $custodian->notify(new RequisitionWorkflowDatabaseNotification(
                    'Property return awaiting SC approval',
                    sprintf(
                        '%s — consolidated from %d employee return%s.',
                        $batch->reference_code,
                        $eligible->count(),
                        $eligible->count() === 1 ? '' : 's',
                    ),
                    propertyActionRequestId: (int) $batch->id,
                ));
            }

            foreach ($eligible as $source) {
                $requester = $source->requestedBy;

                if ($requester instanceof User) {
                    $requester->notify(new RequisitionWorkflowDatabaseNotification(
                        'Property return status updated',
                        sprintf(
                            '%s was compiled and submitted to Supply Custodian as %s.',
                            $source->reference_code,
                            $batch->reference_code,
                        ),
                        propertyActionRequestId: (int) $source->id,
                    ));
                }
            }

            return $batch->fresh(['lines']);
        });
    }

    /**
     * @param  Collection<int, PropertyActionRequest>|SupportCollection<int, PropertyActionRequest>|array<int, int|PropertyActionRequest>  $sources
     * @return Collection<int, PropertyActionRequest>
     */
    public function resolveEligibleSources(User $unitConsolidator, Collection|SupportCollection|array $sources): Collection
    {
        $ids = collect($sources)
            ->map(function (mixed $source): ?int {
                if ($source instanceof PropertyActionRequest) {
                    return (int) $source->id;
                }

                return is_numeric($source) ? (int) $source : null;
            })
            ->filter(fn (?int $id): bool => $id !== null && $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return new Collection;
        }

        return PropertyActionRequest::query()
            ->with(['lines', 'requestedBy'])
            ->whereIn('id', $ids->all())
            ->where('status', PropertyActionRequest::STATUS_PENDING_UC)
            ->whereNotNull('uc_approved_at')
            ->whereNull('compiled_into_property_action_request_id')
            ->whereNull('archived_at')
            ->whereHas('requestedBy', fn ($query) => $query->where('role', User::ROLE_EMPLOYEE))
            ->get()
            ->filter(function (PropertyActionRequest $request) use ($unitConsolidator): bool {
                if ($request->department_id === null) {
                    return false;
                }

                return $unitConsolidator->coversOfficeDepartment(
                    (int) $request->office_id,
                    (int) $request->department_id,
                );
            })
            ->values();
    }

    /**
     * @param  Collection<int, PropertyActionRequest>|SupportCollection<int, PropertyActionRequest>  $records
     * @return Collection<int, PropertyActionRequest>
     */
    public function filterEligible(Collection|SupportCollection $records): Collection
    {
        return $records
            ->filter(function (mixed $record): bool {
                if (! $record instanceof PropertyActionRequest) {
                    return false;
                }

                return $record->status === PropertyActionRequest::STATUS_PENDING_UC
                    && $record->uc_approved_at !== null
                    && $record->compiled_into_property_action_request_id === null
                    && $record->isEmployeeRequest();
            })
            ->values();
    }

    /**
     * @param  Collection<int, PropertyActionRequest>  $sources
     */
    protected function buildCompiledReasonDetail(Collection $sources, ?string $remarks): string
    {
        $parts = $sources
            ->map(function (PropertyActionRequest $source): string {
                $ref = $source->reference_code ?? '#'.$source->id;
                $employee = $source->requestedBy?->name ?? 'Employee';
                $reason = $source->reasonLabel();

                return "{$ref} ({$employee}): {$reason}";
            })
            ->all();

        $detail = 'Compiled employee property returns: '.implode('; ', $parts);

        if (filled($remarks)) {
            $detail .= "\nUC remarks: ".$remarks;
        }

        return $detail;
    }
}
