<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\InventoryUnitPublicLookupService;
use App\Services\PhysicalCountScanService;
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

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Scan asset';

    protected static ?int $navigationSort = 45;

    protected static ?string $title = 'Scan asset';

    protected string $view = 'filament.pages.scan-asset';

    public string $manualCode = '';

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
        return 'Look up PPE and semi-expendable property tags.';
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
        $propertyNumber = app(PhysicalCountScanService::class)->normalizePropertyNumber($code);

        if (blank($propertyNumber)) {
            Notification::make()
                ->title('Invalid scan')
                ->body('Could not read a property number from that code.')
                ->danger()
                ->send();

            return;
        }

        $asset = app(InventoryUnitPublicLookupService::class)->findByPropertyNumber($propertyNumber);

        if ($asset === null) {
            Notification::make()
                ->title('Asset not found')
                ->body("No record found for {$propertyNumber}.")
                ->danger()
                ->send();

            return;
        }

        $this->redirect(
            route('inventory.assets.show', ['propertyNumber' => $propertyNumber]),
            navigate: false,
        );
    }

    public function submitManualCode(): void
    {
        if (blank($this->manualCode)) {
            return;
        }

        $this->resolveScan($this->manualCode);
        $this->manualCode = '';
    }
}
