<?php

namespace App\Http\Concerns;

use App\Services\UserActivityLogger;
use Illuminate\Database\Eloquent\Model;

trait LogsExportActivity
{
    protected function logExportActivity(string $summary, ?Model $subject = null, array $properties = []): void
    {
        app(UserActivityLogger::class)->recordExport($summary, $subject, $properties);
    }
}
