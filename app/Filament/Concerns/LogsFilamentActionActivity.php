<?php

namespace App\Filament\Concerns;

use App\Services\UserActivityLogger;
use Illuminate\Database\Eloquent\Model;

trait LogsFilamentActionActivity
{
    protected function logFilamentAction(
        string $actionName,
        string $summary,
        ?Model $subject = null,
        array $properties = [],
    ): void {
        app(UserActivityLogger::class)->recordForAuthenticated(
            'action_'.$actionName,
            $summary,
            $subject,
            array_merge(['filament_action' => $actionName], $properties),
        );
    }
}
