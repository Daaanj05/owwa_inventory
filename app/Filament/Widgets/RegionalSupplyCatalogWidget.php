<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\RegionalSupplyCatalog;
use App\Models\User;
use App\Services\InventoryStockService;
use App\Support\SupplyOfficeResolver;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class RegionalSupplyCatalogWidget extends Widget
{
    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.regional-supply-catalog-widget';

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && ($user->isUnitConsolidator() || $user->isEmployee());
    }

    /** @return Collection<int, object> */
    public function getPreviewRows(): Collection
    {
        $supplyOfficeId = app(SupplyOfficeResolver::class)->resolve();

        if ($supplyOfficeId === null) {
            return collect();
        }

        $categoryId = session('active_item_category_id');

        return app(InventoryStockService::class)
            ->getStockLevelsList(filled($categoryId) ? (int) $categoryId : null)
            ->where('office_id', $supplyOfficeId)
            ->sortByDesc('stock')
            ->take(5)
            ->values();
    }

    public function getSupplyOfficeName(): string
    {
        return app(SupplyOfficeResolver::class)->resolveOffice()?->name ?? 'Regional supply office';
    }

    public function getCatalogUrl(): string
    {
        $categoryId = session('active_item_category_id');

        return RegionalSupplyCatalog::getUrl(array_filter([
            'category' => filled($categoryId) ? (int) $categoryId : null,
        ]));
    }
}
