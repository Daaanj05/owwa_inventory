<?php

namespace App\Filament\Resources\Issuances\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\Issuances\IssuanceResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditIssuance extends EditRecord
{
    use HasSystemAdminWizardHeading;

    protected static string $resource = IssuanceResource::class;

    protected function getHeaderActions(): array
    {
        $group = ActionGroup::make([
            DeleteAction::make()
                ->label('Archive')
                ->modalHeading('Archive issuance')
                ->modalDescription('This issuance will be archived and hidden from the default list.'),
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
