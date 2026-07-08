<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\EmployeeDistributionInventoryService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
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

    #[Url]
    public string $category = EmployeeDistributionInventoryService::CATEGORY_ALL;

    #[Url]
    public ?int $ledgerItem = null;

    public function mount(): void
    {
        if (! EmployeeDistributionInventoryService::isValidCategory($this->category)) {
            $this->category = EmployeeDistributionInventoryService::CATEGORY_ALL;
        }

        if ($this->ledgerItem !== null) {
            $this->openDistributionLedger($this->ledgerItem);
        }
    }

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

    public function setCategory(string $category): void
    {
        if (! EmployeeDistributionInventoryService::isValidCategory($category)) {
            return;
        }

        $this->category = $category;
        $this->resetPage();
    }

    public function sortByColumn(string $column): void
    {
        $allowed = ['item_name', 'category_name', 'quantity', 'distribution_date', 'distribution_count'];

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

    /** @return array{totalItems: int, totalQuantity: int, totalQuantityThisYear: int} */
    public function getInventorySummary(): array
    {
        $user = Filament::auth()->user();
        if (! $user instanceof User) {
            return ['totalItems' => 0, 'totalQuantity' => 0, 'totalQuantityThisYear' => 0];
        }

        return app(EmployeeDistributionInventoryService::class)->summaryFor($user, $this->category);
    }

    public function getInventoryRows(): LengthAwarePaginator
    {
        $user = Filament::auth()->user();
        if (! $user instanceof User) {
            return (new \Illuminate\Pagination\LengthAwarePaginator([], 0, 10, 1))->onEachSide(0);
        }

        return app(EmployeeDistributionInventoryService::class)->paginatedGroupedInventory(
            $user,
            filled($this->search) ? $this->search : null,
            $this->sortBy,
            $this->sortDir,
            10,
            $this->category,
        );
    }

    public function openDistributionLedger(int $itemId): void
    {
        $user = Filament::auth()->user();
        if (! $user instanceof User) {
            abort(403);
        }

        try {
            app(EmployeeDistributionInventoryService::class)->assertEmployeeOwnsItem($user, $itemId);
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->mountAction('viewDistributionLedger', [
            'itemId' => $itemId,
        ]);
    }

    public function viewDistributionLedgerAction(): Action
    {
        return Action::make('viewDistributionLedger')
            ->modalWidth(Width::FiveExtraLarge)
            ->extraModalWindowAttributes(['class' => 'owwa-view-record-modal'])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalHeading(function (): string {
                $ledger = $this->resolveMountedDistributionLedger();

                return $ledger['header']['item_name'].' — distribution history';
            })
            ->modalContent(fn (): HtmlString => new HtmlString(view(
                'filament.pages.partials.employee-distribution-ledger-modal',
                ['ledger' => $this->resolveMountedDistributionLedger()],
            )->render()));
    }

    /**
     * @return array{
     *     header: array<string, string|null>,
     *     columns: array<string, string>,
     *     rows: array<int, array<string, mixed>>
     * }
     */
    protected function resolveMountedDistributionLedger(): array
    {
        $user = Filament::auth()->user();
        $arguments = $this->getMountedAction()?->getArguments() ?? [];
        $itemId = (int) ($arguments['itemId'] ?? 0);

        if (! $user instanceof User) {
            abort(403);
        }

        return app(EmployeeDistributionInventoryService::class)->presentLedger($user, $itemId);
    }
}
