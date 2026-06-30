<?php

namespace App\Filament\Resources\Transfers\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\Transfers\TransferResource;
use App\Services\TransferStockValidator;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditTransfer extends EditRecord
{
    use HasSystemAdminWizardHeading;

    protected static string $resource = TransferResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        app(TransferStockValidator::class)->validateForUpdate($data, $this->getRecord());

        return $data;
    }

    protected function getHeaderActions(): array
    {
        $group = ActionGroup::make([
            DeleteAction::make()
                ->label('Archive')
                ->modalHeading('Archive transfer')
                ->modalDescription('This transfer will be archived and hidden from the default list.'),
            RestoreAction::make(),
        ]);
        /** @var mixed $group */
        $group = $group->label('Actions');
        $group = $group->icon('heroicon-m-ellipsis-vertical');
        $group = $group->color('gray');
        $group = $group->button();

        return [$group];
    }
}
