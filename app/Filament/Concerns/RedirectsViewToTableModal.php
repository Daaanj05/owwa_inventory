<?php

namespace App\Filament\Concerns;

trait RedirectsViewToTableModal
{
    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->redirect(static::getResource()::viewModalUrl($this->getRecord()));
    }
}
