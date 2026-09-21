<?php

namespace App\Filament\Resources\Disposals\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Concerns\StartsOwwaExportBusy;
use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\Disposals\Concerns\DisposalExportReportAction;
use App\Filament\Resources\Disposals\DisposalResource;
use App\Filament\Resources\Disposals\Schemas\DisposalForm;
use App\Filament\Resources\Pages\ListRecordsWithoutFilterUrl;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\InventoryUnit;
use App\Models\ItemCategory;
use App\Services\DisposalStockValidator;
use App\Support\CategoryWizardBreadcrumb;
use App\Support\CustodianOfficeScope;
use App\Support\OfficeSignatoryDefaults;
use App\Support\ScanAssetHandoff;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListDisposals extends ListRecordsWithoutFilterUrl
{
    use HasSystemAdminWizardHeading;
    use StartsOwwaExportBusy;
    use SyncsActiveItemCategory;

    #[Url]
    public int|string|null $category = null;

    #[Url]
    public ?int $create = null;

    #[Url]
    public ?int $inventory_unit_id = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $pendingCreateFormData = null;

    protected static string $resource = DisposalResource::class;

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return 'Disposals';
    }

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        $categoryName = ItemCategory::query()->whereKey($this->activeItemCategoryId())->value('name');

        if (! $categoryName) {
            return 'Disposals';
        }

        return CategoryWizardBreadcrumb::make($categoryName, 'Disposals', $this->activeItemCategoryId());
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
        $this->mountCreateFromScanQuery();
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
            DisposalExportReportAction::make(),
            OwwaFormModalDefaults::createActionForResource(DisposalResource::class, OwwaFormModalDefaults::WIDTH_MEDIUM)
                ->fillForm(function (): array {
                    $defaults = [
                        'disposal_type' => DisposalForm::defaultDisposalType(),
                        'item_category_filter' => $this->activeItemCategoryId() ?: null,
                        'office_id' => CustodianOfficeScope::inventoryOfficeId(),
                        'disposal_date' => now()->toDateString(),
                    ];

                    if ($this->pendingCreateFormData !== null) {
                        $defaults = array_merge($defaults, $this->pendingCreateFormData);
                        $this->pendingCreateFormData = null;
                    }

                    return $defaults;
                })
                ->mutateFormDataUsing(function (array $data): array {
                    app(DisposalStockValidator::class)->validateForCreate($data);

                    return OfficeSignatoryDefaults::mergeNonBlank(
                        OfficeSignatoryDefaults::forDisposal(
                            isset($data['office_id']) ? (int) $data['office_id'] : null,
                        ),
                        $data,
                    );
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

    protected function mountCreateFromScanQuery(): void
    {
        if ((int) ($this->create ?? 0) !== 1 || ! DisposalResource::canCreate()) {
            return;
        }

        $unitId = (int) ($this->inventory_unit_id ?? 0);
        $this->create = null;
        $this->inventory_unit_id = null;

        if ($unitId <= 0) {
            return;
        }

        $unit = InventoryUnit::query()
            ->with(['item.category', 'office', 'issuance', 'acquisition'])
            ->find($unitId);

        $resolved = ScanAssetHandoff::resolveActionableUnit($unit);

        if ($resolved === null || ScanAssetHandoff::isUnitClaimed($resolved['unit'])) {
            Notification::make()
                ->title('Cannot open disposal from scan')
                ->body('That inventory unit is unavailable, already claimed, or outside your office.')
                ->danger()
                ->send();

            return;
        }

        $disposalType = DisposalForm::defaultDisposalType() ?? 'unserviceable';

        $this->pendingCreateFormData = ScanAssetHandoff::disposalFormDefaults($resolved['unit'], $disposalType);

        $this->mountAction('create', [], ['schemaComponent' => 'content']);
    }
}
