<?php

namespace App\Providers;

use App\Listeners\BroadcastDatabaseNotificationsSent;
use App\Listeners\EnsureDatabaseIsHealthy;
use App\Listeners\LogFailedLogin;
use App\Listeners\LogUserLogin;
use App\Listeners\LogUserLogout;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Notifications\Events\NotificationSent;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        Login::class => [
            LogUserLogin::class,
        ],
        Logout::class => [
            LogUserLogout::class,
        ],
        Failed::class => [
            LogFailedLogin::class,
        ],
        NotificationSent::class => [
            BroadcastDatabaseNotificationsSent::class,
        ],
        DiagnosingHealth::class => [
            EnsureDatabaseIsHealthy::class,
        ],
    ];
}
