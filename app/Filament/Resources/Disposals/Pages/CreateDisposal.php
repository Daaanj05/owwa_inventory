<?php

namespace App\Filament\Resources\Disposals\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\Disposals\DisposalResource;
use App\Filament\Resources\Disposals\Schemas\DisposalForm;
use App\Support\OfficeSignatoryDefaults;
use App\Support\SupplyOfficeResolver;
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
        // Consumable WMR writes off regional SC warehouse stock only.
        if (DisposalForm::resolvedCategorySlug() === 'consumables') {
            $data['par_issuance_id'] = null;
            $regionalOfficeId = app(SupplyOfficeResolver::class)->resolve();
            if ($regionalOfficeId !== null) {
                $data['office_id'] = $regionalOfficeId;
            }
        }

        return OfficeSignatoryDefaults::mergeNonBlank(
            OfficeSignatoryDefaults::forDisposal(
                isset($data['office_id']) ? (int) $data['office_id'] : null,
            ),
            $data,
        );
    }
}
