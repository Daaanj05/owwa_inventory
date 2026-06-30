<?php

namespace App\Filament\Concerns;

use Filament\Facades\Filament;

trait ListensForRequisitionBroadcasts
{
    /**
     * @return array<string, string>
     */
    protected function requisitionBroadcastListeners(): array
    {
        $user = Filament::auth()->user();
        $listeners = [];

        if ($user?->office_id) {
            $listeners["echo-private:requisitions.office.{$user->office_id},.requisition.changed"] = 'refreshFromRequisitionBroadcast';
        }

        if ($user?->isSupplyCustodian()) {
            $listeners['echo-private:requisitions.custodian,.requisition.changed'] = 'refreshFromRequisitionBroadcast';
        }

        if ($user) {
            $listeners["echo-private:requisitions.user.{$user->id},.requisition.changed"] = 'refreshFromRequisitionBroadcast';
        }

        return $listeners;
    }

    public function getListeners(): array
    {
        $parentListeners = method_exists(parent::class, 'getListeners')
            ? parent::getListeners()
            : [];

        return array_merge($parentListeners, $this->requisitionBroadcastListeners());
    }

    public function refreshFromRequisitionBroadcast(): void
    {
        $this->dispatch('$refresh');
    }
}
