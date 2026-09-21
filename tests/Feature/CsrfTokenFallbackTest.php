<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use ReflectionMethod;
use Tests\TestCase;

class CsrfTokenFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_body_token_is_accepted_when_xsrf_cookie_matches_session(): void
    {
        /** @var Store $session */
        $session = $this->app->make('session.store');
        $session->start();

        $validToken = $session->token();

        $request = Request::create('/livewire/update', 'POST', [
            '_token' => 'stale-meta-token',
        ]);
        $request->setLaravelSession($session);
        $request->cookies->set('XSRF-TOKEN', $validToken);

        $middleware = $this->app->make(VerifyCsrfToken::class);
        $method = new ReflectionMethod(VerifyCsrfToken::class, 'tokensMatch');

        $this->assertTrue($method->invoke($middleware, $request));
    }

    public function test_mismatch_fails_when_no_candidate_matches_session(): void
    {
        /** @var Store $session */
        $session = $this->app->make('session.store');
        $session->start();

        $request = Request::create('/livewire/update', 'POST', [
            '_token' => 'stale-meta-token',
        ]);
        $request->setLaravelSession($session);
        $request->cookies->set('XSRF-TOKEN', 'also-wrong');

        $middleware = $this->app->make(VerifyCsrfToken::class);
        $method = new ReflectionMethod(VerifyCsrfToken::class, 'tokensMatch');

        $this->assertFalse($method->invoke($middleware, $request));
    }

    public function test_get_idle_logout_redirects_to_login_instead_of_419_page(): void
    {
        $this->get('/audit/idle-logout')
            ->assertRedirect(url('/login').'?reauth=1');
    }
}
