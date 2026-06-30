<?php

namespace App\Filament\Pages;

use App\Models\Distribution;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use UnitEnum;

class MyInventory extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'My inventory';

    protected static ?string $title = 'My inventory';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.my-inventory';

    #[Url]
    public string $sortBy = 'distribution_date';

    #[Url]
    public string $sortDir = 'desc';

    #[Url]
    public string $search = '';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->isEmployee();
    }

    public function getTitle(): string|Htmlable
    {
        return 'My inventory';
    }

    public function getHeading(): string|Htmlable
    {
        $dashboardUrl = route('filament.admin.pages.dashboard');

        return new HtmlString(sprintf(
            '<span class="owwa-wizard-title" role="list"><a class="owwa-wizard-step owwa-wizard-step-link" href="%s" role="listitem">Inventory</a><span class="owwa-wizard-separator" aria-hidden="true">&gt;</span><span class="owwa-wizard-step owwa-wizard-step-current" role="listitem">My inventory</span></span>',
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
        return ['owwa-inv-category-page', 'owwa-employee-my-inventory'];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function sortByColumn(string $column): void
    {
        $allowed = ['item_name', 'category_name', 'quantity', 'distribution_date', 'distributed_by_name'];

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

    /** @return array{totalItems: int, totalQuantity: int} */
    public function getInventorySummary(): array
    {
        $user = Filament::auth()->user();
        if (! $user) {
            return ['totalItems' => 0, 'totalQuantity' => 0];
        }

        $query = Distribution::query()
            ->where('distributed_to', $user->id);

        return [
            'totalItems' => (int) $query->distinct('item_id')->count('item_id'),
            'totalQuantity' => (int) $query->sum('quantity'),
        ];
    }

    public function getInventoryRows(): LengthAwarePaginator
    {
        $user = Filament::auth()->user();
        if (! $user) {
            return (new \Illuminate\Pagination\LengthAwarePaginator([], 0, 10, 1))->onEachSide(0);
        }

        /** @var \Illuminate\Database\Eloquent\Builder<Distribution> $query */
        $query = Distribution::query()
            ->select([
                'distributions.*',
            ])
            ->join('items', 'items.id', '=', 'distributions.item_id')
            ->join('item_categories', 'item_categories.id', '=', 'items.item_category_id')
            ->join('users as distributor', 'distributor.id', '=', 'distributions.distributed_by')
            ->where('distributions.distributed_to', $user->id)
            ->addSelect([
                'items.name as item_name',
                'item_categories.name as category_name',
                'distributor.name as distributed_by_name',
            ]);

        if (filled($this->search)) {
            $search = '%'.$this->search.'%';
            $query->where(function ($q) use ($search): void {
                $q->where('items.name', 'like', $search)
                    ->orWhere('item_categories.name', 'like', $search)
                    ->orWhere('distributor.name', 'like', $search);
            });
        }

        $sortColumn = match ($this->sortBy) {
            'item_name' => 'items.name',
            'category_name' => 'item_categories.name',
            'quantity' => 'distributions.quantity',
            'distributed_by_name' => 'distributor.name',
            default => 'distributions.distribution_date',
        };

        $query->orderBy($sortColumn, $this->sortDir);

        return $query->paginate(10)->withQueryString()->onEachSide(0);
    }
}
