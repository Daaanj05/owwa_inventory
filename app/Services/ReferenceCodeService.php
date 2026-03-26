<?php

namespace App\Services;

use App\Models\ReferenceSeries;
use Illuminate\Support\Facades\DB;

class ReferenceCodeService
{
    public function forAcquisition(): string
    {
        return $this->nextCode(ReferenceSeries::typeForAcquisition());
    }

    public function forIssuance(): string
    {
        return $this->nextCode(ReferenceSeries::typeForIssuance());
    }

    public function forTransfer(): string
    {
        return $this->nextCode(ReferenceSeries::typeForTransfer());
    }

    public function forDisposal(): string
    {
        return $this->nextCode(ReferenceSeries::typeForDisposal());
    }

    public function forRequisition(): string
    {
        return $this->nextCode(ReferenceSeries::typeForRequisition());
    }

    public function nextCode(string $type): string
    {
        return DB::transaction(function () use ($type): string {
            $series = ReferenceSeries::where('type', $type)->lockForUpdate()->first();

            if (! $series) {
                throw new \RuntimeException("Reference series not found for type: {$type}. Run the reference series seeder.");
            }

            $this->maybeResetSequence($series);

            $code = $this->expandPattern($series);
            $series->next_sequence++;
            $series->last_generated_at = now()->toDateString();
            $series->save();

            return $code;
        });
    }

    protected function maybeResetSequence(ReferenceSeries $series): void
    {
        $last = $series->last_generated_at;
        $now = now();

        $shouldReset = match ($series->reset_period) {
            ReferenceSeries::RESET_DAILY => $last === null || $last->format('Y-m-d') < $now->format('Y-m-d'),
            ReferenceSeries::RESET_MONTHLY => $last === null || $last->format('Y-m') < $now->format('Y-m'),
            ReferenceSeries::RESET_YEARLY => $last === null || $last->format('Y') < $now->format('Y'),
            default => false,
        };

        if ($shouldReset) {
            $series->next_sequence = 1;
        }
    }

    protected function expandPattern(ReferenceSeries $series): string
    {
        $pattern = $series->pattern;
        $seq = $series->next_sequence;

        $replacements = [
            '{prefix}' => $series->prefix,
            '{Y}' => now()->format('Y'),
            '{m}' => now()->format('m'),
            '{d}' => now()->format('d'),
        ];

        foreach ($replacements as $placeholder => $value) {
            $pattern = str_replace($placeholder, $value, $pattern);
        }

        if (preg_match('/\{seq:(\d+)\}/', $pattern, $m)) {
            $pad = (int) $m[1];
            $pattern = preg_replace('/\{seq:\d+\}/', str_pad((string) $seq, $pad, '0', STR_PAD_LEFT), $pattern, 1);
        } else {
            $pattern = str_replace('{seq}', (string) $seq, $pattern);
        }

        return $pattern;
    }

    public function previewNext(string $type): string
    {
        $series = ReferenceSeries::where('type', $type)->first();

        if (! $series) {
            return '';
        }

        $clone = clone $series;
        $this->maybeResetSequence($clone);

        return $this->expandPattern($clone);
    }
}
