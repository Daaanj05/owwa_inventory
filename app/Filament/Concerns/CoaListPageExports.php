<?php

namespace App\Filament\Concerns;

use Filament\Actions\Action;

trait CoaListPageExports
{
    use StartsOwwaExportBusy;

    protected function coaExportReportAction(
        string $name,
        string $routeName,
        ?string $label = null,
        ?string $selectionHint = null,
    ): Action {
        return OwwaListExportActions::schemaHeaderExportAction($name, $routeName, $label, $selectionHint)
            ->livewire($this);
    }
}
