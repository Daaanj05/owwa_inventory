<?php

namespace App\Filament\Pages;

use App\Models\InventoryUnit;
use App\Models\User;
use App\Services\PhysicalCountScanService;
use App\Support\InventoryCategoryTasks;
use App\Support\InventoryUnitQrPayload;
use App\Support\ScanAssetHandoff;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;
use UnitEnum;

class ScanAsset extends Page
{
    protected static bool $shouldRegisterNavigation = true;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static string|UnitEnum|null $navigationGroup = InventoryCategoryTasks::SUPPLY_LINKS_GROUP;

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

    public bool $resolvedIsClaimed = false;

    /**
     * @var array{description?: string, unit_cost?: string, source?: string, holder?: string}
     */
    public array $resolvedDetails = [];

    /**
     * Typed Inventory Item No. matched several (legacy duplicate) units; the SC must pick one.
     *
     * @var array<int, string>
     */
    public array $duplicateUnitOptions = [];

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
        return 'Look up PPE and semi-expendable property tags, then open Disposal, Incident report, or Transfer, or reprint a worn QR label.';
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

        $units = $this->findUnitsFromScan($code);

        if ($units->isEmpty()) {
            Notification::make()
                ->title('Asset not found')
                ->body('No PPE or semi-expendable inventory unit matched that code.')
                ->danger()
                ->send();

            return;
        }

        if ($units->count() > 1) {
            $this->duplicateUnitOptions = $units
                ->mapWithKeys(fn (InventoryUnit $unit): array => [
                    $unit->id => implode(' · ', array_filter([
                        $unit->property_number,
                        $unit->article ?? $unit->item?->name,
                        $unit->office?->name,
                        str_replace('_', ' ', (string) $unit->status),
                    ])),
                ])
                ->all();

            return;
        }

        $this->showUnit($units->first());
    }

    public function selectDuplicateUnit(int $unitId): void
    {
        if (! array_key_exists($unitId, $this->duplicateUnitOptions)) {
            return;
        }

        $unit = InventoryUnit::query()->with(['item.category', 'office', 'issuance', 'acquisition'])->find($unitId);
        $this->clearResolvedAsset();

        if ($unit !== null) {
            $this->showUnit($unit);
        }
    }

    protected function showUnit(InventoryUnit $unit): void
    {
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

        $unit = $resolved['unit'];

        $this->resolvedUnitId = $unit->id;
        $this->resolvedPropertyNumber = (string) $unit->property_number;
        $this->resolvedItemName = (string) ($unit->article ?? $unit->item?->name ?? '—');
        $this->resolvedOfficeName = (string) ($unit->office?->name ?? '—');
        $this->resolvedStatus = str_replace('_', ' ', (string) $unit->status);
        $this->resolvedCategorySlug = $resolved['categorySlug'];
        $this->resolvedCategoryId = $resolved['categoryId'];
        $this->resolvedPublicUrl = route('inventory.assets.unit.show', ['inventoryUnit' => $unit->id]);
        $this->resolvedIsClaimed = ScanAssetHandoff::isUnitClaimed($unit);
        $this->resolvedDetails = $this->unitDetails($unit);
    }

    /**
     * @return array{description: string, unit_cost: string, source: string, holder: string}
     */
    protected function unitDetails(InventoryUnit $unit): array
    {
        $unit->loadMissing([
            'acquisition.inspectionAcceptanceReport',
            'stockOpeningBalance.batch',
            'issuance.issuedTo',
        ]);

        $cost = $unit->unit_cost ?? $unit->acquisition?->unit_cost;

        $source = match (true) {
            $unit->acquisition !== null => trim(sprintf(
                'IAR %s · %s',
                $unit->acquisition->inspectionAcceptanceReport?->number ?? $unit->acquisition->reference_code,
                $unit->acquisition->acquisition_date?->format('M j, Y') ?? '',
            ), ' ·'),
            $unit->stockOpeningBalance !== null => trim(sprintf(
                'Opening balance %s · %s',
                $unit->stockOpeningBalance->batch?->reference_code ?? '',
                $unit->stockOpeningBalance->recorded_at?->format('M j, Y') ?? '',
            ), ' ·'),
            default => '—',
        };

        $holder = match ($unit->status) {
            InventoryUnit::STATUS_ISSUED => trim(sprintf(
                '%s · %s',
                $unit->issuance?->issuedTo?->name ?? '—',
                $unit->issuance?->controlNumber() ?? '',
            ), ' ·'),
            InventoryUnit::STATUS_UNDER_REPAIR => 'Supply office (under repair)',
            default => 'Supply office stock',
        };

        return [
            'description' => (string) ($unit->description ?: ($unit->item?->description ?? '—')),
            'unit_cost' => $cost !== null ? '₱'.number_format((float) $cost, 2) : '—',
            'source' => $source,
            'holder' => $holder,
        ];
    }

    public function reprintQrLabelAction(): Action
    {
        return Action::make('reprintQrLabel')
            ->label('Reprint QR label')
            ->icon('heroicon-o-printer')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(fn (): string => 'Reprint label for '.$this->resolvedPropertyNumber.'?')
            ->modalDescription('Check that the details on the card match the physical item before attaching the new tag.')
            ->modalSubmitActionLabel('Reprint')
            ->visible(fn (): bool => $this->resolvedUnitId !== null)
            ->action(function (): void {
                $unit = $this->resolvedUnitId
                    ? InventoryUnit::query()->with(['item.category', 'office'])->find($this->resolvedUnitId)
                    : null;

                if (ScanAssetHandoff::resolveActionableUnit($unit) === null) {
                    ScanAssetHandoff::notifyBlocked('Scan expired', 'Scan the property tag again to continue.');
                    $this->clearResolvedAsset();

                    return;
                }

                $this->redirect(route('owwa.qr-labels.unit', ['inventoryUnit' => $unit->id]), navigate: false);
            });
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
        $this->resolvedIsClaimed = false;
        $this->resolvedDetails = [];
        $this->duplicateUnitOptions = [];
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

    /**
     * Exact matches only: the scanned unit ID, or every unit carrying the typed Inventory Item No.
     *
     * @return Collection<int, InventoryUnit>
     */
    protected function findUnitsFromScan(string $code): Collection
    {
        $payload = InventoryUnitQrPayload::resolve($code);

        if ($payload?->inventoryUnitId !== null) {
            $unit = InventoryUnit::query()
                ->with(['item.category', 'office', 'issuance', 'acquisition'])
                ->find($payload->inventoryUnitId);

            if ($unit !== null) {
                return new Collection([$unit]);
            }
        }

        $propertyNumber = app(PhysicalCountScanService::class)->normalizePropertyNumber($code);

        if (blank($propertyNumber)) {
            return new Collection;
        }

        return InventoryUnit::query()
            ->with(['item.category', 'office', 'issuance', 'acquisition'])
            ->where('property_number', $propertyNumber)
            ->orderBy('id')
            ->get();
    }
}
