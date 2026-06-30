<?php

namespace App\Filament\Resources\PhysicalCountSessions\Pages;

use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\PhysicalCountSessions\Concerns\HasPhysicalCountWizardBreadcrumbs;
use App\Filament\Resources\PhysicalCountSessions\PhysicalCountSessionResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\ItemCategory;
use App\Models\PhysicalCountSession;
use App\Services\PhysicalCountPreloadService;
use App\Support\CustodianOfficeScope;
use App\Support\OfficeSignatoryDefaults;
use Filament\Actions\Action;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListPhysicalCountSessions extends ListRecords
{
    use HasPhysicalCountWizardBreadcrumbs;
    use SyncsActiveItemCategory;

    protected static string $resource = PhysicalCountSessionResource::class;

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
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('startMobile')
                ->label('Start count (mobile)')
                ->icon('heroicon-o-device-phone-mobile')
                ->color('primary')
                ->url(fn (): string => PhysicalCountSessionResource::getUrl('start-mobile')),
            OwwaFormModalDefaults::createAction(OwwaFormModalDefaults::WIDTH_STANDARD)
                ->mutateFormDataUsing(function (array $data): array {
                    $categoryId = (int) session('active_item_category_id', 0);
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
                    if (! $record->supportsQrScanning()) {
                        return;
                    }

                    Notification::make()
                        ->title('Physical count session created')
                        ->body('Next: load expected assets, then scan property tags with your phone.')
                        ->success()
                        ->actions([
                            NotificationAction::make('preload')
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
                            NotificationAction::make('scan')
                                ->label('Scan with phone')
                                ->button()
                                ->url(PhysicalCountSessionResource::getUrl('scan', ['record' => $record])),
                        ])
                        ->send();
                })
                ->successRedirectUrl(fn (PhysicalCountSession $record): string => PhysicalCountSessionResource::viewModalUrl($record)),
        ];
    }
}
