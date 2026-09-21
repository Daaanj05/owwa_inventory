<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DeliveryTerm extends Model
{
    use LogsUserActivity;

    protected $fillable = [
        'label',
        'is_active',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function archive(): void
    {
        if ($this->isArchived()) {
            return;
        }

        $this->update([
            'archived_at' => now(),
            'is_active' => false,
        ]);
    }

    public function restoreFromArchive(): void
    {
        if (! $this->isArchived()) {
            return;
        }

        $this->update([
            'archived_at' => null,
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return static::query()
            ->active()
            ->orderBy('label')
            ->pluck('label', 'label')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function optionsIncluding(mixed $current): array
    {
        $options = static::options();
        if (filled($current) && ! array_key_exists((string) $current, $options)) {
            $options[(string) $current] = (string) $current;
        }

        return $options;
    }

    public static function remember(?string $label): void
    {
        $normalized = trim((string) $label);
        if ($normalized === '') {
            return;
        }

        /** @var self $record */
        $record = static::query()->firstOrNew([
            'label' => $normalized,
        ]);

        if ($record->exists && $record->isArchived()) {
            $record->restoreFromArchive();

            return;
        }

        if (! $record->exists) {
            $record->is_active = true;
            $record->save();
        }
    }
}
