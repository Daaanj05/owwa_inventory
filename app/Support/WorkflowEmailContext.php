<?php

namespace App\Support;

use App\Models\PropertyActionRequest;
use App\Models\Requisition;
use App\Models\User;

final readonly class WorkflowEmailContext
{
    /**
     * @param  array<int, array{name: string, reference: string}>  $employees
     */
    public function __construct(
        public ?string $employeeName = null,
        public ?string $officeName = null,
        public ?string $departmentName = null,
        public array $employees = [],
    ) {}

    public static function fromRequisition(Requisition $requisition): self
    {
        $requisition->loadMissing(['requestedBy', 'office', 'department']);

        return new self(
            employeeName: $requisition->requestedBy?->name,
            officeName: $requisition->office?->name,
            departmentName: $requisition->department?->name,
        );
    }

    public static function fromPropertyActionRequest(PropertyActionRequest $request): self
    {
        $request->loadMissing(['requestedBy', 'office', 'department']);

        return new self(
            employeeName: $request->requestedBy?->name,
            officeName: $request->office?->name,
            departmentName: $request->department?->name,
        );
    }

    /**
     * @param  iterable<int, Requisition|PropertyActionRequest>  $sources
     */
    public static function fromSourceRecords(iterable $sources, ?string $officeName = null, ?string $departmentName = null): self
    {
        $employees = [];

        foreach ($sources as $source) {
            $source->loadMissing(['requestedBy', 'office', 'department']);

            $name = $source->requestedBy?->name;
            if (! filled($name)) {
                continue;
            }

            $reference = $source instanceof Requisition
                ? ($source->displayTransactionNumber() ?? $source->reference_code ?? "#{$source->id}")
                : ($source->reference_code ?? "#{$source->id}");

            $employees[] = [
                'name' => $name,
                'reference' => (string) $reference,
            ];

            $officeName ??= $source->office?->name;
            $departmentName ??= $source->department?->name;
        }

        return new self(
            employeeName: count($employees) === 1 ? $employees[0]['name'] : null,
            officeName: $officeName,
            departmentName: $departmentName,
            employees: $employees,
        );
    }

    public function forUnitConsolidatorRecipient(User $recipient): bool
    {
        return $recipient->isUnitConsolidator();
    }

    public function hasPanelDetails(): bool
    {
        return filled($this->employeeName)
            || filled($this->officeName)
            || filled($this->departmentName)
            || $this->employees !== [];
    }
}
