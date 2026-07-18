<?php

namespace App\Filament\Resources\Transfers\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\Transfers\Schemas\TransferForm;
use App\Filament\Resources\Transfers\TransferResource;
use App\Services\TransferStockValidator;
use App\Support\OfficeSignatoryDefaults;
use Filament\Resources\Pages\CreateRecord;

class CreateTransfer extends CreateRecord
{
    use HasSystemAdminWizardHeading;

    protected static string $resource = TransferResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        app(TransferStockValidator::class)->validateForCreate($data);

        if (blank($data['property_number'] ?? null) && filled($data['item_id'] ?? null)) {
            $data['property_number'] = TransferForm::catalogPropertyNumberForItem((int) $data['item_id']);
        }

        return OfficeSignatoryDefaults::mergeNonBlank(
            OfficeSignatoryDefaults::forTransfer(
                isset($data['from_office_id']) ? (int) $data['from_office_id'] : null,
                isset($data['to_office_id']) ? (int) $data['to_office_id'] : null,
            ),
            $data,
        );
    }
}
