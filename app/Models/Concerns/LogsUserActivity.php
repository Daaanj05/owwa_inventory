<?php

namespace App\Models\Concerns;

use App\Services\UserActivityLogger;
use Illuminate\Database\Eloquent\Model;

trait LogsUserActivity
{
    public static function bootLogsUserActivity(): void
    {
        static::created(function (Model $model): void {
            app(UserActivityLogger::class)->recordModelEvent($model, 'created');
        });

        static::updated(function (Model $model): void {
            if (! $model->wasChanged()) {
                return;
            }

            if (! static::shouldLogUserActivityUpdate($model)) {
                return;
            }

            app(UserActivityLogger::class)->recordModelEvent($model, 'updated');
        });

        static::deleted(function (Model $model): void {
            app(UserActivityLogger::class)->recordModelEvent($model, 'deleted');
        });
    }

    protected static function shouldLogUserActivityUpdate(Model $model): bool
    {
        $ignored = ['updated_at', 'last_activity_at', 'remember_token'];

        $changed = array_diff(array_keys($model->getChanges()), $ignored);

        return $changed !== [];
    }
}
