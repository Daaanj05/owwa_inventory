<?php

namespace App\Filament\Widgets;

use App\Models\Acquisition;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TopAcquiredProductsWidget extends Widget
{
    protected static ?int $sort = 5;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.top-acquired-products-widget';

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user?->isSupplyCustodian() ?? false;
    }

    /**
     * Rank by received stock (custodian receipt / IAR), using acquisition unit costs —
     * not PR paperwork lines, which stay at ₱0 until costs are entered on the PO.
     *
     * @return Collection<int, object{
     *     item_id: int,
     *     item_code: ?string,
     *     item_name: string,
     *     category_name: string,
     *     total_quantity: int,
     *     avg_unit_cost: float,
     *     total_amount: float
     * }>
     */
    public function getTopProductRows(): Collection
    {
        return Acquisition::query()
            ->join('items', 'acquisitions.item_id', '=', 'items.id')
            ->join('item_categories', 'items.item_category_id', '=', 'item_categories.id')
            ->whereNull('items.archived_at')
            ->groupBy(
                'acquisitions.item_id',
                'items.item_code',
                'items.name',
                'item_categories.name',
            )
            ->select([
                'acquisitions.item_id',
                'items.item_code',
                'items.name as item_name',
                'item_categories.name as category_name',
                DB::raw('SUM(acquisitions.quantity) as total_quantity'),
                DB::raw('CASE WHEN SUM(acquisitions.quantity) > 0 THEN SUM(acquisitions.quantity * acquisitions.unit_cost) / SUM(acquisitions.quantity) ELSE 0 END as avg_unit_cost'),
                DB::raw('SUM(acquisitions.quantity * acquisitions.unit_cost) as total_amount'),
            ])
            ->orderByDesc('total_quantity')
            ->limit(5)
            ->get()
            ->map(fn ($row): object => (object) [
                'item_id' => (int) $row->item_id,
                'item_code' => $row->item_code,
                'item_name' => (string) $row->item_name,
                'category_name' => (string) $row->category_name,
                'total_quantity' => (int) $row->total_quantity,
                'avg_unit_cost' => (float) $row->avg_unit_cost,
                'total_amount' => (float) $row->total_amount,
            ]);
    }
}
