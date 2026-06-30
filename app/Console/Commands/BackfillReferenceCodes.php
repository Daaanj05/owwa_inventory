<?php

namespace App\Console\Commands;

use App\Models\Issuance;
use App\Models\Requisition;
use App\Models\Transfer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class BackfillReferenceCodes extends Command
{
    protected $signature = 'app:backfill-reference-codes
        {--apply : Persist changes (default is dry-run)}
        {--types=requisition,issuance,transfer : Comma-separated transaction types to process}
        {--limit=0 : Optional max records per type (0 = no limit)}';

    protected $description = 'Backfill legacy reference codes to OWWA control-number format 0000-00-0000.';

    public function handle(): int
    {
        $types = $this->parseTypes((string) $this->option('types'));
        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');

        $modelMap = [
            'requisition' => [
                'model' => Requisition::class,
                'date' => 'created_at',
            ],
            'issuance' => [
                'model' => Issuance::class,
                'date' => 'issuance_date',
            ],
            'transfer' => [
                'model' => Transfer::class,
                'date' => 'transfer_date',
            ],
        ];

        $invalidTypes = array_values(array_diff($types, array_keys($modelMap)));
        if ($invalidTypes !== []) {
            $this->error('Unsupported type(s): '.implode(', ', $invalidTypes));

            return self::FAILURE;
        }

        $this->info($apply ? 'Applying reference code backfill...' : 'Dry-run reference code backfill...');

        foreach ($types as $type) {
            /** @var class-string<\Illuminate\Database\Eloquent\Model> $model */
            $model = $modelMap[$type]['model'];
            $dateColumn = $modelMap[$type]['date'];

            $query = $model::query()
                ->orderBy($dateColumn)
                ->orderBy('id');
            if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
                $query->withTrashed();
            }

            if ($limit > 0) {
                $query->limit($limit);
            }

            $records = $query->get();
            $existingCodes = $records
                ->pluck('reference_code')
                ->filter(fn ($code): bool => is_string($code) && $code !== '')
                ->flip()
                ->all();

            $changed = 0;
            foreach ($records as $record) {
                /** @var \Illuminate\Database\Eloquent\Model $record */
                $current = (string) ($record->reference_code ?? '');
                if ($this->isControlNumber($current)) {
                    continue;
                }

                $dateValue = $record->{$dateColumn};
                $useMonthly = $type === 'issuance';
                $nextCode = $this->nextUniqueControlNumber(
                    $dateValue?->format('Y-m-d'),
                    $useMonthly,
                    $existingCodes
                );

                $this->line(sprintf('[%s #%d] %s -> %s', $type, (int) $record->getKey(), $current !== '' ? $current : '(empty)', $nextCode));

                if ($apply) {
                    $record->reference_code = $nextCode;
                    $record->save();
                }

                $existingCodes[$nextCode] = true;
                $changed++;
            }

            $this->info(sprintf('%s: %d updated%s', $type, $changed, $apply ? '' : ' (dry-run)'));
        }

        $this->newLine();
        $this->info($apply ? 'Backfill completed.' : 'Dry-run complete. Re-run with --apply to save changes.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    protected function parseTypes(string $types): array
    {
        return array_values(array_filter(array_map('trim', explode(',', strtolower($types)))));
    }

    protected function isControlNumber(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{4}$/', strtoupper(trim($value))) === 1;
    }

    /**
     * @param  array<string, bool>  $existingCodes
     */
    protected function nextUniqueControlNumber(?string $date, bool $monthlySeries, array $existingCodes): string
    {
        $dateObj = filled($date) ? Carbon::parse($date) : now();
        $year = $dateObj->format('Y');
        $middle = $monthlySeries ? $dateObj->format('m') : '01';

        for ($serial = 1; $serial <= 9999; $serial++) {
            $code = sprintf('%s-%s-%04d', $year, $middle, $serial);
            if (! isset($existingCodes[$code])) {
                return $code;
            }
        }

        throw new \RuntimeException("Unable to allocate unique control number for {$year}-{$middle}.");
    }
}
