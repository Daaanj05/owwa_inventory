<?php

namespace App\Filament\Pages;

use App\Models\InventoryUnit;
use App\Models\User;
use App\Services\PhysicalCountScanService;
use App\Support\ScanAssetHandoff;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;
use UnitEnum;

class ScanAsset extends Page
{
    protected static bool $shouldRegisterNavigation = true;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static string|UnitEnum|null $navigationGroup = 'Regional supply';

    protected static ?string $navigationLabel = 'Scan asset';

    protected static ?int $navigationSort = 45;

    protected static ?string $title = 'Scan asset';

    protected string $view = 'filament.pages.scan-asset';

    public string $manualCode = '';

    public ?int $resolvedUnitId = null;

    public ?string $resolvedPropertyNumber = null;

    public ?string $resolvedItemName = null;

    public ?string $resolvedOfficeName = null;

    public ?string $resolvedStatus = null;

    public ?string $resolvedCategorySlug = null;

    public ?int $resolvedCategoryId = null;

    public ?string $resolvedPublicUrl = null;

    public static function canAccess(): bool
    {
        if (! config('inventory.qr_public_lookup', true)) {
            return false;
        }

        $user = Filament::auth()->user();

        return $user instanceof User && $user->isSupplyCustodian();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Scan asset';
    }

    public function getHeading(): string|Htmlable
    {
        return new HtmlString(
            View::make('filament.pages.partials.scan-asset-heading')->render()
        );
    }

    public function getSubheading(): ?string
    {
        return 'Look up PPE and semi-expendable property tags, then open Disposal, Incident report, or Transfer.';
    }

    /**
     * @return array<int, string>
     */
    public function getPageClasses(): array
    {
        return ['owwa-physical-count-scan-page', 'owwa-scan-asset-page'];
    }

    public function resolveScan(string $code): void
    {
        $this->clearResolvedAsset();

        $unit = $this->findUnitFromScan($code);

        if ($unit === null) {
            Notification::make()
                ->title('Asset not found')
                ->body('No PPE or semi-expendable inventory unit matched that code.')
                ->danger()
                ->send();

            return;
        }

        $resolved = ScanAssetHandoff::resolveActionableUnit($unit);

        if ($resolved === null) {
            if ($unit->status === InventoryUnit::STATUS_DISPOSED) {
                ScanAssetHandoff::notifyBlocked(
                    'Unit already disposed',
                    'This property tag is already marked disposed.',
                );
            } else {
                ScanAssetHandoff::notifyBlocked(
                    'Cannot use this tag',
                    'Only in-stock or issued PPE and semi-expendable units in your office can start an action.',
                );
            }

            return;
        }

        if (ScanAssetHandoff::isUnitClaimed($resolved['unit'])) {
            ScanAssetHandoff::notifyBlocked(
                'Unit already claimed',
                'This inventory unit is already linked to a disposal or incident report draft.',
            );

            return;
        }

        $this->resolvedUnitId = $resolved['unit']->id;
        $this->resolvedPropertyNumber = (string) $resolved['unit']->property_number;
        $this->resolvedItemName = (string) ($resolved['unit']->article ?? $resolved['unit']->item?->name ?? '—');
        $this->resolvedOfficeName = (string) ($resolved['unit']->office?->name ?? '—');
        $this->resolvedStatus = str_replace('_', ' ', (string) $resolved['unit']->status);
        $this->resolvedCategorySlug = $resolved['categorySlug'];
        $this->resolvedCategoryId = $resolved['categoryId'];
        $this->resolvedPublicUrl = route('inventory.assets.unit.show', ['inventoryUnit' => $resolved['unit']->id]);
    }

    public function submitManualCode(): void
    {
        if (blank($this->manualCode)) {
            return;
        }

        $this->resolveScan($this->manualCode);
        $this->manualCode = '';
    }

    public function clearResolvedAsset(): void
    {
        $this->resolvedUnitId = null;
        $this->resolvedPropertyNumber = null;
        $this->resolvedItemName = null;
        $this->resolvedOfficeName = null;
        $this->resolvedStatus = null;
        $this->resolvedCategorySlug = null;
        $this->resolvedCategoryId = null;
        $this->resolvedPublicUrl = null;
    }

    public function startDisposal(): void
    {
        $this->redirectToAction('disposal');
    }

    public function startIncident(): void
    {
        $this->redirectToAction('incident');
    }

    public function startTransfer(): void
    {
        $this->redirectToAction('transfer');
    }

    protected function redirectToAction(string $action): void
    {
        $unit = $this->resolvedUnitId
            ? InventoryUnit::query()->with(['item.category', 'office'])->find($this->resolvedUnitId)
            : null;

        $resolved = ScanAssetHandoff::resolveActionableUnit($unit);

        if ($resolved === null) {
            ScanAssetHandoff::notifyBlocked(
                'Scan expired',
                'Scan the property tag again to continue.',
            );
            $this->clearResolvedAsset();

            return;
        }

        if (ScanAssetHandoff::isUnitClaimed($resolved['unit'])) {
            ScanAssetHandoff::notifyBlocked(
                'Unit already claimed',
                'This inventory unit is already linked to a disposal or incident report draft.',
            );

            return;
        }

        $url = match ($action) {
            'disposal' => ScanAssetHandoff::disposalCreateUrl($resolved['unit'], $resolved['categoryId']),
            'incident' => ScanAssetHandoff::incidentCreateUrl($resolved['unit']),
            'transfer' => ScanAssetHandoff::transferCreateUrl($resolved['unit'], $resolved['categoryId']),
            default => null,
        };

        if ($url === null) {
            return;
        }

        $this->redirect($url, navigate: false);
    }

    protected function findUnitFromScan(string $code): ?InventoryUnit
    {
        $payload = \App\Support\InventoryUnitQrPayload::resolve($code);

        if ($payload?->inventoryUnitId !== null) {
            $unit = InventoryUnit::query()
                ->with(['item.category', 'office', 'issuance', 'acquisition'])
                ->find($payload->inventoryUnitId);

            if ($unit !== null) {
                return $unit;
            }
        }

        $propertyNumber = app(PhysicalCountScanService::class)->normalizePropertyNumber($code);

        if (blank($propertyNumber)) {
            return null;
        }

        return InventoryUnit::query()
            ->with(['item.category', 'office', 'issuance', 'acquisition'])
            ->where('property_number', $propertyNumber)
            ->orderBy('id')
            ->first();
    }
}
