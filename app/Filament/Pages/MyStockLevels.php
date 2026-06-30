<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\InventoryStockService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use UnitEnum;

class MyStockLevels extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Stock levels';

    protected static ?string $title = 'Stock levels';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.my-stock-levels';

    #[Url]
    public string $sortBy = 'item_name';

    #[Url]
    public string $sortDir = 'asc';

    #[Url]
    public string $search = '';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->isEmployee();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Stock levels';
    }

    public function getHeading(): string|Htmlable
    {
        $dashboardUrl = route('filament.admin.pages.dashboard');

        return new HtmlString(sprintf(
            '<span class="owwa-wizard-title" role="list"><a class="owwa-wizard-step owwa-wizard-step-link" href="%s" role="listitem">Inventory</a><span class="owwa-wizard-separator" aria-hidden="true">&gt;</span><span class="owwa-wizard-step owwa-wizard-step-current" role="listitem">Stock levels</span></span>',
            e($dashboardUrl),
        ));
    }

    public function getSubheading(): string|Htmlable|null
    {
        return null;
    }

    /** @return array<int, string> */
    public function getPageClasses(): array
    {
        return ['owwa-inv-category-page', 'owwa-employee-stock-levels'];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function sortByColumn(string $column): void
    {
        $allowed = ['item_name', 'category_name', 'stock', 'reorder_level'];

        if (! in_array($column, $allowed, true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }
    }

    /** @return array{total: int, lowCount: int, okCount: int} */
    public function getStockSummary(): array
    {
        $rows = $this->getAllStockRows();
        $total = $rows->count();
        $lowCount = $rows->where('is_low', true)->count();

        return [
            'total' => $total,
            'lowCount' => $lowCount,
            'okCount' => $total - $lowCount,
        ];
    }

    /** @return Collection<int, object> */
    protected function getAllStockRows(): Collection
    {
        $user = Filament::auth()->user();
        if (! $user instanceof User || ! $user->office_id) {
            return collect();
        }

        $rows = app(InventoryStockService::class)->getStockLevelsList();

        $rows = $rows->where('office_id', (int) $user->office_id)->values();

        if (filled($this->search)) {
            $needle = mb_strtolower($this->search);
            $rows = $rows->filter(fn (object $row): bool => str_contains(mb_strtolower($row->item_name ?? ''), $needle)
                || str_contains(mb_strtolower($row->category_name ?? ''), $needle)
            )->values();
        }

        $dir = $this->sortDir === 'asc';

        return $rows->sortBy($this->sortBy, SORT_REGULAR, ! $dir)->values();
    }

    public function getStockRows(): LengthAwarePaginator
    {
        $rows = $this->getAllStockRows();
        $perPage = 10;
        $page = $this->getPage();

        return (new Paginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        ))->onEachSide(0);
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
