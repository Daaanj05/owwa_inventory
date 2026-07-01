<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->append(\App\Http\Middleware\LogSlowRequests::class);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('sessions:close-stale')->everyFiveMinutes();
        $schedule->command('audit:archive-old-logs')->daily();
        $schedule->command('password-reset-requests:prune')->daily();
        $schedule->command('inventory:send-plan-reminders')->dailyAt('08:00');
        $schedule->command('inventory:eul-reminders')->dailyAt('08:30');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Routing\Exceptions\InvalidSignatureException $exception, \Illuminate\Http\Request $request) {
            return redirect()
                ->to('/admin/login')
                ->with('verification_error', \App\Support\FriendlyMessages::emailVerificationExpiredLink());
        });
    })->create();
