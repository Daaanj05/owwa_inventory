<?php

namespace App\Filament\Resources\Requisitions\Pages;

use App\Filament\Concerns\RedirectsCreateToList;
use App\Filament\Resources\Requisitions\RequisitionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRequisition extends CreateRecord
{
    use RedirectsCreateToList;

    protected static string $resource = RequisitionResource::class;

    public function mount(): void
    {
        $itemId = (int) request()->query('item_id', 0);

        if ($itemId > 0) {
            $this->redirect(RequisitionResource::getUrl('index', array_filter([
                'create' => 1,
                'item_id' => $itemId,
                'category' => request()->query('category'),
            ])));

            return;
        }

        $this->redirect(RequisitionResource::getUrl('index'));
    }
}
