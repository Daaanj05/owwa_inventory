<?php

namespace App\Filament\Concerns;

use Filament\Facades\Filament;

trait ListensForPropertyActionBroadcasts
{
    use HasPropertyActionRefreshFallback;

    /**
     * @return array<string, string>
     */
    protected function propertyActionBroadcastListeners(): array
    {
        if (! filled(config('filament.broadcasting.echo.key'))) {
            return [];
        }

        $user = Filament::auth()->user();
        $listeners = [];

        if ($user?->office_id) {
            $listeners["echo-private:property-actions.office.{$user->office_id},.property-action.changed"] = 'refreshFromPropertyActionBroadcast';
        }

        if ($user?->isSupplyCustodian()) {
            $listeners['echo-private:property-actions.custodian,.property-action.changed'] = 'refreshFromPropertyActionBroadcast';
        }

        if ($user) {
            $listeners["echo-private:property-actions.user.{$user->id},.property-action.changed"] = 'refreshFromPropertyActionBroadcast';
        }

        return $listeners;
    }

    public function getListeners(): array
    {
        $parentListeners = method_exists(parent::class, 'getListeners')
            ? parent::getListeners()
            : [];

        return array_merge($parentListeners, $this->propertyActionBroadcastListeners());
    }

    public function refreshFromPropertyActionBroadcast(): void
    {
        $this->dispatch('$refresh');
    }
}
