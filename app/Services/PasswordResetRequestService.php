<?php

namespace App\Services;

use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Notifications\PasswordResetRequestDatabaseNotification;
use App\Support\MailDelivery;
use App\Support\MailDeliveryResult;
use Filament\Auth\Notifications\ResetPassword as FilamentResetPassword;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Support\Facades\Password;

class PasswordResetRequestService
{
    public function submitRequest(string $email): bool
    {
        $this->pruneExpired();

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            return false;
        }

        $adminPanel = Filament::getPanel('admin');

        if (
            $user instanceof FilamentUser
            && ! $user->canAccessPanel($adminPanel)
        ) {
            return false;
        }

        $request = PasswordResetRequest::query()
            ->where('user_id', $user->id)
            ->where('status', PasswordResetRequest::STATUS_PENDING)
            ->first();

        if ($request !== null) {
            $request->update([
                'requested_at' => now(),
            ]);
        } else {
            $request = PasswordResetRequest::query()->create([
                'user_id' => $user->id,
                'status' => PasswordResetRequest::STATUS_PENDING,
                'requested_at' => now(),
            ]);
        }

        $this->notifySystemAdmins($user);

        return true;
    }

    public function sendResetEmail(PasswordResetRequest $request, User $admin): MailDeliveryResult
    {
        $user = $request->user;

        if ($user === null) {
            return new MailDeliveryResult(false, false);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $token = Password::broker(Filament::getAuthPasswordBroker())->createToken($user);

        $notification = app(FilamentResetPassword::class, ['token' => $token]);
        $notification->url = Filament::getResetPasswordUrl($token, $user);

        $result = MailDelivery::notify($user, $notification);

        if ($result->success) {
            $request->update([
                'status' => PasswordResetRequest::STATUS_SENT,
                'handled_by' => $admin->id,
                'handled_at' => now(),
            ]);
        }

        return $result;
    }

    public function sendResetEmailForUser(User $user, User $admin): MailDeliveryResult
    {
        $request = PasswordResetRequest::query()
            ->where('user_id', $user->id)
            ->where('status', PasswordResetRequest::STATUS_PENDING)
            ->first();

        if ($request === null) {
            $request = PasswordResetRequest::query()->create([
                'user_id' => $user->id,
                'status' => PasswordResetRequest::STATUS_PENDING,
                'requested_at' => now(),
            ]);
        }

        return $this->sendResetEmail($request, $admin);
    }

    public function dismiss(PasswordResetRequest $request, User $admin): void
    {
        $request->update([
            'status' => PasswordResetRequest::STATUS_DISMISSED,
            'handled_by' => $admin->id,
            'handled_at' => now(),
        ]);
    }

    public function pruneExpired(): int
    {
        $days = (int) config('inventory.password_reset_request_retention_days', 30);
        $cutoff = now()->subDays($days);

        return PasswordResetRequest::query()
            ->where('created_at', '<', $cutoff)
            ->delete();
    }

    protected function notifySystemAdmins(User $requestingUser): void
    {
        $notification = new PasswordResetRequestDatabaseNotification(
            $requestingUser->name,
            $requestingUser->email,
            $requestingUser,
        );

        User::query()
            ->where('role', User::ROLE_SYSTEM_ADMIN)
            ->each(function (User $admin) use ($notification): void {
                $admin->notify($notification);
            });
    }
}
