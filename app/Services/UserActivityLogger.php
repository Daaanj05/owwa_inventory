<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserActivityLog;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class UserActivityLogger
{
    /** @var list<string> */
    protected array $sensitiveAttributes = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    public function record(
        ?User $user,
        string $action,
        string $summary,
        ?Model $subject = null,
        array $properties = [],
    ): ?UserActivityLog {
        if ($summary === '') {
            return null;
        }

        $userLogId = session('audit_user_log_id');

        return UserActivityLog::query()->create([
            'user_id' => $user?->id,
            'user_log_id' => is_numeric($userLogId) ? (int) $userLogId : null,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'summary' => Str::limit($summary, 500),
            'properties' => $properties === [] ? null : $properties,
            'ip_address' => request()->ip(),
            'panel' => Filament::getCurrentPanel()?->getId() ?? Filament::getCurrentOrDefaultPanel()?->getId(),
        ]);
    }

    public function recordForAuthenticated(
        string $action,
        string $summary,
        ?Model $subject = null,
        array $properties = [],
    ): ?UserActivityLog {
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        return $this->record($user, $action, $summary, $subject, $properties);
    }

    public function recordGuest(
        string $action,
        string $summary,
        array $properties = [],
    ): ?UserActivityLog {
        return $this->record(null, $action, $summary, null, $properties);
    }

    public function recordExport(string $summary, ?Model $subject = null, array $properties = []): ?UserActivityLog
    {
        return $this->recordForAuthenticated('exported', $summary, $subject, $properties);
    }

    public function recordWorkflow(
        string $action,
        string $summary,
        ?Model $subject = null,
        array $properties = [],
    ): ?UserActivityLog {
        return $this->recordForAuthenticated($action, $summary, $subject, $properties);
    }

    public function recordModelEvent(Model $model, string $event): ?UserActivityLog
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        $action = $this->resolveModelAction($model, $event);
        $summary = $this->buildModelSummary($model, $action);

        $properties = [];

        if ($event === 'updated' && $model->wasChanged()) {
            $properties = $this->buildAttributeDiff($model);
        }

        return $this->record($user, $action, $summary, $model, $properties);
    }

    /**
     * @return array{changed: list<string>, old?: array<string, mixed>, new?: array<string, mixed>}
     */
    protected function buildAttributeDiff(Model $model): array
    {
        $changes = $model->getChanges();
        $changedKeys = array_values(array_filter(
            array_keys($changes),
            fn (string $key): bool => ! in_array($key, $this->sensitiveAttributes, true) && $key !== 'updated_at',
        ));

        $old = [];
        $new = [];

        foreach ($changedKeys as $key) {
            $old[$key] = $model->getOriginal($key);
            $new[$key] = $changes[$key] ?? $model->getAttribute($key);
        }

        return [
            'changed' => $changedKeys,
            'old' => $old,
            'new' => $new,
        ];
    }

    protected function resolveModelAction(Model $model, string $event): string
    {
        if ($event !== 'updated' || ! $model->wasChanged()) {
            return $event;
        }

        if ($model->wasChanged('status')) {
            $status = (string) $model->getAttribute('status');

            return match ($status) {
                'approved', 'accepted' => 'approved',
                'rejected' => 'rejected',
                'fulfilled', 'issued' => 'fulfilled',
                'archived' => 'archived',
                default => 'status_changed',
            };
        }

        if ($model->wasChanged('archived_at')) {
            return $model->getAttribute('archived_at') !== null ? 'archived' : 'restored';
        }

        return 'updated';
    }

    protected function buildModelSummary(Model $model, string $action): string
    {
        $label = $this->modelLabel($model);
        $reference = $this->modelReference($model);

        $verb = match ($action) {
            'created' => 'Created',
            'updated' => 'Updated',
            'deleted' => 'Deleted',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'fulfilled' => 'Fulfilled',
            'archived' => 'Archived',
            'restored' => 'Restored',
            'status_changed' => 'Changed status of',
            default => ucfirst(str_replace('_', ' ', $action)),
        };

        if ($reference !== null && $reference !== '') {
            return "{$verb} {$label} {$reference}";
        }

        return "{$verb} {$label}";
    }

    protected function modelLabel(Model $model): string
    {
        return Str::headline(class_basename($model));
    }

    protected function modelReference(Model $model): ?string
    {
        foreach (['reference_code', 'item_code', 'name', 'email'] as $attribute) {
            if (! empty($model->getAttribute($attribute))) {
                return (string) $model->getAttribute($attribute);
            }
        }

        $key = $model->getKey();

        return $key !== null ? '#'.$key : null;
    }
}
