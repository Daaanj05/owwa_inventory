<?php

namespace App\Filament\Resources\Disposals\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\Disposals\DisposalResource;
use App\Services\DisposalStockValidator;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditDisposal extends EditRecord
{
    use HasSystemAdminWizardHeading;

    protected static string $resource = DisposalResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        app(DisposalStockValidator::class)->validateForUpdate($data, $this->getRecord());

        return $data;
    }

    protected function getHeaderActions(): array
    {
        $archiveAction = DeleteAction::make();
        /** @var mixed $archiveAction */
        $archiveAction = $archiveAction->label('Archive');
        /** @var mixed $archiveAction */
        $archiveAction = $archiveAction->icon('heroicon-o-archive-box');
        /** @var mixed $archiveAction */
        $archiveAction = $archiveAction->modalHeading('Archive disposal');
        /** @var mixed $archiveAction */
        $archiveAction = $archiveAction->modalDescription('This disposal will be archived and hidden from the default list.');

        $group = ActionGroup::make([
            $archiveAction,
            RestoreAction::make(),
        ]);
        /** @var mixed $group */
        $group = $group->label('Actions');
        /** @var mixed $group */
        $group = $group->icon('heroicon-m-ellipsis-vertical');
        /** @var mixed $group */
        $group = $group->color('gray');
        /** @var mixed $group */
        $group = $group->button();

        return [$group];
    }
}
