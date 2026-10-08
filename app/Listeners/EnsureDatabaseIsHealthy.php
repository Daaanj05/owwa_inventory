<?php

namespace App\Listeners;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;

class EnsureDatabaseIsHealthy
{
    public function handle(DiagnosingHealth $event): void
    {
        DB::connection()->getPdo();
        DB::select('select 1');
    }
}
