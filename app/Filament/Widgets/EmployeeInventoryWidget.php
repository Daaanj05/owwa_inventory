<?php

namespace App\Filament\Widgets;

use App\Models\Distribution;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EmployeeInventoryWidget extends Widget
{
    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.employee-inventory-widget';

    public string $invSort = 'distribution_date';

    public string $invDir = 'desc';

    public string $invSearch = '';

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->isEmployee();
    }

    public function sortInventory(string $column): void
    {
        $allowed = ['item_name', 'category_name', 'quantity', 'distribution_date', 'distributed_by_name'];

        if (! in_array($column, $allowed, true)) {
            return;
        }

        if ($this->invSort === $column) {
            $this->invDir = $this->invDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->invSort = $column;
            $this->invDir = 'asc';
        }
    }

    public function getInventoryRows(): LengthAwarePaginator
    {
        $user = Filament::auth()->user();
        if (! $user) {
            return (new \Illuminate\Pagination\LengthAwarePaginator([], 0, 10, 1))->onEachSide(0);
        }

        /** @var \Illuminate\Database\Eloquent\Builder<Distribution> $query */
        $query = Distribution::query()
            ->select('distributions.*')
            ->join('items', 'items.id', '=', 'distributions.item_id')
            ->join('item_categories', 'item_categories.id', '=', 'items.item_category_id')
            ->join('users as distributor', 'distributor.id', '=', 'distributions.distributed_by')
            ->where('distributions.distributed_to', $user->id)
            ->addSelect([
                'items.name as item_name',
                'item_categories.name as category_name',
                'distributor.name as distributed_by_name',
            ]);

        if (filled($this->invSearch)) {
            $term = '%'.$this->invSearch.'%';
            $query->where(function ($q) use ($term): void {
                $q->where('items.name', 'like', $term)
                    ->orWhere('item_categories.name', 'like', $term)
                    ->orWhere('distributor.name', 'like', $term);
            });
        }

        $sortColumn = match ($this->invSort) {
            'item_name' => 'items.name',
            'category_name' => 'item_categories.name',
            'quantity' => 'distributions.quantity',
            'distributed_by_name' => 'distributor.name',
            default => 'distributions.distribution_date',
        };

        return $query->orderBy($sortColumn, $this->invDir)->paginate(10)->onEachSide(0);
    }
}
