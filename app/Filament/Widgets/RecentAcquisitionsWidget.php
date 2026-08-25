<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Models\AcquisitionPaperwork;
use App\Models\ItemCategory;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class RecentAcquisitionsWidget extends Widget
{
    protected static ?int $sort = 4;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.recent-acquisitions-widget';

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user?->isSupplyCustodian() ?? false;
    }

    /**
     * @return Collection<int, AcquisitionPaperwork>
     */
    public function getRecentRows(): Collection
    {
        return AcquisitionPaperwork::query()
            ->whereNotNull('received_at')
            ->with(['office', 'acquisitions'])
            ->orderByDesc('received_at')
            ->limit(5)
            ->get();
    }

    public function getAcquisitionsUrl(): string
    {
        $categoryId = SyncsActiveItemCategory::resolveCategoryIdFromContext();

        if ($categoryId <= 0) {
            $categoryId = (int) ItemCategory::query()->orderBy('name')->value('id');
        }

        return AcquisitionResource::getUrl('index', array_filter([
            'category' => $categoryId > 0 ? $categoryId : null,
        ]));
    }

    public function getTotalAmount(AcquisitionPaperwork $paperwork): float
    {
        return (float) $paperwork->acquisitions->sum(
            fn ($acquisition): float => (float) $acquisition->quantity * (float) $acquisition->unit_cost,
        );
    }
}
