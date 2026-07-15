<?php

namespace App\Filament\Pages;

use App\Filament\Resources\PropertyActionRequests\PropertyActionRequestResource;
use App\Models\PropertyActionRequest;
use App\Models\User;
use App\Services\OfficePropertyRegisterService;
use App\Support\InventoryCategoryOptions;
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

class OfficePropertyRegister extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Office';

    protected static ?string $navigationLabel = 'Office Property Registry';

    protected static ?string $title = 'Office Property Registry';

    protected static ?int $navigationSort = 11;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.office-property-register';

    #[Url]
    public int|string|null $category = null;

    #[Url]
    public string $sortBy = 'item_name';

    #[Url]
    public string $sortDir = 'asc';

    #[Url]
    public string $search = '';

    public int $ledgerPage = 1;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->isUnitConsolidator();
    }

    public function mount(): void
    {
        if (! filled($this->category)) {
            $this->category = InventoryCategoryOptions::defaultConsumablesCategoryId();
        }
    }

    public function getTitle(): string|Htmlable
    {
        return 'Office Property Registry';
    }

    public function getHeading(): string|Htmlable
    {
        return 'Office Property Registry';
    }

    public function getSubheading(): ?string
    {
        return 'Received = from Supply Custodian into your office. Distributed = to employees. Balance = still in office custody.';
    }

    /** @return array<int, string> */
    public function getCategoryOptions(): array
    {
        return InventoryCategoryOptions::allActiveCategoryOptions();
    }

    /** @return array<int, string> */
    public function getPageClasses(): array
    {
        return ['owwa-inv-category-page', 'owwa-office-property-register'];
    }

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function sortByColumn(string $column): void
    {
        $allowed = ['item_name', 'category_name', 'received', 'distributed', 'balance'];

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

    public function getStockCardRows(): LengthAwarePaginator
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, 10, 1);
        }

        return app(OfficePropertyRegisterService::class)->paginateStockCards(
            $user,
            (int) $this->category,
            filled($this->search) ? $this->search : null,
            $this->sortBy,
            $this->sortDir,
        );
    }

    public function openOfficeStockLedger(int $itemId): void
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        try {
            app(OfficePropertyRegisterService::class)->assertOfficeHasItem($user, $itemId);
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->ledgerPage = 1;

        $this->mountAction('viewOfficeStockLedger', [
            'itemId' => $itemId,
        ]);
    }

    public function viewOfficeStockLedgerAction(): Action
    {
        return Action::make('viewOfficeStockLedger')
            ->modalWidth(Width::FiveExtraLarge)
            ->extraModalWindowAttributes(['class' => 'owwa-view-record-modal'])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalHeading(function (): string {
                $ledger = $this->resolveMountedOfficeStockLedger();

                return $ledger['header']['item_name'].' — Stock History';
            })
            ->modalContent(fn (): HtmlString => new HtmlString(view(
                'filament.pages.partials.office-stock-ledger-modal',
                ['ledger' => $this->resolveMountedOfficeStockLedger()],
            )->render()));
    }

    public function propertyActionUrl(int $issuanceId, string $actionType): string
    {
        return PropertyActionRequestResource::createUrlForIssuance($issuanceId, $actionType);
    }

    public function suggestedPropertyActionType(array $unit): string
    {
        if (($unit['category_slug'] ?? null) === 'semi_expendable') {
            $status = $unit['eul_status'] ?? null;

            if (in_array($status, [SemiExpendableUsefulLife::STATUS_NEARING, SemiExpendableUsefulLife::STATUS_EXPIRED], true)) {
                return PropertyActionRequest::ACTION_REPLACEMENT;
            }
        }

        return PropertyActionRequest::ACTION_DISPOSAL;
    }

    public function showPropertyActionCta(array $unit): bool
    {
        if (($unit['category_slug'] ?? null) === 'semi_expendable') {
            return in_array($unit['eul_status'] ?? null, [
                SemiExpendableUsefulLife::STATUS_NEARING,
                SemiExpendableUsefulLife::STATUS_EXPIRED,
            ], true);
        }

        return in_array($unit['category_slug'] ?? null, ['ppe', 'semi_expendable'], true);
    }

    /**
     * @return array{
     *     header: array<string, string|null>,
     *     columns: array<string, string>,
     *     rows: array<int, array<string, mixed>>,
     *     property_units: array<int, array<string, mixed>>,
     *     show_property_units: bool,
     *     paginator: \Illuminate\Contracts\Pagination\LengthAwarePaginator
     * }
     */
    protected function resolveMountedOfficeStockLedger(): array
    {
        $user = Filament::auth()->user();
        $arguments = $this->getMountedAction()?->getArguments() ?? [];
        $itemId = (int) ($arguments['itemId'] ?? 0);

        if (! $user instanceof User) {
            abort(403);
        }

        return app(OfficePropertyRegisterService::class)->presentOfficeStockLedgerPaginated(
            $user,
            $itemId,
            max(1, $this->ledgerPage),
        );
    }
}
