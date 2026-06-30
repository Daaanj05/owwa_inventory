<?php

namespace App\Services;

use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\PhysicalCountLine;
use App\Models\PhysicalCountScanEvent;
use App\Models\PhysicalCountSession;
use App\Support\InventoryUnitQrPayload;
use App\Support\PhysicalCountScanOutcome;
use App\Support\PhysicalCountScanResult;
use Illuminate\Support\Facades\DB;

class PhysicalCountScanService
{
    public function normalizePropertyNumber(string $rawCode): string
    {
        $payload = InventoryUnitQrPayload::resolve($rawCode);
        if ($payload !== null) {
            return $payload->propertyNumber;
        }

        $code = trim($rawCode);

        if (str_starts_with(strtoupper($code), 'OWWA:PN:')) {
            $code = substr($code, strlen('OWWA:PN:'));
        }

        return trim($code);
    }

    public function resolve(PhysicalCountSession $session, string $rawCode, ?int $userId = null): PhysicalCountScanResult
    {
        $payload = InventoryUnitQrPayload::resolve($rawCode);
        $propertyNumber = $payload?->propertyNumber ?? $this->normalizePropertyNumber($rawCode);

        if ($propertyNumber === '') {
            return $this->recordEvent(
                $session,
                $propertyNumber,
                PhysicalCountScanOutcome::NotFound,
                null,
                $userId,
                'Empty scan code.',
            );
        }

        if ($payload?->officeId !== null && (int) $payload->officeId !== (int) $session->office_id) {
            return $this->recordEvent(
                $session,
                $propertyNumber,
                PhysicalCountScanOutcome::NotFound,
                null,
                $userId,
                "Property {$propertyNumber} belongs to a different office.",
            );
        }

        $line = $session->lines()
            ->where('property_number', $propertyNumber)
            ->first();

        if ($line !== null) {
            if ($line->on_hand_count >= $line->balance_per_card) {
                return $this->recordEvent(
                    $session,
                    $propertyNumber,
                    PhysicalCountScanOutcome::Duplicate,
                    $line,
                    $userId,
                    "Property {$propertyNumber} was already counted.",
                );
            }

            $line->increment('on_hand_count');

            return $this->recordEvent(
                $session,
                $propertyNumber,
                PhysicalCountScanOutcome::Found,
                $line->fresh(),
                $userId,
                "Found: {$line->article} ({$propertyNumber}).",
            );
        }

        $unit = InventoryUnit::query()
            ->with(['item.category'])
            ->where('property_number', $propertyNumber)
            ->first();

        if ($unit !== null && $this->unitMatchesSession($unit, $session)) {
            if (! $session->hasBookListLoaded()) {
                $foundLine = $this->createFoundLineFromUnit($session, $unit, $propertyNumber);

                return $this->recordEvent(
                    $session,
                    $propertyNumber,
                    PhysicalCountScanOutcome::Found,
                    $foundLine,
                    $userId,
                    "Found: {$foundLine->article} ({$propertyNumber}).",
                );
            }

            $overageLine = $this->createOverageLineFromUnit($session, $unit, $propertyNumber);

            return $this->recordEvent(
                $session,
                $propertyNumber,
                PhysicalCountScanOutcome::Overage,
                $overageLine,
                $userId,
                "Overage: {$overageLine->article} ({$propertyNumber}) — not on expected list.",
            );
        }

        $issuance = Issuance::query()
            ->with(['item.category'])
            ->where('office_id', $session->office_id)
            ->where('property_number', $propertyNumber)
            ->first();

        if ($issuance !== null && $this->issuanceMatchesSession($issuance, $session)) {
            if (! $session->hasBookListLoaded()) {
                $foundLine = $this->createFoundLineFromIssuance($session, $issuance, $propertyNumber);

                return $this->recordEvent(
                    $session,
                    $propertyNumber,
                    PhysicalCountScanOutcome::Found,
                    $foundLine,
                    $userId,
                    "Found: {$foundLine->article} ({$propertyNumber}).",
                );
            }

            $overageLine = $this->createOverageLine($session, $issuance, $propertyNumber);

            return $this->recordEvent(
                $session,
                $propertyNumber,
                PhysicalCountScanOutcome::Overage,
                $overageLine,
                $userId,
                "Overage: {$overageLine->article} ({$propertyNumber}) — not on expected list.",
            );
        }

        return $this->recordEvent(
            $session,
            $propertyNumber,
            PhysicalCountScanOutcome::NotFound,
            null,
            $userId,
            "Unknown property tag: {$propertyNumber}.",
        );
    }

    protected function unitMatchesSession(InventoryUnit $unit, PhysicalCountSession $session): bool
    {
        $slug = $unit->item?->category?->getTemplateSlug();

        if ($slug !== $session->templateSlug()) {
            return false;
        }

        if ((int) $unit->office_id !== (int) $session->office_id) {
            return false;
        }

        if ($session->item_category_id && $unit->item?->item_category_id !== $session->item_category_id) {
            return false;
        }

        return true;
    }

    protected function issuanceMatchesSession(Issuance $issuance, PhysicalCountSession $session): bool
    {
        $slug = $issuance->item?->category?->getTemplateSlug();

        if ($slug !== $session->templateSlug()) {
            return false;
        }

        if ($session->item_category_id && $issuance->item?->item_category_id !== $session->item_category_id) {
            return false;
        }

        return true;
    }

    protected function createFoundLineFromUnit(
        PhysicalCountSession $session,
        InventoryUnit $unit,
        string $propertyNumber,
    ): PhysicalCountLine {
        $item = $unit->item;

        return DB::transaction(function () use ($session, $unit, $propertyNumber, $item): PhysicalCountLine {
            $existing = PhysicalCountLine::query()
                ->where('physical_count_session_id', $session->id)
                ->where('property_number', $propertyNumber)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->on_hand_count < $existing->balance_per_card) {
                    $existing->increment('on_hand_count');
                }

                return $existing->fresh();
            }

            return PhysicalCountLine::query()->create([
                'physical_count_session_id' => $session->id,
                'item_id' => $item?->id ?? $unit->item_id,
                'article' => $unit->article ?? $item?->name,
                'description' => $unit->description ?? $item?->description,
                'stock_number' => $unit->stock_number ?? $item?->item_code,
                'property_number' => $propertyNumber,
                'unit_of_measure' => $unit->unit_of_measure ?? $item?->unit,
                'balance_per_card' => 1,
                'on_hand_count' => 1,
            ]);
        });
    }

    protected function createFoundLineFromIssuance(
        PhysicalCountSession $session,
        Issuance $issuance,
        string $propertyNumber,
    ): PhysicalCountLine {
        $item = $issuance->item;

        return DB::transaction(function () use ($session, $issuance, $propertyNumber, $item): PhysicalCountLine {
            $existing = PhysicalCountLine::query()
                ->where('physical_count_session_id', $session->id)
                ->where('property_number', $propertyNumber)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->on_hand_count < $existing->balance_per_card) {
                    $existing->increment('on_hand_count');
                }

                return $existing->fresh();
            }

            return PhysicalCountLine::query()->create([
                'physical_count_session_id' => $session->id,
                'item_id' => $item?->id ?? $issuance->item_id,
                'article' => $item?->name,
                'description' => $item?->description,
                'stock_number' => $item?->item_code,
                'property_number' => $propertyNumber,
                'unit_of_measure' => $item?->unit,
                'balance_per_card' => 1,
                'on_hand_count' => 1,
            ]);
        });
    }

    protected function createOverageLineFromUnit(
        PhysicalCountSession $session,
        InventoryUnit $unit,
        string $propertyNumber,
    ): PhysicalCountLine {
        $item = $unit->item;

        return DB::transaction(function () use ($session, $unit, $propertyNumber, $item): PhysicalCountLine {
            $existing = PhysicalCountLine::query()
                ->where('physical_count_session_id', $session->id)
                ->where('property_number', $propertyNumber)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->on_hand_count < $existing->balance_per_card) {
                    $existing->increment('on_hand_count');
                }

                return $existing->fresh();
            }

            return PhysicalCountLine::query()->create([
                'physical_count_session_id' => $session->id,
                'item_id' => $item?->id ?? $unit->item_id,
                'article' => $unit->article ?? $item?->name,
                'description' => $unit->description ?? $item?->description,
                'stock_number' => $unit->stock_number ?? $item?->item_code,
                'property_number' => $propertyNumber,
                'unit_of_measure' => $unit->unit_of_measure ?? $item?->unit,
                'balance_per_card' => 0,
                'on_hand_count' => 1,
                'remarks' => 'Overage — scanned but not on expected list',
            ]);
        });
    }

    protected function createOverageLine(
        PhysicalCountSession $session,
        Issuance $issuance,
        string $propertyNumber,
    ): PhysicalCountLine {
        $item = $issuance->item;

        return DB::transaction(function () use ($session, $issuance, $propertyNumber, $item): PhysicalCountLine {
            $existing = PhysicalCountLine::query()
                ->where('physical_count_session_id', $session->id)
                ->where('property_number', $propertyNumber)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->on_hand_count < $existing->balance_per_card) {
                    $existing->increment('on_hand_count');
                }

                return $existing->fresh();
            }

            return PhysicalCountLine::query()->create([
                'physical_count_session_id' => $session->id,
                'item_id' => $item?->id ?? $issuance->item_id,
                'article' => $item?->name,
                'description' => $item?->description,
                'stock_number' => $item?->item_code,
                'property_number' => $propertyNumber,
                'unit_of_measure' => $item?->unit,
                'balance_per_card' => 0,
                'on_hand_count' => 1,
                'remarks' => 'Overage — scanned but not on expected list',
            ]);
        });
    }

    protected function recordEvent(
        PhysicalCountSession $session,
        string $propertyNumber,
        PhysicalCountScanOutcome $outcome,
        ?PhysicalCountLine $line,
        ?int $userId,
        ?string $message,
    ): PhysicalCountScanResult {
        PhysicalCountScanEvent::query()->create([
            'physical_count_session_id' => $session->id,
            'property_number' => $propertyNumber,
            'result' => $outcome->value,
            'physical_count_line_id' => $line?->id,
            'scanned_by' => $userId ?? auth()->id(),
            'scanned_at' => now(),
        ]);

        return new PhysicalCountScanResult($outcome, $line, $message);
    }
}
