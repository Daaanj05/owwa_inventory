<?php

namespace App\Filament\Resources\PhysicalCountSessions\Pages;

use App\Filament\Concerns\HasSearchRowToolbarActions;
use App\Filament\Concerns\HasSetupArchiveView;
use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\PhysicalCountSessions\Concerns\HasPhysicalCountWizardBreadcrumbs;
use App\Filament\Resources\PhysicalCountSessions\PhysicalCountSessionResource;
use App\Filament\Resources\PhysicalCountSessions\Schemas\PhysicalCountSessionForm;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\ItemCategory;
use App\Models\PhysicalCountSession;
use App\Services\PhysicalCountPreloadService;
use App\Support\CustodianOfficeScope;
use App\Support\OfficeSignatoryDefaults;
use App\Support\PhysicalCountPropertyClassResolver;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListPhysicalCountSessions extends ListRecords
{
    use HasPhysicalCountWizardBreadcrumbs;
    use HasSearchRowToolbarActions;
    use HasSetupArchiveView;
    use SyncsActiveItemCategory;

    #[Url]
    public int|string|null $category = null;

    protected static string $resource = PhysicalCountSessionResource::class;

    protected bool $loadItemsOnCreate = true;

    public function getTitle(): string|Htmlable
    {
        return 'Physical counts';
    }

    public function getHeading(): string|Htmlable
    {
        return $this->physicalCountBreadcrumbHtml();
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    public function mount(): void
    {
        parent::mount();

        $this->syncActiveItemCategoryFromRequest();
        $this->registerSetupArchiveViewHook();
    }

    protected function setupArchiveViewArchivedCount(): int
    {
        $query = PhysicalCountSession::query()->whereNotNull('archived_at');
        $categoryId = $this->activeItemCategoryId();
        if ($categoryId > 0) {
            $query->where('item_category_id', $categoryId);
        }

        return CustodianOfficeScope::applyOfficeColumn($query)->count();
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->applySetupArchiveQuery($query))
            ->emptyStateHeading(fn (): string => $this->showingArchived
                ? 'No archived physical counts'
                : 'No physical counts')
            ->emptyStateDescription(fn (): string => $this->showingArchived
                ? 'Archived sessions will appear here. Switch back to Active to continue counting.'
                : 'Create a physical count session to get started.');
    }

    /**
     * @return list<array{label: string, action?: string, url?: string, style?: string}>
     */
    protected function searchRowToolbarButtons(): array
    {
        if ($this->showingArchived) {
            return [];
        }

        $buttons = [];

        if ($this->activeCategorySupportsMobileCount()) {
            $buttons[] = [
                'label' => 'Start count (mobile)',
                'url' => PhysicalCountSessionResource::getUrl('start-mobile', [
                    'category' => $this->activeItemCategoryId(),
                ]),
                'style' => 'primary',
            ];
        }

        $buttons[] = [
            'label' => 'New Physical Count',
            'action' => 'create',
            'style' => 'primary',
        ];

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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('startMobile')
                ->label('Start count (mobile)')
                ->icon('heroicon-o-device-phone-mobile')
                ->color('primary')
                ->visible(fn (): bool => ! $this->showingArchived && $this->activeCategorySupportsMobileCount())
                ->url(fn (): string => PhysicalCountSessionResource::getUrl('start-mobile', [
                    'category' => $this->activeItemCategoryId(),
                ])),
            OwwaFormModalDefaults::createActionForResource(
                PhysicalCountSessionResource::class,
                OwwaFormModalDefaults::WIDTH_STANDARD,
                $this->consumableCreateModalDescription(),
            )
                ->label('New Physical Count')
                ->visible(fn (): bool => ! $this->showingArchived)
                ->fillForm(fn (): array => PhysicalCountSessionForm::defaultCreateFormData($this->activeItemCategoryId()))
                ->mutateFormDataUsing(function (array $data): array {
                    $this->loadItemsOnCreate = array_key_exists('load_items_on_create', $data)
                        ? (bool) $data['load_items_on_create']
                        : false;
                    unset($data['load_items_on_create']);

                    $categoryId = $this->activeItemCategoryId();
                    if ($categoryId > 0) {
                        $data['item_category_id'] = $categoryId;
                    }

                    $category = ItemCategory::query()->find($categoryId);
                    $data['count_type'] ??= match ($category?->getTemplateSlug()) {
                        'ppe' => PhysicalCountSession::TYPE_RPCPPE,
                        'semi_expendable' => PhysicalCountSession::TYPE_RPCSP,
                        default => PhysicalCountSession::TYPE_RPCI,
                    };
                    $data['count_date'] ??= now()->toDateString();
                    $data['office_id'] ??= CustodianOfficeScope::inventoryOfficeId();

                    return OfficeSignatoryDefaults::mergeNonBlank(
                        OfficeSignatoryDefaults::forPhysicalCountSession(
                            isset($data['office_id']) ? (int) $data['office_id'] : null,
                        ),
                        $data,
                    );
                })
                ->after(function (PhysicalCountSession $record): void {
                    PhysicalCountPropertyClassResolver::syncSession($record);

                    if ($record->isConsumablePhysicalCount()) {
                        if ($this->loadItemsOnCreate) {
                            $result = app(PhysicalCountPreloadService::class)->preloadFromStockBalances($record);

                            Notification::make()
                                ->title('Physical count created — items loaded')
                                ->body("Created {$result['created']}, updated {$result['updated']}, skipped {$result['skipped']}. Next: enter On hand per count for each item.")
                                ->success()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Physical count session created')
                            ->body('Next: Load Items from the session view, or add items manually. Inventory type is set automatically from those items.')
                            ->success()
                            ->actions([
                                Action::make('preload')
                                    ->label('Load Items now')
                                    ->button()
                                    ->action(function () use ($record): void {
                                        $result = app(PhysicalCountPreloadService::class)->preloadFromStockBalances($record);

                                        Notification::make()
                                            ->title('Items loaded')
                                            ->body("Created {$result['created']}, updated {$result['updated']}, skipped {$result['skipped']}. Enter On hand per count for each item.")
                                            ->success()
                                            ->send();
                                    }),
                            ])
                            ->send();

                        return;
                    }

                    if (! $record->supportsQrScanning()) {
                        return;
                    }

                    Notification::make()
                        ->title('Physical count session created')
                        ->body('Next: load expected assets, then scan property tags with your phone.')
                        ->success()
                        ->actions([
                            Action::make('preload')
                                ->label('Load expected assets now')
                                ->button()
                                ->action(function () use ($record): void {
                                    $result = app(PhysicalCountPreloadService::class)->preloadFromCustodyRecords($record);

                                    Notification::make()
                                        ->title('Expected assets loaded')
                                        ->body("Created {$result['created']}, updated {$result['updated']}, skipped {$result['skipped']}.")
                                        ->success()
                                        ->send();
                                }),
                            Action::make('scan')
                                ->label('Scan with phone')
                                ->button()
                                ->url(PhysicalCountSessionResource::getUrl('scan', ['record' => $record])),
                        ])
                        ->send();
                })
                ->successRedirectUrl(fn (PhysicalCountSession $record): string => PhysicalCountSessionResource::viewModalUrl($record)),
        ];
    }

    protected function consumableCreateModalDescription(): ?string
    {
        if (! $this->activeCategoryIsConsumable()) {
            return null;
        }

        return PhysicalCountSessionForm::loadItemsCreateModalDescription();
    }

    protected function activeCategoryIsConsumable(): bool
    {
        $categoryId = $this->activeItemCategoryId();
        if ($categoryId <= 0) {
            return true;
        }

        return ItemCategory::query()->find($categoryId)?->getTemplateSlug() === 'consumables';
    }

    protected function activeCategorySupportsMobileCount(): bool
    {
        $categoryId = $this->activeItemCategoryId();
        if ($categoryId <= 0) {
            return true;
        }

        $slug = ItemCategory::query()->find($categoryId)?->getTemplateSlug();

        return in_array($slug, ['ppe', 'semi_expendable'], true);
    }
}
