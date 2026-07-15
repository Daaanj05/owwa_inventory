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
        if (! Schema::hasTable('disposal_batches')) {
            Schema::create('disposal_batches', function (Blueprint $table): void {
                $table->id();
                $table->string('category_slug', 32);
                $table->string('reference_code');
                $table->foreignId('office_id')->constrained()->cascadeOnDelete();
                $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
                $table->date('disposal_date');
                $table->string('disposal_type')->nullable();
                $table->string('disposal_mode')->nullable();
                $table->text('remarks')->nullable();
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('custodian_printed_name')->nullable();
                $table->string('accountable_officer_designation')->nullable();
                $table->string('accountable_officer_station')->nullable();
                $table->string('approved_by_printed_name')->nullable();
                $table->string('immediate_supervisor_printed_name')->nullable();
                $table->string('inspection_officer_printed_name')->nullable();
                $table->string('witness_printed_name')->nullable();
                $table->timestamps();

                $table->index('category_slug');
                $table->index('disposal_date');
                $table->unique(['category_slug', 'reference_code']);
            });
        }

        if (! Schema::hasColumn('disposals', 'disposal_batch_id')) {
            Schema::table('disposals', function (Blueprint $table): void {
                $table->foreignId('disposal_batch_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('disposal_batches')
                    ->nullOnDelete();
            });
        }

        if (DB::table('disposal_batches')->count() === 0) {
            $this->backfillDisposalBatches();
        }

        $this->dropDisposalReferenceCodeUniqueIfPresent();
        $this->seedPerCategoryDisposalSeries();
    }

    public function down(): void
    {
        Schema::table('disposals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('disposal_batch_id');
            $table->dropIndex(['reference_code']);
            $table->unique('reference_code');
        });

        Schema::dropIfExists('disposal_batches');

        ReferenceSeries::query()
            ->whereIn('type', [
                ReferenceSeries::TYPE_DISPOSAL_CONSUMABLES,
                ReferenceSeries::TYPE_DISPOSAL_PROPERTY,
            ])
            ->delete();
    }

    protected function backfillDisposalBatches(): void
    {
        $categoryNamesById = DB::table('item_categories')->pluck('name', 'id');

        $disposals = DB::table('disposals')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        foreach ($disposals as $disposal) {
            $itemCategoryId = DB::table('items')
                ->where('id', $disposal->item_id)
                ->value('item_category_id');

            $categorySlug = $this->resolveCategorySlug($categoryNamesById[$itemCategoryId] ?? null);

            $batchId = DB::table('disposal_batches')->insertGetId([
                'category_slug' => $categorySlug,
                'reference_code' => $disposal->reference_code,
                'office_id' => $disposal->office_id,
                'department_id' => $disposal->department_id,
                'disposal_date' => $disposal->disposal_date,
                'disposal_type' => $disposal->disposal_type,
                'disposal_mode' => $disposal->disposal_mode,
                'remarks' => $disposal->remarks,
                'recorded_by' => $disposal->recorded_by,
                'custodian_printed_name' => $disposal->custodian_printed_name ?? null,
                'accountable_officer_designation' => $disposal->accountable_officer_designation ?? null,
                'accountable_officer_station' => $disposal->accountable_officer_station ?? null,
                'approved_by_printed_name' => $disposal->approved_by_printed_name ?? null,
                'immediate_supervisor_printed_name' => $disposal->immediate_supervisor_printed_name ?? null,
                'inspection_officer_printed_name' => $disposal->inspection_officer_printed_name ?? null,
                'witness_printed_name' => $disposal->witness_printed_name ?? null,
                'created_at' => $disposal->created_at,
                'updated_at' => $disposal->updated_at,
            ]);

            DB::table('disposals')
                ->where('id', $disposal->id)
                ->update(['disposal_batch_id' => $batchId]);
        }
    }

    protected function dropDisposalReferenceCodeUniqueIfPresent(): void
    {
        try {
            Schema::table('disposals', function (Blueprint $table): void {
                $table->dropUnique(['reference_code']);
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('disposals', function (Blueprint $table): void {
                $table->index('reference_code');
            });
        } catch (\Throwable) {
        }
    }

    protected function seedPerCategoryDisposalSeries(): void
    {
        $legacy = ReferenceSeries::query()
            ->where('type', ReferenceSeries::TYPE_DISPOSAL)
            ->first();

        foreach ([
            [
                'type' => ReferenceSeries::TYPE_DISPOSAL_CONSUMABLES,
                'name' => 'Disposal control no. (WMR — consumables)',
                'prefix' => 'WMR',
            ],
            [
                'type' => ReferenceSeries::TYPE_DISPOSAL_PROPERTY,
                'name' => 'Disposal control no. (IIRUP — semi/PPE)',
                'prefix' => 'IIRUP',
            ],
        ] as $row) {
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
