<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\MyInventory;
use App\Models\User;
use App\Services\EmployeeDistributionInventoryService;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;

class EmployeeInventoryWidget extends Widget
{
    use WithPagination;

    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.employee-inventory-widget';

    public string $invSort = 'distribution_date';

    public string $invDir = 'desc';

    public string $invSearch = '';

    public string $invCategory = EmployeeDistributionInventoryService::CATEGORY_CONSUMABLES;

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->isEmployee();
    }

    public function sortInventory(string $column): void
    {
        $allowed = ['item_name', 'category_name', 'quantity', 'distribution_date', 'distribution_count'];

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

    public function updatedInvCategory(): void
    {
        if (! EmployeeDistributionInventoryService::isValidCategory($this->invCategory)) {
            $this->invCategory = EmployeeDistributionInventoryService::CATEGORY_CONSUMABLES;
        }

        $this->resetPage();
    }

    public function updatedInvSearch(): void
    {
        $this->resetPage();
    }

    public function getInventoryRows(): LengthAwarePaginator
    {
        $user = Filament::auth()->user();
        if (! $user instanceof User) {
            return (new \Illuminate\Pagination\LengthAwarePaginator([], 0, 10, 1))->onEachSide(0);
        }

        return app(EmployeeDistributionInventoryService::class)->paginatedGroupedInventory(
            $user,
            filled($this->invSearch) ? $this->invSearch : null,
            $this->invSort,
            $this->invDir,
            10,
            $this->invCategory,
        );
    }

    public function ledgerUrl(int $itemId): string
    {
        return MyInventory::getUrl([
            'ledgerItem' => $itemId,
            'category' => $this->invCategory,
        ]);
    }
}
