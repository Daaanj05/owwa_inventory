<?php

namespace App\Http\Middleware;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;
use Illuminate\Http\Request;

/**
 * Livewire sends _token from the page meta tag. That meta can go stale while the
 * session (and XSRF-TOKEN cookie) stay valid — e.g. another tab, SPA navigate, or
 * a response that refreshed the cookie. Accept any matching candidate so interactive
 * requests do not 419 while the user is still logged in.
 */
class VerifyCsrfToken extends Middleware
{
    protected function tokensMatch($request): bool
    {
        $sessionToken = $request->session()->token();

        if (! is_string($sessionToken) || $sessionToken === '') {
            return false;
        }

        foreach ($this->requestTokens($request) as $token) {
            if (is_string($token) && $token !== '' && hash_equals($sessionToken, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    protected function requestTokens(Request $request): array
    {
        $tokens = [];

        foreach ([
            $request->input('_token'),
            $request->header('X-CSRF-TOKEN'),
        ] as $value) {
            if (is_string($value) && $value !== '') {
                $tokens[] = $value;
            }
        }

        foreach ([
            $request->header('X-XSRF-TOKEN'),
            $request->cookie('XSRF-TOKEN'),
        ] as $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            // Cookie may already be decrypted by EncryptCookies (plaintext),
            // or still encrypted when read from the X-XSRF-TOKEN header.
            $tokens[] = $value;

            try {
                $tokens[] = CookieValuePrefix::remove(
                    $this->encrypter->decrypt($value, static::serialized())
                );
            } catch (DecryptException) {
                // Not an encrypted payload — plaintext candidate already added.
            }
        }

        return array_values(array_unique($tokens));
    }
}
