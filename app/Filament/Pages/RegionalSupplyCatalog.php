<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Requisitions\RequisitionResource;
use App\Models\ItemCategory;
use App\Models\User;
use App\Services\InventoryStockService;
use App\Support\SupplyOfficeResolver;
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

class RegionalSupplyCatalog extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Requisitions';

    protected static ?string $navigationLabel = 'Regional supply catalog';

    protected static ?string $title = 'Regional supply catalog';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.regional-supply-catalog';

    #[Url]
    public string $sortBy = 'item_name';

    #[Url]
    public string $sortDir = 'asc';

    #[Url]
    public string $search = '';

    #[Url]
    public int|string|null $category = null;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && ($user->isUnitConsolidator() || $user->isEmployee());
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        if (blank($this->category)) {
            $this->category = session('active_item_category_id');
        }
    }

    public function getTitle(): string|Htmlable
    {
        return 'Regional supply catalog';
    }

    public function getHeading(): string|Htmlable
    {
        $dashboardUrl = route('filament.admin.pages.dashboard');

        return new HtmlString(sprintf(
            '<span class="owwa-wizard-title" role="list"><a class="owwa-wizard-step owwa-wizard-step-link" href="%s" role="listitem">Requisitions</a><span class="owwa-wizard-separator" aria-hidden="true">&gt;</span><span class="owwa-wizard-step owwa-wizard-step-current" role="listitem">Regional supply catalog</span></span>',
            e($dashboardUrl),
        ));
    }

    public function getSubheading(): string|Htmlable|null
    {
        $officeName = app(SupplyOfficeResolver::class)->resolveOffice()?->name;

        if ($officeName === null) {
            return 'Stock available from the regional supply office for items you can request.';
        }

        return "Stock at {$officeName} — use this list when planning requests to the Supply Custodian.";
    }

    /** @return array<int, string> */
    public function getPageClasses(): array
    {
        return ['owwa-inv-category-page', 'owwa-regional-supply-catalog'];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCategory(): void
    {
        $this->resetPage();

        if (filled($this->category)) {
            session()->put('active_item_category_id', (int) $this->category);
        }
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

    /** @return array<int, string> */
    public function getCategoryOptions(): array
    {
        return ItemCategory::query()
            ->whereNull('archived_at')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function getSupplyOfficeName(): string
    {
        return app(SupplyOfficeResolver::class)->resolveOffice()?->name ?? 'Regional supply office';
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

    public function requestItemUrl(int $itemId): string
    {
        return RequisitionResource::getUrl('index', array_filter([
            'create' => 1,
            'item_id' => $itemId,
            'category' => filled($this->category) ? (int) $this->category : null,
        ]));
    }

    /** @return Collection<int, object> */
    protected function getAllStockRows(): Collection
    {
        $supplyOfficeId = app(SupplyOfficeResolver::class)->resolve();

        if ($supplyOfficeId === null) {
            return collect();
        }

        $categoryId = filled($this->category) ? (int) $this->category : null;

        $rows = app(InventoryStockService::class)->getStockLevelsList($categoryId)
            ->where('office_id', $supplyOfficeId)
            ->values();

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
}
