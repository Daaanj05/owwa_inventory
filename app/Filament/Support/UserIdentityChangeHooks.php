<?php

namespace App\Filament\Support;

use App\Models\User;
use App\Notifications\SignInEmailChangedNotification;
use App\Support\FriendlyMessages;
use App\Support\MailDelivery;
use Filament\Auth\Notifications\VerifyEmail as FilamentVerifyEmail;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Str;

class UserIdentityChangeHooks
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareSaveData(array $data, User $record): array
    {
        if (! array_key_exists('email', $data)) {
            return $data;
        }

        $newEmail = Str::lower(trim((string) $data['email']));
        $currentEmail = Str::lower(trim((string) $record->email));

        if ($newEmail === '' || $newEmail === $currentEmail) {
            return $data;
        }

        $data['_previous_email'] = (string) $record->email;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function afterSave(User $record, array $data): void
    {
        $previousEmail = $data['_previous_email'] ?? null;

        if (! is_string($previousEmail) || $previousEmail === '') {
            return;
        }

        if (Str::lower(trim($previousEmail)) === Str::lower(trim((string) $record->email))) {
            return;
        }

        $record->forceFill([
            'email_verified_at' => null,
            'remember_token' => Str::random(60),
        ])->save();

        DB::table('sessions')->where('user_id', $record->id)->delete();

        $verifyNotification = app(FilamentVerifyEmail::class);
        $verifyNotification->url = User::guestEmailVerificationUrlFor($record);
        $verifyResult = MailDelivery::notify($record, $verifyNotification);

        MailDelivery::notify(
            NotificationFacade::route('mail', $previousEmail),
            new SignInEmailChangedNotification(
                newEmail: (string) $record->email,
                userName: (string) ($record->name ?: $record->email),
            ),
        );

        if ($verifyResult->success && $verifyResult->wasQueued) {
            Notification::make()
                ->title('Sign-in email updated')
                ->body(FriendlyMessages::adminEmailChangeVerificationQueued($record->email))
                ->success()
                ->send();

            return;
        }

        if ($verifyResult->success) {
            Notification::make()
                ->title('Sign-in email updated')
                ->body(FriendlyMessages::adminEmailChangeVerificationSent($record->email))
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title('Sign-in email updated')
            ->body(FriendlyMessages::adminEmailChangeVerificationFailed($record->email))
            ->warning()
            ->send();
    }
}
