<?php

namespace App\Services;

use App\Models\Department;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DepartmentBulkCreateService
{
    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return Collection<int, Department>
     */
    public function createForOffice(int $officeId, array $lines): Collection
    {
        if ($officeId <= 0) {
            throw ValidationException::withMessages([
                'office_id' => 'Select an office.',
            ]);
        }

        $normalized = $this->normalizeLines($lines);
        $this->validateLines($officeId, $normalized);

        return DB::transaction(function () use ($officeId, $normalized): Collection {
            $created = new Collection;

            foreach ($normalized as $line) {
                $created->push(Department::query()->create([
                    'office_id' => $officeId,
                    'name' => $line['name'],
                    'code' => $line['code'],
                ]));
            }

            return $created;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array{name: string, code: ?string}>
     */
    public function normalizeLines(array $lines): array
    {
        $normalized = [];

        foreach ($lines as $line) {
            $name = trim((string) ($line['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $code = trim((string) ($line['code'] ?? ''));

            $normalized[] = [
                'name' => $name,
                'code' => $code !== '' ? $code : null,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array{name: string, code: ?string}>  $lines
     */
    protected function validateLines(int $officeId, array $lines): void
    {
        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'Add at least one sub-office/department name.',
            ]);
        }

        $errors = [];
        $seen = [];

        foreach ($lines as $index => $line) {
            $key = Str::lower($line['name']);

            if (isset($seen[$key])) {
                $errors["lines.{$index}.name"] = 'This name is duplicated in the form.';
            }

            $seen[$key] = true;
        }

        $existingNames = Department::query()
            ->where('office_id', $officeId)
            ->pluck('name')
            ->map(fn (string $name): string => Str::lower(trim($name)))
            ->all();

        $existingLookup = array_fill_keys($existingNames, true);

        foreach ($lines as $index => $line) {
            if (isset($existingLookup[Str::lower($line['name'])])) {
                $errors["lines.{$index}.name"] = 'A sub-office/department with this name already exists in this office.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
