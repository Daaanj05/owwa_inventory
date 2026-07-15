<?php

use App\Models\ReferenceSeries;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('issuance_batches')) {
            Schema::create('issuance_batches', function (Blueprint $table): void {
                $table->id();
                $table->string('category_slug', 32);
                $table->string('reference_code');
                $table->foreignId('requisition_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('office_id')->constrained()->cascadeOnDelete();
                $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
                $table->date('issuance_date');
                $table->text('remarks')->nullable();
                $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('issued_to')->nullable()->constrained('users')->nullOnDelete();
                $table->string('custodian_printed_name')->nullable();
                $table->string('custodian_designation')->nullable();
                $table->string('issued_to_designation')->nullable();
                $table->string('accounting_staff_printed_name')->nullable();
                $table->string('received_from_name')->nullable();
                $table->timestamps();

                $table->index('category_slug');
                $table->index('issuance_date');
                $table->unique(['category_slug', 'reference_code']);
            });
        }

        if (! Schema::hasColumn('issuances', 'issuance_batch_id')) {
            Schema::table('issuances', function (Blueprint $table): void {
                $table->foreignId('issuance_batch_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('issuance_batches')
                    ->nullOnDelete();
            });
        }

        if (DB::table('issuance_batches')->count() === 0) {
            $this->backfillIssuanceBatches();
        }

        $this->dropIssuanceReferenceCodeUniqueIfPresent();

        $this->ensureIssuanceBatchCompositeUnique();

        $this->seedPerCategoryIssuanceSeries();
    }

    protected function ensureIssuanceBatchCompositeUnique(): void
    {
        try {
            Schema::table('issuance_batches', function (Blueprint $table): void {
                $table->dropUnique(['reference_code']);
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('issuance_batches', function (Blueprint $table): void {
                $table->unique(['category_slug', 'reference_code']);
            });
        } catch (\Throwable) {
        }
    }

    protected function dropIssuanceReferenceCodeUniqueIfPresent(): void
    {
        try {
            Schema::table('issuances', function (Blueprint $table): void {
                $table->dropUnique(['reference_code']);
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('issuances', function (Blueprint $table): void {
                $table->index('reference_code');
            });
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        Schema::table('issuances', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('issuance_batch_id');
            $table->dropIndex(['reference_code']);
            $table->unique('reference_code');
        });

        Schema::dropIfExists('issuance_batches');

        ReferenceSeries::query()
            ->whereIn('type', [
                ReferenceSeries::TYPE_ISSUANCE_CONSUMABLES,
                ReferenceSeries::TYPE_ISSUANCE_SEMI,
                ReferenceSeries::TYPE_ISSUANCE_PPE,
            ])
            ->delete();
    }

    protected function backfillIssuanceBatches(): void
    {
        $categoryNamesById = DB::table('item_categories')
            ->pluck('name', 'id');

        $issuances = DB::table('issuances')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        foreach ($issuances as $issuance) {
            $itemCategoryId = DB::table('items')
                ->where('id', $issuance->item_id)
                ->value('item_category_id');

            $categoryName = $categoryNamesById[$itemCategoryId] ?? null;
            $categorySlug = $this->resolveCategorySlug($categoryName);

            $batchId = DB::table('issuance_batches')->insertGetId([
                'category_slug' => $categorySlug,
                'reference_code' => $issuance->reference_code,
                'requisition_id' => $issuance->requisition_id,
                'office_id' => $issuance->office_id,
                'department_id' => $issuance->department_id,
                'issuance_date' => $issuance->issuance_date,
                'remarks' => $issuance->remarks,
                'issued_by' => $issuance->issued_by,
                'issued_to' => $issuance->issued_to,
                'custodian_printed_name' => $issuance->custodian_printed_name ?? null,
                'custodian_designation' => $issuance->custodian_designation ?? null,
                'issued_to_designation' => $issuance->issued_to_designation ?? null,
                'accounting_staff_printed_name' => $issuance->accounting_staff_printed_name ?? null,
                'received_from_name' => $issuance->received_from_name ?? null,
                'created_at' => $issuance->created_at,
                'updated_at' => $issuance->updated_at,
            ]);

            DB::table('issuances')
                ->where('id', $issuance->id)
                ->update(['issuance_batch_id' => $batchId]);
        }
    }

    protected function seedPerCategoryIssuanceSeries(): void
    {
        $legacy = ReferenceSeries::query()
            ->where('type', ReferenceSeries::TYPE_ISSUANCE)
            ->first();

        $defaults = [
            [
                'type' => ReferenceSeries::TYPE_ISSUANCE_CONSUMABLES,
                'name' => 'Issuance control no. (RSMI Serial — consumables)',
                'prefix' => 'RSMI',
            ],
            [
                'type' => ReferenceSeries::TYPE_ISSUANCE_SEMI,
                'name' => 'Issuance control no. (ICS — semi-expendable)',
                'prefix' => 'ICS',
            ],
            [
                'type' => ReferenceSeries::TYPE_ISSUANCE_PPE,
                'name' => 'Issuance control no. (PAR — PPE)',
                'prefix' => 'PAR',
            ],
        ];

        foreach ($defaults as $row) {
            ReferenceSeries::query()->updateOrCreate(
                ['type' => $row['type']],
                [
                    'name' => $row['name'],
                    'prefix' => $row['prefix'],
                    'pattern' => '{Y}-{m}-{seq:4}',
                    'next_sequence' => $legacy?->next_sequence ?? 1,
                    'reset_period' => ReferenceSeries::RESET_YEARLY,
                    'last_generated_at' => $legacy?->last_generated_at,
                ],
            );
        }

        $this->syncSeriesCountersFromBatches();
    }

    protected function syncSeriesCountersFromBatches(): void
    {
        $typeBySlug = [
            'consumables' => ReferenceSeries::TYPE_ISSUANCE_CONSUMABLES,
            'semi_expendable' => ReferenceSeries::TYPE_ISSUANCE_SEMI,
            'ppe' => ReferenceSeries::TYPE_ISSUANCE_PPE,
        ];

        foreach ($typeBySlug as $slug => $type) {
            $maxSequence = DB::table('issuance_batches')
                ->where('category_slug', $slug)
                ->get(['reference_code'])
                ->map(function (object $batch): int {
                    if (preg_match('/^\d{4}-\d{2}-(\d+)$/', (string) $batch->reference_code, $matches) !== 1) {
                        return 0;
                    }

                    return (int) $matches[1];
                })
                ->max() ?? 0;

            if ($maxSequence > 0) {
                ReferenceSeries::query()
                    ->where('type', $type)
                    ->update(['next_sequence' => $maxSequence + 1]);
            }
        }
    }

    protected function resolveCategorySlug(?string $categoryName): string
    {
        $name = strtolower(trim((string) $categoryName));

        if (in_array($name, ['consumables', 'consumable'], true)) {
            return 'consumables';
        }

        if (in_array($name, [
            'ppe',
            'power plant equipment',
            'power_plant_equipment',
            'property, plant and equipment',
            'property plant and equipment',
        ], true)) {
            return 'ppe';
        }

        if (in_array($name, ['semi-expendable', 'semi expendable', 'semi_expendable'], true)) {
            return 'semi_expendable';
        }

        return 'consumables';
    }
};
