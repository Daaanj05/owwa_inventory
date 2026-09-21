<?php

use App\Models\ReferenceSeries;
use App\Services\ReferenceCodeService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_opening_balance_batches', function (Blueprint $table) {
            $table->string('reference_code')->nullable()->after('item_category_id');
            $table->timestamp('confirmed_at')->nullable()->after('recorded_at');
        });

        Schema::table('stock_opening_balance_batches', function (Blueprint $table) {
            $table->date('recorded_on')->nullable()->change();
        });

        ReferenceSeries::query()->updateOrCreate(
            ['type' => ReferenceSeries::TYPE_OPENING_BALANCE],
            [
                'name' => 'Opening balance tracking no. (not official)',
                'prefix' => 'OB',
                'pattern' => '{Y}-{m}-{seq:4}',
                'next_sequence' => 1,
                'reset_period' => ReferenceSeries::RESET_YEARLY,
                'last_generated_at' => null,
            ],
        );

        DB::table('stock_opening_balance_batches')
            ->whereNull('confirmed_at')
            ->orderBy('id')
            ->each(function (object $batch): void {
                $confirmedAt = $batch->recorded_at ?? $batch->created_at ?? now();

                $referenceCode = $batch->reference_code;
                if ($referenceCode === null || $referenceCode === '') {
                    $referenceCode = app(ReferenceCodeService::class)->forOpeningBalance();
                }

                DB::table('stock_opening_balance_batches')
                    ->where('id', $batch->id)
                    ->update([
                        'confirmed_at' => $confirmedAt,
                        'reference_code' => $referenceCode,
                    ]);
            });

        Schema::table('stock_opening_balance_batches', function (Blueprint $table) {
            $table->unique('reference_code');
        });
    }

    public function down(): void
    {
        Schema::table('stock_opening_balance_batches', function (Blueprint $table) {
            $table->dropUnique(['reference_code']);
            $table->dropColumn(['reference_code', 'confirmed_at']);
        });

        Schema::table('stock_opening_balance_batches', function (Blueprint $table) {
            $table->date('recorded_on')->nullable(false)->change();
        });
    }
};
