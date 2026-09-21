<?php

namespace App\Filament\Resources\Transfers\Pages;

use App\Filament\Concerns\CoaListPageExports;
use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\Transfers\TransferResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\ItemCategory;
use App\Support\CategoryWizardBreadcrumb;
use App\Support\CustodianOfficeScope;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListTransfers extends ListRecords
{
    use CoaListPageExports;
    use HasSystemAdminWizardHeading;
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

        $this->mountAction('create', [], ['schemaComponent' => 'content']);
    }

    public function getTabs(): array
    {
        return [
            'active' => Tab::make('Active')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutTrashed())
                ->excludeQueryWhenResolvingRecord(),
            'archived' => Tab::make('Archived')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->onlyTrashed())
                ->excludeQueryWhenResolvingRecord(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        $actionsComponent = Actions::make([
            $this->coaExportReportAction('coaTransfer', 'owwa.export.bulk.transfers'),
            OwwaFormModalDefaults::createActionForResource(TransferResource::class, OwwaFormModalDefaults::WIDTH_STANDARD)
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
                }),
        ]);

        /** @var mixed $actionsComponent */
        $actionsComponent = $actionsComponent->alignEnd();

        $flexComponent = Flex::make([
            $this->getTabsContentComponent(),
            $actionsComponent,
        ]);

        /** @var mixed $flexComponent */
        $flexComponent = $flexComponent->alignBetween()->verticallyAlignCenter();

        return $schema
            ->components([
                $flexComponent,
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
