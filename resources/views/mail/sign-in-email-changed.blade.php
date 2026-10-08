<x-mail::message>
# Sign-in email changed

Hello {{ $userName }},

A System Admin changed the sign-in email for your **OWWA Region IV-A Inventory System** account.

The new sign-in email is:

**{{ $newEmail }}**

If you still need access, use that address after it has been verified. If you did not expect this change, contact your System Admin right away.

Thanks,<br>
{{ config('owwa_mail.brand_name') }}
</x-mail::message>
