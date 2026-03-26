<?php

namespace App\Filament\Pages;

use App\Models\ItemCategory;
use App\Services\InventoryStockService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use UnitEnum;

class StockLevels extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Stock levels';

    protected static ?string $title = 'Stock levels';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.stock-levels';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user && ! $user->isSystemAdmin();
    }

    #[Url]
    public string $sortBy = 'item_name';

    #[Url]
    public string $sortDir = 'asc';

    #[Url]
    public string $categoryFilter = '';

    public function getTitle(): string|Htmlable
    {
        return 'Stock levels';
    }

    public static function getNavigationLabel(): string
    {
        return 'Stock levels';
    }

    public function getSubheading(): ?string
    {
        return 'View quantity on hand and low-stock alerts.';
    }

    public function sortByColumn(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }
    }

    /** @return array<int, array{id: int, name: string}> */
    public function getCategoryOptions(): array
    {
        return ItemCategory::query()->orderBy('name')->pluck('name', 'id')->toArray();
    }

    /** @return array{total: int, lowCount: int, okCount: int} */
    public function getStockLevelsSummary(): array
    {
        $rows = $this->getStockLevelsFull();
        $total = $rows->count();
        $lowCount = $rows->where('is_low', true)->count();

        return [
            'total' => $total,
            'lowCount' => $lowCount,
            'okCount' => $total - $lowCount,
        ];
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    public function getStockLevelsFull(): \Illuminate\Support\Collection
    {
        $rows = app(InventoryStockService::class)->getStockLevelsList();
        $user = Filament::auth()->user();
        if ($user && ! $user->isSupplyCustodian() && $user->office_id) {
            $rows = $rows->where('office_id', (int) $user->office_id)->values();
        }

        if ($this->categoryFilter !== '') {
            $rows = $rows->where('category_name', $this->categoryFilter)->values();
        }

        return $rows;
    }

    public function getStockLevels(): LengthAwarePaginator
    {
        $rows = $this->getStockLevelsFull();

        $sortBy = $this->sortBy;
        $sortDir = $this->sortDir;
        $rows = $rows->sortBy($sortBy, SORT_REGULAR, $sortDir === 'desc')->values();

        $page = (int) request()->get('page', 1);
        $perPage = (int) request()->get('per_page', 15);
        $perPage = min(max($perPage, 10), 50);

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }
}
