<?php

namespace App\Support;

use App\Filament\Resources\Disposals\DisposalResource;
use App\Filament\Resources\IncidentReports\IncidentReportResource;
use App\Filament\Resources\Transfers\TransferResource;
use App\Models\Disposal;
use App\Models\InventoryUnit;
use App\Models\User;
use App\Services\DisposalInventoryUnitService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;

class ScanAssetHandoff
{
    /**
     * @return array{unit: InventoryUnit, categoryId: int, categorySlug: string}|null
     */
    public static function resolveActionableUnit(?InventoryUnit $unit, ?User $user = null): ?array
    {
        $user ??= Filament::auth()->user();

        if (! $unit instanceof InventoryUnit) {
            return null;
        }

        $unit->loadMissing(['item.category', 'office', 'issuance', 'acquisition']);
        $slug = $unit->item?->category?->getTemplateSlug();

        if (! in_array($slug, ['ppe', 'semi_expendable'], true)) {
            return null;
        }

        if ($unit->status === InventoryUnit::STATUS_DISPOSED) {
            return null;
        }

        $scopedOfficeId = CustodianOfficeScope::inventoryOfficeId($user instanceof User ? $user : null);
        if ($scopedOfficeId !== null && (int) $unit->office_id !== $scopedOfficeId) {
            return null;
        }

        $categoryId = (int) ($unit->item?->item_category_id ?? 0);
        if ($categoryId <= 0) {
            return null;
        }

        return [
            'unit' => $unit,
            'categoryId' => $categoryId,
            'categorySlug' => (string) $slug,
        ];
    }

    public static function isUnitClaimed(InventoryUnit $unit, ?int $excludeDisposalId = null): bool
    {
        return Disposal::query()
            ->where('inventory_unit_id', $unit->id)
            ->when(
                $excludeDisposalId !== null,
                fn ($query) => $query->where('id', '!=', $excludeDisposalId),
            )
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public static function disposalFormDefaults(InventoryUnit $unit, string $disposalType): array
    {
        $defaults = [
            'disposal_type' => $disposalType,
            'item_id' => $unit->item_id,
            'office_id' => $unit->office_id,
            'inventory_unit_id' => $unit->id,
            'item_category_filter' => $unit->item?->item_category_id,
            'disposal_date' => now()->toDateString(),
        ];

        $unitService = app(DisposalInventoryUnitService::class);
        $unitService->applyUnitToFormState($unit, function (string $key, mixed $value) use (&$defaults): void {
            $defaults[$key] = $value;
        });

        return $defaults;
    }

    /**
     * @return array<string, mixed>
     */
    public static function transferFormDefaults(InventoryUnit $unit, int $categoryId): array
    {
        return [
            'item_id' => $unit->item_id,
            'from_office_id' => $unit->office_id,
            'property_number' => $unit->property_number,
            'quantity' => 1,
            'item_category_filter' => $categoryId,
            'transfer_date' => now()->toDateString(),
        ];
    }

    public static function disposalCreateUrl(InventoryUnit $unit, int $categoryId): string
    {
        return DisposalResource::getUrl('index', [
            'category' => $categoryId,
            'create' => 1,
            'inventory_unit_id' => $unit->id,
        ]);
    }

    public static function incidentCreateUrl(InventoryUnit $unit): string
    {
        return IncidentReportResource::getUrl('index', [
            'create' => 1,
            'inventory_unit_id' => $unit->id,
        ]);
    }

    public static function transferCreateUrl(InventoryUnit $unit, int $categoryId): string
    {
        return TransferResource::getUrl('index', [
            'category' => $categoryId,
            'create' => 1,
            'item_id' => $unit->item_id,
            'from_office' => $unit->office_id,
            'property_number' => $unit->property_number,
        ]);
    }

    public static function notifyBlocked(string $title, string $body): void
    {
        Notification::make()
            ->title($title)
            ->body($body)
            ->danger()
            ->send();
    }
}
