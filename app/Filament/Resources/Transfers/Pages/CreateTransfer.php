<?php

namespace App\Filament\Resources\Transfers\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\Transfers\Schemas\TransferForm;
use App\Filament\Resources\Transfers\TransferResource;
use App\Models\Transfer;
use App\Services\TransferStockValidator;
use App\Support\OfficeSignatoryDefaults;
use App\Support\SupplyOfficeResolver;
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
        $data = $this->applyReturnDestination($data);
        unset($data['return_issuance_id']);

        app(TransferStockValidator::class)->validateForCreate($data);

        if (($data['transfer_type'] ?? null) !== Transfer::TYPE_RETURN
            && blank($data['property_number'] ?? null)
            && filled($data['item_id'] ?? null)) {
            $data['property_number'] = TransferForm::catalogPropertyNumberForItem((int) $data['item_id']);
        }

        $data = OfficeSignatoryDefaults::mergeNonBlank(
            OfficeSignatoryDefaults::forTransfer(
                isset($data['from_office_id']) ? (int) $data['from_office_id'] : null,
                isset($data['to_office_id']) ? (int) $data['to_office_id'] : null,
            ),
            $data,
        );

        // Ordinary create posts stock immediately; property-return drafts stay unapproved.
        $data['approved_at'] = now();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function applyReturnDestination(array $data): array
    {
        if (! in_array($data['transfer_type'] ?? null, [Transfer::TYPE_RETURN, Transfer::TYPE_RELOCATE], true)) {
            return $data;
        }

        $regionalOfficeId = app(SupplyOfficeResolver::class)->resolve();
        if ($regionalOfficeId !== null) {
            $data['to_office_id'] = $regionalOfficeId;
            if (($data['transfer_type'] ?? null) === Transfer::TYPE_RELOCATE) {
                $data['to_accountable_officer'] = app(SupplyOfficeResolver::class)->resolveOfficeName()
                    ?? ($data['to_accountable_officer'] ?? null);
            }
        }

        return $data;
    }
}
