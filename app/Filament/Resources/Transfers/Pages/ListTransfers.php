<?php

namespace App\Filament\Resources\Transfers\Pages;

use App\Filament\Concerns\HasSearchRowToolbarActions;
use App\Filament\Concerns\HasSetupArchiveView;
use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Concerns\OwwaListExportActions;
use App\Filament\Concerns\StartsOwwaExportBusy;
use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\Transfers\TransferResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\ItemCategory;
use App\Support\CategoryWizardBreadcrumb;
use App\Support\CustodianOfficeScope;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListTransfers extends ListRecords
{
    use HasSearchRowToolbarActions;
    use HasSetupArchiveView;
    use HasSystemAdminWizardHeading;
    use StartsOwwaExportBusy;
    use SyncsActiveItemCategory;

    protected static string $resource = TransferResource::class;

    #[Url]
    public int|string|null $category = null;

    #[Url]
    public ?int $create = null;

    #[Url]
    public ?int $item_id = null;

    #[Url]
    public ?int $from_office = null;

    #[Url]
    public ?string $property_number = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $pendingCreateFormData = null;

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return 'Transfers';
    }

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        $categoryName = ItemCategory::query()->whereKey($this->activeItemCategoryId())->value('name');

        if (! $categoryName) {
            return 'Transfers';
        }

        return CategoryWizardBreadcrumb::make($categoryName, 'Transfers', $this->activeItemCategoryId());
    }

    /**
     * Filament schemas sometimes call `getRecord()` even on "list" pages.
     * List pages don't have a selected record, so we return `null`.
     */
    public function getRecord(): mixed
    {
        return null;
    }

    public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return null;
    }

    public function mount(): void
    {
        parent::mount();

        $this->syncActiveItemCategoryFromRequest();
        $this->registerSetupArchiveViewHook();

        if ((int) ($this->create ?? 0) !== 1 || ! TransferResource::canCreate()) {
            return;
        }

        $categoryId = $this->activeItemCategoryId();
        $categorySlug = $categoryId > 0
            ? \App\Models\ItemCategory::query()->find($categoryId)?->getTemplateSlug()
            : null;

        if ($categorySlug === 'consumables') {
            return;
        }

        $itemId = (int) ($this->item_id ?? 0);
        $fromOfficeId = (int) ($this->from_office ?? 0);
        $propertyNumber = filled($this->property_number) ? (string) $this->property_number : null;

        $this->create = null;
        $this->item_id = null;
        $this->from_office = null;
        $this->property_number = null;

        $this->pendingCreateFormData = array_filter([
            'item_id' => $itemId > 0 ? $itemId : null,
            'from_office_id' => $fromOfficeId > 0 ? $fromOfficeId : CustodianOfficeScope::inventoryOfficeId(),
            'item_category_filter' => $this->activeItemCategoryId() ?: null,
            'property_number' => $propertyNumber,
            'quantity' => filled($propertyNumber) ? 1 : null,
            'transfer_date' => now()->toDateString(),
        ]);

        $this->cacheInteractsWithHeaderActions();
        $this->mountAction('create');
    }

    protected function setupArchiveViewArchivedCount(): int
    {
        return TransferResource::getEloquentQuery()->onlyTrashed()->count();
    }

    protected function applySetupArchiveQuery(Builder $query): Builder
    {
        return $this->showingArchived
            ? $query->onlyTrashed()
            : $query->withoutTrashed();
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->applySetupArchiveQuery($query));
    }

    /**
     * @return list<array{label: string, action?: string, url?: string, style?: string}>
     */
    protected function searchRowToolbarButtons(): array
    {
        $buttons = [
            [
                'label' => 'Export Report',
                'action' => 'coaTransfer',
                'style' => 'gray',
            ],
        ];

        if (! $this->showingArchived) {
            $buttons[] = [
                'label' => 'New Transfer',
                'action' => 'create',
                'style' => 'primary',
            ];
        }

        return $buttons;
    }

    /**
     * @return array<int, string>
     */
    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'owwa-setup-archive-toggle',
            'owwa-search-row-toolbar',
        ];
    }

    public function cacheInteractsWithHeaderActions(): void
    {
        $this->cachedHeaderActions = [];

        parent::cacheInteractsWithHeaderActions();
    }

    protected function getHeaderActions(): array
    {
        return [
            OwwaListExportActions::headerAction('coaTransfer', 'owwa.export.bulk.transfers')
                ->livewire($this),
            OwwaFormModalDefaults::createActionForResource(TransferResource::class, OwwaFormModalDefaults::WIDTH_STANDARD)
                ->label('New Transfer')
                ->fillForm(function (): array {
                    $defaults = [
                        'item_category_filter' => $this->activeItemCategoryId() ?: null,
                        'from_office_id' => CustodianOfficeScope::inventoryOfficeId(),
                        'transfer_date' => now()->toDateString(),
                    ];

                    if ($this->pendingCreateFormData !== null) {
                        $defaults = array_merge($defaults, $this->pendingCreateFormData);
                        $this->pendingCreateFormData = null;
                    }

                    return $defaults;
                })
                ->visible(fn (): bool => ! $this->showingArchived),
        ];
    }
}
