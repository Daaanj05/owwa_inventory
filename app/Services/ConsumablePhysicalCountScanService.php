<?php

namespace App\Services;

use App\Models\Item;
use App\Models\PhysicalCountLine;
use App\Models\PhysicalCountScanEvent;
use App\Models\PhysicalCountSession;
use App\Support\ConsumableStockQrPayload;
use App\Support\InventoryUnitQrPayload;
use App\Support\PhysicalCountScanOutcome;
use App\Support\PhysicalCountScanResult;

class ConsumablePhysicalCountScanService
{
    public function __construct(
        protected InventoryStockService $stockService,
    ) {}

    public function resolve(PhysicalCountSession $session, string $rawCode, ?int $userId = null): PhysicalCountScanResult
    {
        if (! $session->supportsStockQrScanning()) {
            return $this->recordEvent(
                $session,
                null,
                PhysicalCountScanOutcome::NotFound,
                null,
                $userId,
                'Stock QR scanning is only available for consumable (RPCI) sessions.',
            );
        }

        if (InventoryUnitQrPayload::resolve($rawCode) !== null) {
            return $this->recordEvent(
                $session,
                null,
                PhysicalCountScanOutcome::NotFound,
                null,
                $userId,
                'That QR is a property tag. Use a stock / shelf QR for consumable counts.',
            );
        }

        $payload = ConsumableStockQrPayload::resolve($rawCode);

        if ($payload === null) {
            return $this->recordEvent(
                $session,
                null,
                PhysicalCountScanOutcome::NotFound,
                null,
                $userId,
                'Unrecognized stock QR code.',
            );
        }

        if ((int) $payload->officeId !== (int) $session->office_id) {
            return $this->recordEvent(
                $session,
                (string) $payload->itemId,
                PhysicalCountScanOutcome::NotFound,
                null,
                $userId,
                'Scanned stock belongs to a different office.',
            );
        }

        $item = Item::query()->with('category')->find($payload->itemId);

        if ($item === null || $item->category?->getTemplateSlug() !== 'consumables') {
            return $this->recordEvent(
                $session,
                (string) $payload->itemId,
                PhysicalCountScanOutcome::NotFound,
                null,
                $userId,
                'Stock item not found or is not a consumable.',
            );
        }

        if (filled($session->inventory_type) && $item->inventory_type !== $session->inventory_type) {
            return $this->recordEvent(
                $session,
                (string) $item->item_code,
                PhysicalCountScanOutcome::NotFound,
                null,
                $userId,
                'Item inventory type does not match this RPCI session.',
            );
        }

        if ($session->item_category_id && (int) $item->item_category_id !== (int) $session->item_category_id) {
            return $this->recordEvent(
                $session,
                (string) $item->item_code,
                PhysicalCountScanOutcome::NotFound,
                null,
                $userId,
                'Item category does not match this count session.',
            );
        }

        $line = $this->findOrCreateLine($session, $item);

        return $this->recordEvent(
            $session,
            (string) ($item->item_code ?? $item->id),
            PhysicalCountScanOutcome::NeedsQuantity,
            $line,
            $userId,
            "Enter counted quantity for {$item->name}.",
        );
    }

    public function applyQuantity(
        PhysicalCountSession $session,
        PhysicalCountLine $line,
        int $quantity,
        ?int $userId = null,
    ): PhysicalCountScanResult {
        if ((int) $line->physical_count_session_id !== (int) $session->id) {
            return new PhysicalCountScanResult(
                PhysicalCountScanOutcome::NotFound,
                null,
                'Count line does not belong to this session.',
            );
        }

        $quantity = max(0, $quantity);
        $line->update(['on_hand_count' => $quantity]);

        $outcome = $line->balance_per_card > 0 && $quantity > $line->balance_per_card
            ? PhysicalCountScanOutcome::Overage
            : PhysicalCountScanOutcome::Found;

        return $this->recordEvent(
            $session,
            (string) ($line->stock_number ?? $line->item_id),
            $outcome,
            $line->fresh(),
            $userId,
            "Counted {$quantity} for {$line->article}.",
        );
    }

    protected function findOrCreateLine(PhysicalCountSession $session, Item $item): PhysicalCountLine
    {
        $existing = $session->lines()->where('item_id', $item->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $balance = max(0, $this->stockService->getStock((int) $item->id, (int) $session->office_id));

        return $session->lines()->create([
            'item_id' => $item->id,
            'article' => $item->name,
            'description' => $item->description,
            'stock_number' => $item->item_code,
            'unit_of_measure' => $item->unit,
            'balance_per_card' => $balance,
            'on_hand_count' => 0,
            'remarks' => $session->hasBookListLoaded() ? null : 'Added via stock QR',
        ]);
    }

    protected function recordEvent(
        PhysicalCountSession $session,
        ?string $code,
        PhysicalCountScanOutcome $outcome,
        ?PhysicalCountLine $line,
        ?int $userId,
        string $message,
    ): PhysicalCountScanResult {
        PhysicalCountScanEvent::query()->create([
            'physical_count_session_id' => $session->id,
            'physical_count_line_id' => $line?->id,
            'property_number' => $code !== null && $code !== '' ? $code : 'stock',
            'result' => $outcome->value,
            'scanned_by' => $userId,
            'scanned_at' => now(),
        ]);

        return new PhysicalCountScanResult($outcome, $line, $message);
    }
}
