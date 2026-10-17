<x-mail::message :logo-message="$message ?? null">
# Welcome, {{ $user->name }}

Your account for the **{{ config('owwa_mail.brand_name') }}** has been created.

Use the button below to set up your account. You will confirm your email, enter a verification code we send next, then choose your password.

<x-mail::button :url="$setupUrl">
Set up your account
</x-mail::button>

After setup, sign in and enable multi-factor authentication before using the system.

**Sign-in page:** [{{ $panelLoginUrl }}]({{ $panelLoginUrl }})

Thanks,<br>
{{ config('owwa_mail.brand_name') }}
</x-mail::message>
