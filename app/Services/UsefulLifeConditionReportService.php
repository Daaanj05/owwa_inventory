<?php

namespace App\Services;

use App\Filament\Resources\Issuances\IssuanceResource;
use App\Filament\Resources\PropertyActionRequests\PropertyActionRequestResource;
use App\Models\Issuance;
use App\Models\PropertyActionRequest;
use App\Models\User;
use App\Notifications\StillUsableUsefulLifeNotification;
use App\Support\NotificationRecipientResolver;
use App\Support\SemiExpendableUsefulLife;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class UsefulLifeConditionReportService
{
    public function reportStillUsable(Issuance $issuance, User $reporter, string $note): void
    {
        $this->assertReporterCanReview($issuance, $reporter);

        $note = trim($note);
        if ($note === '') {
            throw ValidationException::withMessages([
                'note' => 'Add a short note before reporting that the unit is still usable.',
            ]);
        }

        $issuance->update([
            'useful_life_condition' => Issuance::USEFUL_LIFE_STILL_USABLE,
            'useful_life_condition_note' => $note,
            'useful_life_condition_reported_at' => now(),
        ]);

        $issuance->refresh();

        $recipients = app(NotificationRecipientResolver::class)
            ->supplyCustodiansForOffice((int) $issuance->office_id);

        if ($recipients->isEmpty()) {
            $recipients = app(NotificationRecipientResolver::class)->supplyCustodiansForRegionalOffice();
        }

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new StillUsableUsefulLifeNotification($issuance));
        }
    }

    public function reportNeedsReplacement(Issuance $issuance, User $reporter): string
    {
        $this->assertReporterCanReview($issuance, $reporter);

        $issuance->update([
            'useful_life_condition' => Issuance::USEFUL_LIFE_NEEDS_REPLACEMENT,
            'useful_life_condition_reported_at' => now(),
        ]);

        return PropertyActionRequestResource::createUrlForIssuance(
            $issuance->id,
            PropertyActionRequest::ACTION_REPLACEMENT,
        );
    }

    protected function assertReporterCanReview(Issuance $issuance, User $reporter): void
    {
        $issuance->loadMissing('item.category');

        if ((int) $issuance->issued_to !== (int) $reporter->id) {
            throw ValidationException::withMessages([
                'issuance' => 'Only the person this unit was issued to can report its condition.',
            ]);
        }

        if ($issuance->item?->category?->getTemplateSlug() !== 'semi_expendable') {
            throw ValidationException::withMessages([
                'issuance' => 'Useful life review applies to semi-expendable units.',
            ]);
        }

        $status = SemiExpendableUsefulLife::statusForIssuance($issuance);
        if (! in_array($status, [SemiExpendableUsefulLife::STATUS_NEARING, SemiExpendableUsefulLife::STATUS_EXPIRED], true)) {
            throw ValidationException::withMessages([
                'issuance' => 'This unit is not nearing or past its useful life.',
            ]);
        }
    }

    public function custodianExtendUrl(Issuance $issuance): string
    {
        return IssuanceResource::viewModalUrl($issuance);
    }
}
