<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Services\InventoryStockService;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class EmployeeStockLevelsWidget extends Widget
{
    protected static ?int $sort = 3;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.employee-stock-levels-widget';

    public string $stockSort = 'item_name';

    public string $stockDir = 'asc';

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->isEmployee();
    }

    public function sortStock(string $column): void
    {
        $allowed = ['item_name', 'category_name', 'stock', 'reorder_level'];

        if (! in_array($column, $allowed, true)) {
            return;
        }

        if ($this->stockSort === $column) {
            $this->stockDir = $this->stockDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->stockSort = $column;
            $this->stockDir = 'asc';
        }
    }

    /** @return Collection<int, object> */
    public function getStockRows(): Collection
    {
        $user = Filament::auth()->user();
        if (! $user || ! $user->office_id) {
            return collect();
        }

        $rows = app(InventoryStockService::class)->getStockLevelsList();

        $rows = $rows->where('office_id', (int) $user->office_id)->values();

        $dir = $this->stockDir === 'asc';

        return $rows->sortBy($this->stockSort, SORT_REGULAR, ! $dir)->values();
    }

    public function getOfficeName(): string
    {
        $user = Filament::auth()->user();
        if ($user instanceof User && $user->office_id) {
            return $user->office?->name ?? 'Your office';
        }

        return 'Your office';
    }
}
