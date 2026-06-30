<x-mail::message>
# Welcome, {{ $user->name }}

Your account for the **OWWA Region IV-A Inventory System** has been created.

<x-mail::button :url="$verificationUrl">
Verify email address
</x-mail::button>

Please verify your email address using the button above before signing in.

<x-mail::panel>
**Sign-in details**

**Login URL:** [{{ $panelLoginUrl }}]({{ $panelLoginUrl }})

**Temporary password:** {{ $temporaryPassword }}
</x-mail::panel>

After your first login, you will be asked to choose a new password before using the system. You can change it again anytime from **Profile** in the user menu.

Thanks,<br>
{{ config('owwa_mail.brand_name') }}
</x-mail::message>
