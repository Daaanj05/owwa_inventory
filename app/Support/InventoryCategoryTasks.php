<?php

namespace App\Support;

use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Pages\StockLevels;
use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Filament\Resources\Disposals\DisposalResource;
use App\Filament\Resources\Issuances\IssuanceResource;
use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\PhysicalCountSessions\PhysicalCountSessionResource;
use App\Filament\Resources\PhysicalInventoryPlans\PhysicalInventoryPlanResource;
use App\Filament\Resources\Transfers\TransferResource;
use App\Models\ItemCategory;
use Filament\Support\Icons\Heroicon;

/**
 * Sidebar tasks for one inventory category folder.
 */
final class InventoryCategoryTasks
{
    use SyncsActiveItemCategory;

    public const SUPPLY_LINKS_GROUP = 'Supply links';

    /**
     * @return array<int, array{title: string, icon: Heroicon, url: string, route: string, sort: int}>
     */
    public static function forCategory(ItemCategory $category): array
    {
        $categoryId = $category->id;
        $tasks = [
            self::task(
                'Stock levels',
                Heroicon::OutlinedSquares2x2,
                StockLevels::getUrl(['category' => $categoryId]),
                StockLevels::getRouteName(),
                1,
            ),
            self::task(
                'Items',
                Heroicon::OutlinedCube,
                self::urlWithActiveItemCategory(ItemResource::getUrl('index'), $categoryId),
                ItemResource::getRouteBaseName().'.*',
                2,
            ),
            self::task(
                'Acquisitions',
                Heroicon::OutlinedArrowDownTray,
                self::urlWithActiveItemCategory(AcquisitionResource::getUrl('index'), $categoryId),
                AcquisitionResource::getRouteBaseName().'.*',
                3,
            ),
            self::task(
                'Issuances',
                Heroicon::OutlinedArrowUpTray,
                self::urlWithActiveItemCategory(IssuanceResource::getUrl('index'), $categoryId),
                IssuanceResource::getRouteBaseName().'.*',
                4,
            ),
            self::task(
                'Disposals',
                Heroicon::OutlinedTrash,
                self::urlWithActiveItemCategory(DisposalResource::getUrl('index'), $categoryId),
                DisposalResource::getRouteBaseName().'.*',
                5,
            ),
        ];

        if ($category->getTemplateSlug() !== 'consumables') {
            $tasks[] = self::task(
                'Transfers',
                Heroicon::OutlinedArrowsRightLeft,
                self::urlWithActiveItemCategory(TransferResource::getUrl('index'), $categoryId),
                TransferResource::getRouteBaseName().'.*',
                6,
            );
        }

        $tasks[] = self::task(
            'Physical counts',
            Heroicon::OutlinedClipboardDocumentCheck,
            self::urlWithActiveItemCategory(PhysicalCountSessionResource::getUrl('index'), $categoryId),
            PhysicalCountSessionResource::getRouteBaseName().'.*',
            7,
        );
        $tasks[] = self::task(
            'Inventory Schedule',
            Heroicon::OutlinedCalendarDays,
            self::urlWithActiveItemCategory(PhysicalInventoryPlanResource::getUrl('index'), $categoryId),
            PhysicalInventoryPlanResource::getRouteBaseName().'.*',
            8,
        );

        return $tasks;
    }

    /**
     * @return array{title: string, icon: Heroicon, url: string, route: string, sort: int}
     */
    private static function task(string $title, Heroicon $icon, string $url, string $route, int $sort): array
    {
        return [
            'title' => $title,
            'icon' => $icon,
            'url' => $url,
            'route' => $route,
            'sort' => $sort,
        ];
    }
}
