<?php

namespace App\Filament\Resources\Disposals\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\Disposals\DisposalResource;
use App\Support\OfficeSignatoryDefaults;
use Filament\Resources\Pages\CreateRecord;

class CreateDisposal extends CreateRecord
{
    use HasSystemAdminWizardHeading;

    protected static string $resource = DisposalResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return OfficeSignatoryDefaults::mergeNonBlank(
            OfficeSignatoryDefaults::forDisposal(
                isset($data['office_id']) ? (int) $data['office_id'] : null,
            ),
            $data,
        );
    }
}
