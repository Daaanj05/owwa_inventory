<?php

namespace App\Filament\Concerns;

trait RedirectsCreateToList
{
    public function mount(): void
    {
        $this->redirect(static::getResource()::getUrl('index'));
    }
}
