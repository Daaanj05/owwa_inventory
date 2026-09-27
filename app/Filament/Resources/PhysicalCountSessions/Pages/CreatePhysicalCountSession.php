<?php

namespace App\Filament\Resources\PhysicalCountSessions\Pages;

use App\Filament\Concerns\RedirectsCreateToList;
use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\PhysicalCountSessions\PhysicalCountSessionResource;
use App\Filament\Resources\PhysicalCountSessions\Schemas\PhysicalCountSessionForm;
use App\Models\PhysicalCountSession;
use App\Services\PhysicalCountPreloadService;
use App\Support\PhysicalCountPropertyClassResolver;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreatePhysicalCountSession extends CreateRecord
{
    use RedirectsCreateToList;
    use SyncsActiveItemCategory;

    protected static string $resource = PhysicalCountSessionResource::class;

    protected bool $loadItemsOnCreate = true;

    public function mount(): void
    {
        parent::mount();

        $this->syncActiveItemCategoryFromRequest(false);
        $this->form->fill(PhysicalCountSessionForm::defaultCreateFormData($this->activeItemCategoryId()));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->loadItemsOnCreate = array_key_exists('load_items_on_create', $data)
            ? (bool) $data['load_items_on_create']
            : false;
        unset($data['load_items_on_create']);

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var PhysicalCountSession $record */
        $record = $this->getRecord();
        PhysicalCountPropertyClassResolver::syncSession($record);

        if (! $record->isConsumablePhysicalCount() || ! $this->loadItemsOnCreate) {
            return;
        }

        $result = app(PhysicalCountPreloadService::class)->preloadFromStockBalances($record);

        Notification::make()
            ->title('Items loaded')
            ->body("Created {$result['created']}, updated {$result['updated']}, skipped {$result['skipped']}. Enter On hand per count for each item.")
            ->success()
            ->send();
    }
}
