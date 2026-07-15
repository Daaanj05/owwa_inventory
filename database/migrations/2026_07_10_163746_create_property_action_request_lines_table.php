<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_action_request_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_action_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issuance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('disposal_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        if (Schema::hasTable('property_action_requests')) {
            $requests = DB::table('property_action_requests')
                ->whereNotNull('issuance_id')
                ->orderBy('id')
                ->get();

            foreach ($requests as $request) {
                DB::table('property_action_request_lines')->insert([
                    'property_action_request_id' => $request->id,
                    'issuance_id' => $request->issuance_id,
                    'inventory_unit_id' => $request->inventory_unit_id,
                    'disposal_id' => $request->disposal_id,
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::table('property_action_requests', function (Blueprint $table): void {
            if (Schema::hasColumn('property_action_requests', 'issuance_id')) {
                $table->dropConstrainedForeignId('issuance_id');
            }
            if (Schema::hasColumn('property_action_requests', 'inventory_unit_id')) {
                $table->dropConstrainedForeignId('inventory_unit_id');
            }
            if (Schema::hasColumn('property_action_requests', 'disposal_id')) {
                $table->dropConstrainedForeignId('disposal_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('property_action_requests', function (Blueprint $table): void {
            $table->foreignId('issuance_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inventory_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('disposal_id')->nullable()->constrained()->nullOnDelete();
        });

        if (Schema::hasTable('property_action_request_lines')) {
            DB::table('property_action_request_lines')
                ->orderBy('property_action_request_id')
                ->orderBy('sort_order')
                ->each(function (object $line): void {
                    DB::table('property_action_requests')
                        ->where('id', $line->property_action_request_id)
                        ->whereNull('issuance_id')
                        ->update([
                            'issuance_id' => $line->issuance_id,
                            'inventory_unit_id' => $line->inventory_unit_id,
                            'disposal_id' => $line->disposal_id,
                        ]);
                });
        }

        Schema::dropIfExists('property_action_request_lines');
    }
};
