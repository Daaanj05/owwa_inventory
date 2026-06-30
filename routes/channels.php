<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, int $id): bool {
    return (int) $user->id === $id;
});

Broadcast::channel('requisitions.office.{officeId}', function (User $user, int $officeId): bool {
    return (int) $user->office_id === $officeId
        && ($user->isUnitConsolidator() || $user->isEmployee());
});

Broadcast::channel('requisitions.custodian', function (User $user): bool {
    return $user->isSupplyCustodian();
});

Broadcast::channel('requisitions.user.{userId}', function (User $user, int $userId): bool {
    return (int) $user->id === $userId;
});
