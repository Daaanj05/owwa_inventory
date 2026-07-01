<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\FriendlyMessages;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GuestEmailVerificationController extends Controller
{
    public function __invoke(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::query()->find($id);

        if ($user === null) {
            return $this->redirectWithVerificationError(FriendlyMessages::emailVerificationInvalidLink());
        }

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return $this->redirectWithVerificationError(FriendlyMessages::emailVerificationInvalidLink());
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();

            event(new Verified($user));
        }

        return redirect()
            ->to(User::panelLoginUrlFor($user))
            ->with('status', 'email-verified');
    }

    protected function redirectWithVerificationError(string $message): RedirectResponse
    {
        return redirect()
            ->to('/admin/login')
            ->with('verification_error', $message);
    }
}
