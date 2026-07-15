<?php

namespace App\Filament\Pages;

use App\Filament\Resources\PropertyActionRequests\PropertyActionRequestResource;
use App\Models\Issuance;
use App\Models\PropertyActionRequest;
use App\Models\User;
use App\Services\EmployeeDistributionInventoryService;
use App\Support\SemiExpendableUsefulLife;
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

    protected static string|UnitEnum|null $navigationGroup = 'My items';

    protected static ?string $navigationLabel = 'My Inventory';

    protected static ?string $title = 'My Inventory';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.my-inventory';

    #[Url]
    public string $sortBy = 'distribution_date';

    #[Url]
    public string $sortDir = 'desc';

    #[Url]
    public string $search = '';

    #[Url]
    public string $category = EmployeeDistributionInventoryService::CATEGORY_CONSUMABLES;

    #[Url]
    public string $custodyTab = EmployeeDistributionInventoryService::CUSTODY_TAB_ON_HAND;

    #[Url]
    public ?int $ledgerItem = null;

    public int $ledgerPage = 1;

    public function mount(): void
    {
        if (! EmployeeDistributionInventoryService::isValidCategory($this->category)) {
            $this->category = EmployeeDistributionInventoryService::CATEGORY_CONSUMABLES;
        }

        if ($this->ledgerItem !== null) {
            $this->openDistributionLedger($this->ledgerItem);
        }
    }

    public function updatedCategory(): void
    {
        if (! EmployeeDistributionInventoryService::isValidCategory($this->category)) {
            $this->category = EmployeeDistributionInventoryService::CATEGORY_CONSUMABLES;
        }

        $this->resetPage();
    }

    public function updatedCustodyTab(): void
    {
        if (! EmployeeDistributionInventoryService::isValidCustodyTab($this->custodyTab)) {
            $this->custodyTab = EmployeeDistributionInventoryService::CUSTODY_TAB_ON_HAND;
        }

        $this->resetPage();
    }

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->isEmployee();
    }

    public function getTitle(): string|Htmlable
    {
        return 'My Inventory';
    }

    public function getHeading(): string|Htmlable
    {
        return 'My Inventory';
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
        $allowed = $this->usesPropertyIssuanceView()
            ? ['item_name', 'quantity', 'distribution_date', 'distribution_count', 'last_distribution_date']
            : ['item_name', 'quantity', 'distribution_date', 'distribution_count'];

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

        return app(EmployeeDistributionInventoryService::class)->summaryFor($user, $this->category, $this->custodyTab);
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
            $this->custodyTab,
        );
    }

    public function usesPropertyIssuanceView(): bool
    {
        return EmployeeDistributionInventoryService::usesPropertyIssuanceView($this->category);
    }

    public function propertyActionUrl(Issuance $issuance, string $actionType): string
    {
        return PropertyActionRequestResource::createUrlForIssuance($issuance->id, $actionType);
    }

    public function suggestedPropertyActionType(Issuance $issuance): string
    {
        $slug = $issuance->item?->category?->getTemplateSlug();

        if ($slug === 'semi_expendable') {
            $status = SemiExpendableUsefulLife::statusForIssuance($issuance);

            if (in_array($status, [SemiExpendableUsefulLife::STATUS_NEARING, SemiExpendableUsefulLife::STATUS_EXPIRED], true)) {
                return PropertyActionRequest::ACTION_REPLACEMENT;
            }
        }

        return PropertyActionRequest::ACTION_DISPOSAL;
    }

    public function showPropertyActionCta(Issuance $issuance): bool
    {
        if ($this->custodyTab !== EmployeeDistributionInventoryService::CUSTODY_TAB_ON_HAND) {
            return false;
        }

        $slug = $issuance->item?->category?->getTemplateSlug();

        if ($slug !== 'semi_expendable') {
            return false;
        }

        $status = SemiExpendableUsefulLife::statusForIssuance($issuance);

        return in_array($status, [SemiExpendableUsefulLife::STATUS_NEARING, SemiExpendableUsefulLife::STATUS_EXPIRED], true);
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

        $this->ledgerPage = 1;

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

                return $ledger['header']['item_name'].' — Distribution History';
            })
            ->modalContent(fn (): HtmlString => new HtmlString(view(
                'filament.pages.partials.employee-distribution-ledger-modal',
                ['ledger' => $this->resolveMountedDistributionLedger()],
            )->render()));
    }

    public function openPropertyIssuanceLedger(int $itemId): void
    {
        $user = Filament::auth()->user();
        if (! $user instanceof User) {
            abort(403);
        }

        try {
            app(EmployeeDistributionInventoryService::class)->assertEmployeeOwnsPropertyItem(
                $user,
                $itemId,
                $this->custodyTab,
            );
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->ledgerPage = 1;

        $this->mountAction('viewDistributionLedger', [
            'itemId' => $itemId,
        ]);
    }

    public function openPropertyItemLedger(int $itemId): void
    {
        $this->openPropertyIssuanceLedger($itemId);
    }

    /**
     * @return array{
     *     header: array<string, string|null>,
     *     columns: array<string, array{label: string, tooltip?: string}|string>,
     *     rows: array<int, array<string, mixed>>,
     *     paginator: \Illuminate\Contracts\Pagination\LengthAwarePaginator
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

        if ($this->usesPropertyIssuanceView()) {
            return app(EmployeeDistributionInventoryService::class)->presentPropertyIssuanceLedgerPaginated(
                $user,
                $itemId,
                max(1, $this->ledgerPage),
                10,
                $this->custodyTab,
            );
        }

        return app(EmployeeDistributionInventoryService::class)->presentLedgerPaginated(
            $user,
            $itemId,
            max(1, $this->ledgerPage),
        );
    }
}
