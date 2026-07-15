<?php

namespace App\Support;

use App\Models\Item;
use App\Models\Requisition;
use Illuminate\Support\Collection;

class EmployeeRequisitionOriginalSubmission
{
    /**
     * @return array{purpose: ?string, lines: array<int, array{item_id: int, item_name: string, quantity: int}>}
     */
    public static function capture(Requisition $requisition): array
    {
        $requisition->loadMissing('items.item');

        return [
            'purpose' => $requisition->purpose,
            'lines' => $requisition->items
                ->sortBy('id')
                ->values()
                ->map(fn ($line): array => [
                    'item_id' => (int) $line->item_id,
                    'item_name' => (string) ($line->item?->name ?? 'Item'),
                    'quantity' => (int) $line->quantity,
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     */
    public static function differsFromCurrent(Requisition $requisition, ?array $snapshot = null): bool
    {
        $snapshot ??= $requisition->original_submission;

        if (! is_array($snapshot) || $snapshot === []) {
            return false;
        }

        $requisition->loadMissing('items');

        $originalPurpose = trim((string) ($snapshot['purpose'] ?? ''));
        $currentPurpose = trim((string) ($requisition->purpose ?? ''));

        if ($originalPurpose !== $currentPurpose) {
            return true;
        }

        /** @var Collection<int, array{item_id: int, quantity: int}> $originalLines */
        $originalLines = collect($snapshot['lines'] ?? [])
            ->map(fn (array $line): array => [
                'item_id' => (int) ($line['item_id'] ?? 0),
                'quantity' => (int) ($line['quantity'] ?? 0),
            ])
            ->filter(fn (array $line): bool => $line['item_id'] > 0)
            ->sortBy(['item_id', 'quantity'])
            ->values();

        $currentLines = $requisition->items
            ->map(fn ($line): array => [
                'item_id' => (int) $line->item_id,
                'quantity' => (int) $line->quantity,
            ])
            ->sortBy(['item_id', 'quantity'])
            ->values();

        return $originalLines->all() !== $currentLines->all();
    }

    /**
     * @return array<int, array{item_id: int, item_name: string, original_quantity: int, current_quantity: ?int, change: string}>
     */
    public static function lineComparisonRows(Requisition $requisition): array
    {
        $snapshot = $requisition->original_submission;

        if (! is_array($snapshot) || $snapshot === []) {
            return [];
        }

        $requisition->loadMissing('items.item');

        $currentByItem = $requisition->items
            ->groupBy(fn ($line): int => (int) $line->item_id)
            ->map(fn (Collection $lines): int => (int) $lines->sum('quantity'));

        $originalByItem = collect($snapshot['lines'] ?? [])
            ->groupBy(fn (array $line): int => (int) ($line['item_id'] ?? 0))
            ->map(function (Collection $lines): array {
                $first = $lines->first() ?? [];

                return [
                    'item_name' => (string) ($first['item_name'] ?? 'Item'),
                    'quantity' => (int) $lines->sum(fn (array $line): int => (int) ($line['quantity'] ?? 0)),
                ];
            });

        $itemIds = $originalByItem->keys()
            ->merge($currentByItem->keys())
            ->unique()
            ->filter(fn (int $id): bool => $id > 0)
            ->sort()
            ->values();

        $names = Item::query()
            ->whereIn('id', $itemIds->all())
            ->pluck('name', 'id');

        $rows = [];

        foreach ($itemIds as $itemId) {
            $originalQty = (int) ($originalByItem[$itemId]['quantity'] ?? 0);
            $currentQty = $currentByItem->has($itemId) ? (int) $currentByItem[$itemId] : null;
            $itemName = (string) ($names[$itemId] ?? $originalByItem[$itemId]['item_name'] ?? 'Item');

            $change = match (true) {
                $currentQty === null => 'removed',
                ! $originalByItem->has($itemId) => 'added',
                $originalQty !== $currentQty => 'changed',
                default => 'unchanged',
            };

            $rows[] = [
                'item_id' => $itemId,
                'item_name' => $itemName,
                'original_quantity' => $originalQty,
                'current_quantity' => $currentQty,
                'change' => $change,
            ];
        }

        return $rows;
    }

    public static function originalPurpose(Requisition $requisition): ?string
    {
        $snapshot = $requisition->original_submission;

        if (! is_array($snapshot)) {
            return null;
        }

        $purpose = $snapshot['purpose'] ?? null;

        return filled($purpose) ? (string) $purpose : null;
    }
}
