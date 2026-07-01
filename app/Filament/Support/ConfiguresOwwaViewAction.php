<?php

namespace App\Filament\Support;

use Filament\Actions\ViewAction;

class ConfiguresOwwaViewAction
{
    /**
     * @param  array<int, \Filament\Actions\Action|\Filament\Actions\ActionGroup>  $footerActions
     * @param  array<int, \Filament\Schemas\Components\Component|\Filament\Infolists\Components\Component>  $schema
     */
    public static function make(
        array $schema = [],
        array $footerActions = [],
        string $modalWidth = '5xl',
        ?string $extraModalClass = null,
        ?string $modalHeading = null,
    ): ViewAction {
        $windowClass = 'owwa-view-record-modal owwa-record-modal';
        if ($extraModalClass !== null) {
            $windowClass .= ' '.$extraModalClass;
        }

        $action = ViewAction::make()
            ->modal()
            ->modalWidth($modalWidth)
            ->label('')
            ->tableIcon(null)
            ->extraAttributes(['class' => 'sr-only'])
            ->extraModalWindowAttributes(['class' => $windowClass])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');

        if ($schema !== []) {
            $action->schema($schema);
        }

        if ($footerActions !== []) {
            $action->extraModalFooterActions($footerActions);
        }

        if ($modalHeading !== null) {
            $action->modalHeading($modalHeading);
        }

        return $action;
    }
}
