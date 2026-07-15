<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Illuminate\Support\Str;

class OwwaFormModalDefaults
{
    public const MODAL_WINDOW_CLASS = 'owwa-view-record-modal owwa-record-modal fi-fixed-positioning-context';

    public const WIDTH_COMPACT = '3xl';

    public const WIDTH_MEDIUM = '4xl';

    public const WIDTH_STANDARD = '5xl';

    public const WIDTH_WIDE = '7xl';

    public static function formatModelLabel(string $label): string
    {
        return Str::of($label)->trim()->ucfirst()->toString();
    }

    public static function createHeading(string $label): string
    {
        return self::formatModelLabel($label);
    }

    public static function editHeading(string $label): string
    {
        return 'Edit '.self::formatModelLabel($label);
    }

    public static function viewHeading(string $label): string
    {
        return self::formatModelLabel($label);
    }

    public static function apply(Action $action, string $width = self::WIDTH_STANDARD): Action
    {
        return $action
            ->modal()
            ->modalWidth($width)
            ->closeModalByClickingAway(false)
            ->closeModalByEscaping(false)
            ->extraModalWindowAttributes(['class' => self::MODAL_WINDOW_CLASS]);
    }

    public static function createAction(
        string $width = self::WIDTH_STANDARD,
        ?string $modelLabel = null,
        ?string $description = null,
    ): CreateAction {
        /** @var CreateAction $action */
        $action = self::apply(CreateAction::make(), $width);

        if ($modelLabel !== null) {
            $action->modalHeading(self::createHeading($modelLabel));
        }

        if ($description !== null) {
            $action->modalDescription($description);
        }

        return $action;
    }

    /**
     * @param  class-string<resource>  $resourceClass
     */
    public static function createActionForResource(
        string $resourceClass,
        string $width = self::WIDTH_STANDARD,
        ?string $description = null,
    ): CreateAction {
        return self::createAction($width, $resourceClass::getModelLabel(), $description);
    }

    public static function editAction(
        string $width = self::WIDTH_STANDARD,
        ?string $modelLabel = null,
    ): EditAction {
        /** @var EditAction $action */
        $action = self::apply(EditAction::make(), $width);

        if ($modelLabel !== null) {
            $action->modalHeading(self::editHeading($modelLabel));
        }

        return $action;
    }

    /**
     * @param  class-string<resource>  $resourceClass
     */
    public static function editActionForResource(
        string $resourceClass,
        string $width = self::WIDTH_STANDARD,
    ): EditAction {
        return self::editAction($width, $resourceClass::getModelLabel());
    }
}
