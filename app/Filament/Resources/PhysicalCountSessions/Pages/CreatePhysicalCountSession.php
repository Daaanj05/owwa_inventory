<?php

namespace App\Filament\Resources\PhysicalCountSessions\Pages;

use App\Filament\Concerns\RedirectsCreateToList;
use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\PhysicalCountSessions\PhysicalCountSessionResource;
use App\Filament\Resources\PhysicalCountSessions\Schemas\PhysicalCountSessionForm;
use Filament\Resources\Pages\CreateRecord;

class CreatePhysicalCountSession extends CreateRecord
{
    use RedirectsCreateToList;
    use SyncsActiveItemCategory;

    protected static string $resource = PhysicalCountSessionResource::class;

    public function mount(): void
    {
        parent::mount();

        $this->syncActiveItemCategoryFromRequest(false);
        $this->form->fill(PhysicalCountSessionForm::defaultCreateFormData($this->activeItemCategoryId()));
    }
}
