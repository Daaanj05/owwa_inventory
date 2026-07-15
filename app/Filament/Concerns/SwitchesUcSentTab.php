<?php

namespace App\Filament\Concerns;

use Livewire\Component;

trait SwitchesUcSentTab
{
    public function switchUcTabToSent(): void
    {
        $this->ucTab = 'sent';
        $this->resetTable();
    }

    public static function switchLivewireUcTabToSent(mixed $livewire): void
    {
        if (! $livewire instanceof Component) {
            return;
        }

        if (! method_exists($livewire, 'switchUcTabToSent')) {
            return;
        }

        $livewire->switchUcTabToSent();
    }
}
