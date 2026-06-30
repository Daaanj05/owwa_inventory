<?php

namespace App\Filament\Resources\Transfers\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
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

        return OfficeSignatoryDefaults::mergeNonBlank(
            OfficeSignatoryDefaults::forTransfer(
                isset($data['from_office_id']) ? (int) $data['from_office_id'] : null,
                isset($data['to_office_id']) ? (int) $data['to_office_id'] : null,
            ),
            $data,
        );
    }
}
