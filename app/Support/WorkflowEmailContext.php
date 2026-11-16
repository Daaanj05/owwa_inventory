<?php

namespace App\Support;

use App\Models\PropertyActionRequest;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Models\WorkflowEmailNotification;

final readonly class WorkflowEmailContext
{
    /**
     * @param  array<int, array{name: string, reference: string}>  $employees
     * @param  list<array{name: string, quantity: int, unit: ?string}>  $requestedItems
     */
    public function __construct(
        public ?string $employeeName = null,
        public ?string $officeName = null,
        public ?string $departmentName = null,
        public array $employees = [],
        public array $requestedItems = [],
    ) {}

    public static function fromRequisition(Requisition $requisition): self
    {
        $requisition->loadMissing(['requestedBy', 'office', 'department']);

        return new self(
            employeeName: $requisition->requestedBy?->name,
            officeName: $requisition->office?->name,
            departmentName: $requisition->department?->name,
            requestedItems: self::requestedLineItems($requisition),
        );
    }

    /**
     * @return list<array{name: string, quantity: int, unit: ?string}>
     */
    public static function requestedLineItems(Requisition $requisition): array
    {
        $requisition->loadMissing(['items.item']);

        return $requisition->items
            ->sortBy('id')
            ->map(function (RequisitionItem $line): array {
                return [
                    'name' => filled($line->item?->name) ? (string) $line->item->name : 'Item',
                    'quantity' => (int) $line->quantity,
                    'unit' => filled($line->item?->unit) ? (string) $line->item->unit : null,
                ];
            })
            ->filter(fn (array $line): bool => $line['quantity'] > 0)
            ->values()
            ->all();
    }

    /**
     * Lines saved after the notification is built still belong in the email.
     *
     * @return list<array{name: string, quantity: int, unit: ?string}>
     */
    public static function requestedLineItemsForMail(?self $context, ?int $workflowEmailNotificationId): array
    {
        $requisitionId = 0;

        if ($workflowEmailNotificationId) {
            $requisitionId = (int) (WorkflowEmailNotification::query()
                ->whereKey($workflowEmailNotificationId)
                ->value('requisition_id') ?? 0);
        }

        if ($requisitionId > 0) {
            $requisition = Requisition::query()->find($requisitionId);

            if ($requisition instanceof Requisition) {
                $fromDatabase = self::requestedLineItems($requisition);

                if ($fromDatabase !== []) {
                    return $fromDatabase;
                }
            }
        }

        return $context?->requestedItems ?? [];
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
