<?php

namespace App\Filament\Widgets;

use App\Models\AcquisitionPaperworkLine;
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
        return AcquisitionPaperworkLine::query()
            ->join('acquisition_paperwork', 'acquisition_paperwork_lines.acquisition_paperwork_id', '=', 'acquisition_paperwork.id')
            ->join('items', 'acquisition_paperwork_lines.item_id', '=', 'items.id')
            ->join('item_categories', 'items.item_category_id', '=', 'item_categories.id')
            ->whereNotNull('acquisition_paperwork.received_at')
            ->whereNull('items.archived_at')
            ->groupBy(
                'acquisition_paperwork_lines.item_id',
                'items.item_code',
                'items.name',
                'item_categories.name',
            )
            ->select([
                'acquisition_paperwork_lines.item_id',
                'items.item_code',
                'items.name as item_name',
                'item_categories.name as category_name',
                DB::raw('SUM(acquisition_paperwork_lines.quantity) as total_quantity'),
                DB::raw('AVG(acquisition_paperwork_lines.unit_cost) as avg_unit_cost'),
                DB::raw('SUM(acquisition_paperwork_lines.amount) as total_amount'),
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
