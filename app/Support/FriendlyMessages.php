<?php

namespace App\Support;

final class FriendlyMessages
{
    public static function welcomeEmailSent(string $email, string $temporaryPassword): string
    {
        return sprintf(
            'Welcome email sent to %s. Temporary password (backup): %s',
            $email,
            $temporaryPassword,
        );
    }

    public static function welcomeEmailQueued(string $email, string $temporaryPassword): string
    {
        return sprintf(
            'User saved. Welcome email is queued for %s and will arrive shortly when the mail worker is running. Temporary password (backup): %s',
            $email,
            $temporaryPassword,
        );
    }

    public static function welcomeEmailFailed(string $email, string $temporaryPassword): string
    {
        return sprintf(
            'User saved, but we could not send the welcome email right now. Share these credentials manually — Email: %s · Temporary password: %s · Ask your administrator if email delivery is unavailable.',
            $email,
            $temporaryPassword,
        );
    }

    public static function verificationResendSent(string $email): string
    {
        return sprintf('A new verification link was sent to %s.', $email);
    }

    public static function verificationResendQueued(string $email): string
    {
        return sprintf(
            'Verification email queued for %s. It will arrive shortly when the mail worker is running.',
            $email,
        );
    }

    public static function verificationResendFailed(): string
    {
        return 'We could not send the verification email right now. The website may be online, but outbound email is unavailable. Ask your administrator to start the mail worker or share a verification link manually.';
    }

    public static function emailNotVerifiedLogin(): string
    {
        return 'Please verify your email address before signing in. Check your inbox for the verification link, or ask your administrator to resend it.';
    }

    public static function emailVerificationSuccess(): string
    {
        return 'Your email has been verified. You can sign in now.';
    }

    public static function emailVerificationInvalidLink(): string
    {
        return 'This verification link is invalid. Ask your administrator to resend a new verification email.';
    }

    public static function emailVerificationExpiredLink(): string
    {
        return 'This verification link has expired. Ask your administrator to resend a new verification email.';
    }

    public static function websiteUnavailable503(): string
    {
        return 'The OWWA Inventory System is temporarily unavailable. The service may be starting up, undergoing maintenance, or the hosting platform may be waking from sleep. Please wait a minute and try again.';
    }

    public static function websiteError500(): string
    {
        return 'Something went wrong on our side. Please try again in a few minutes. If the problem continues, contact your system administrator.';
    }

    public static function pageNotFound404(): string
    {
        return 'The page you requested could not be found. Check the address or return to the login page.';
    }

    public static function serviceUnavailableHint(): string
    {
        return 'If you are testing during capstone deployment, the app may be online on Render while email is processed separately. Ask the administrator to confirm the mail worker is running.';
    }
}
