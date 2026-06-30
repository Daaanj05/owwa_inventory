<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;

class OwwaFormModalDefaults
{
    public const MODAL_WINDOW_CLASS = 'owwa-view-record-modal owwa-record-modal';

    public const WIDTH_COMPACT = '3xl';

    public const WIDTH_MEDIUM = '4xl';

    public const WIDTH_STANDARD = '5xl';

    public const WIDTH_WIDE = '7xl';

    public static function apply(Action $action, string $width = self::WIDTH_STANDARD): Action
    {
        return $action
            ->modal()
            ->modalWidth($width)
            ->extraModalWindowAttributes(['class' => self::MODAL_WINDOW_CLASS]);
    }

    public static function createAction(string $width = self::WIDTH_STANDARD): CreateAction
    {
        /** @var CreateAction $action */
        $action = self::apply(CreateAction::make(), $width);

        return $action;
    }

    public static function editAction(string $width = self::WIDTH_STANDARD): EditAction
    {
        /** @var EditAction $action */
        $action = self::apply(EditAction::make(), $width);

        return $action;
    }
}
