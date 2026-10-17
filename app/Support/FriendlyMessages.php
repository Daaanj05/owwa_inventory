<?php

namespace App\Support;

final class FriendlyMessages
{
    public static function welcomeEmailSent(string $email): string
    {
        return sprintf(
            'Setup email sent to %s. They can open the link to verify their email and choose a password.',
            $email,
        );
    }

    public static function welcomeEmailQueued(string $email): string
    {
        return sprintf(
            'User saved. Setup email is queued for %s and will arrive shortly when the mail worker is running.',
            $email,
        );
    }

    public static function welcomeEmailFailed(string $email): string
    {
        return sprintf(
            'User saved, but we could not send the setup email to %s right now. Ask your administrator if email delivery is unavailable, then use Resend setup email.',
            $email,
        );
    }

    public static function verificationResendSent(string $email): string
    {
        return sprintf('A new setup email was sent to %s.', $email);
    }

    public static function verificationResendQueued(string $email): string
    {
        return sprintf(
            'Setup email queued for %s. It will arrive shortly when the mail worker is running.',
            $email,
        );
    }

    public static function verificationResendFailed(): string
    {
        return 'We could not send the setup email right now. The website may be online, but outbound email is unavailable. Ask your administrator to start the mail worker.';
    }

    public static function setupCodeSent(): string
    {
        return 'If that email has a pending account setup, we sent a verification code.';
    }

    public static function accountSetupSuccess(): string
    {
        return 'Your email is verified and your password is set. Enable multi-factor authentication to continue.';
    }

    public static function emailVerificationSuccess(): string
    {
        return self::accountSetupSuccess();
    }

    public static function passwordResetCodeSent(): string
    {
        return 'If an account exists for that email, we sent a password reset code.';
    }

    public static function passwordResetEmailSent(string $email): string
    {
        return sprintf('A password reset code was sent to %s.', $email);
    }

    public static function passwordResetEmailQueued(string $email): string
    {
        return sprintf(
            'Password reset email queued for %s. It will arrive shortly when the mail worker is running.',
            $email,
        );
    }

    public static function passwordResetEmailFailed(): string
    {
        return 'We could not send the password reset email right now. The website may be online, but outbound email is unavailable. Ask your administrator to start the mail worker or try again later.';
    }

    public static function adminEmailChangeVerificationSent(string $email): string
    {
        return sprintf(
            'A setup email was sent to %s. The account cannot sign in until that address is set up again.',
            $email,
        );
    }

    public static function adminEmailChangeVerificationQueued(string $email): string
    {
        return sprintf(
            'A setup email is queued for %s. The account cannot sign in until that address is set up again.',
            $email,
        );
    }

    public static function adminEmailChangeVerificationFailed(string $email): string
    {
        return sprintf(
            'The sign-in email is now %s, but we could not send the setup email. Use Resend setup email when outbound mail is available.',
            $email,
        );
    }

    public static function adminEmailEditHelper(): string
    {
        return 'This is the sign-in email. It must be verified before login. Changing it requires a new setup email and signs the person out of existing sessions.';
    }

    public static function emailNotVerifiedLogin(?string $setupUrl = null): string
    {
        $message = 'Please finish setting up your account before signing in. Open the setup link from your email, or ask your administrator to resend it.';

        if (filled($setupUrl)) {
            return $message.' Setup page: '.$setupUrl;
        }

        return $message;
    }

    public static function emailVerificationInvalidCode(): string
    {
        return 'This verification code is invalid or has expired. Go back and request a new code.';
    }

    public static function emailVerificationInvalidLink(): string
    {
        return self::emailVerificationInvalidCode();
    }

    public static function emailVerificationExpiredLink(): string
    {
        return self::emailVerificationInvalidCode();
    }

    public static function passwordResetInvalidCode(): string
    {
        return 'This password reset code is invalid or has expired. Request a new code from Forgot password.';
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
