@props([
    'label' => '',
    'name' => '',
    'type' => 'text',
    'placeholder' => "\u{200B}",
    'revealable' => false,
])

@php
    $hasError = $errors->has($name);
    $isPassword = $type === 'password';
@endphp

<fieldset
    class="owwa-login-outlined-fieldset {{ $hasError ? 'owwa-login-outlined-fieldset--error' : '' }} {{ $isPassword ? 'owwa-login-outlined-fieldset--password' : '' }}"
    @if ($isPassword && $revealable) x-data="{ revealed: false }" @endif
>
    <legend class="owwa-login-outlined-legend">{{ $label }}</legend>
    <div class="owwa-login-outlined-input-wrap">
        <input
            @if ($isPassword && $revealable)
                type="password"
                x-bind:type="revealed ? 'text' : 'password'"
            @else
                type="{{ $type }}"
            @endif
            name="{{ $name }}"
            wire:model="{{ $name }}"
            placeholder="{{ $placeholder }}"
            autocomplete="{{ $isPassword ? 'current-password' : 'email' }}"
            class="owwa-login-outlined-input"
            {{ $attributes->except('class') }}
        />
        @if ($isPassword && $revealable)
            <button
                type="button"
                class="owwa-login-password-reveal"
                tabindex="-1"
                aria-label="Toggle password visibility"
                @click="revealed = !revealed"
            >
                <span x-show="!revealed" class="owwa-login-password-reveal-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                </span>
                <span x-show="revealed" class="owwa-login-password-reveal-icon" x-cloak aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" /></svg>
                </span>
            </button>
        @endif
    </div>
</fieldset>

@error($name)
    <p class="owwa-login-outlined-error">{{ $message }}</p>
@enderror
