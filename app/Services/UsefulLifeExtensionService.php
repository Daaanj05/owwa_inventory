<?php

namespace App\Services;

use App\Models\Issuance;
use App\Models\UsefulLifeExtension;
use App\Models\User;
use App\Support\SemiExpendableUsefulLife;
use Illuminate\Support\Carbon;

class UsefulLifeExtensionService
{
    public function extend(Issuance $issuance, string $newEul, ?string $reason, User $approver): UsefulLifeExtension
    {
        $issuance->loadMissing('item.category');

        SemiExpendableUsefulLife::assertEligibleForSemi($newEul);

        $previousEul = (string) ($issuance->estimated_useful_life ?? '');
        $previousExpiresAt = $issuance->eul_expires_at;
        $newExpiresAt = SemiExpendableUsefulLife::computeExpiresAt($issuance->issuance_date, $newEul);

        $extension = UsefulLifeExtension::query()->create([
            'issuance_id' => $issuance->id,
            'previous_eul' => $previousEul,
            'new_eul' => $newEul,
            'previous_expires_at' => $previousExpiresAt,
            'new_expires_at' => $newExpiresAt,
            'reason' => $reason,
            'approved_by' => $approver->id,
            'approved_at' => Carbon::now(),
        ]);

        $issuance->update([
            'estimated_useful_life' => $newEul,
            'eul_expires_at' => $newExpiresAt,
        ]);

        return $extension;
    }
}
