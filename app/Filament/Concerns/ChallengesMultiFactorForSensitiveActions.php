<?php

namespace App\Filament\Concerns;

use App\Models\User;
use Filament\Auth\MultiFactor\Contracts\HasBeforeChallengeHook;
use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

trait ChallengesMultiFactorForSensitiveActions
{
    /**
     * @return array<Component>
     */
    protected function multiFactorChallengeFormComponents(User $user): array
    {
        $enabledProviders = array_values(array_filter(
            Filament::getMultiFactorAuthenticationProviders(),
            fn (MultiFactorAuthenticationProvider $provider): bool => $provider->isEnabled($user),
        ));

        if ($enabledProviders === []) {
            return [];
        }

        $components = [];

        if (count($enabledProviders) > 1) {
            $components[] = Radio::make('multiFactorProvider')
                ->label('Verification method')
                ->options(collect($enabledProviders)
                    ->mapWithKeys(fn (MultiFactorAuthenticationProvider $provider): array => [
                        $provider->getId() => $provider->getLoginFormLabel(),
                    ])
                    ->all())
                ->default($enabledProviders[0]->getId())
                ->required()
                ->live();
        }

        foreach ($enabledProviders as $provider) {
            $components[] = Group::make($provider->getChallengeFormComponents($user))
                ->statePath($provider->getId())
                ->when(
                    count($enabledProviders) > 1,
                    fn (Group $group): Group => $group->visible(
                        fn (Get $get): bool => $get('multiFactorProvider') === $provider->getId()
                    ),
                );
        }

        return $components;
    }

    protected function prepareMultiFactorChallenge(User $user): void
    {
        foreach (Filament::getMultiFactorAuthenticationProviders() as $provider) {
            if (! $provider->isEnabled($user)) {
                continue;
            }

            if ($provider instanceof HasBeforeChallengeHook) {
                $provider->beforeChallenge($user);
            }

            break;
        }
    }

    protected function hitMultiFactorChallengeRateLimiter(User $user): void
    {
        $rateLimitingKey = 'filament-multi-factor-challenge:'.$user->getAuthIdentifier();

        if (RateLimiter::tooManyAttempts($rateLimitingKey, maxAttempts: 5)) {
            throw ValidationException::withMessages([
                'data.password' => 'Too many verification attempts. Please wait and try again.',
            ]);
        }

        RateLimiter::hit($rateLimitingKey);
    }

    protected function userHasEnabledMultiFactorProvider(User $user): bool
    {
        foreach (Filament::getMultiFactorAuthenticationProviders() as $provider) {
            if ($provider->isEnabled($user)) {
                return true;
            }
        }

        return false;
    }
}
