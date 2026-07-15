<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('uacs_object_code_id')
                ->nullable()
                ->after('property_class')
                ->constrained('uacs_object_codes')
                ->nullOnDelete();
            $table->string('ppe_property_number')->nullable()->after('semi_expendable_property_number');
        });

        $map = [
            'ict' => 'information_technology',
            'sports_equipment' => 'machinery_equipment',
            'vehicle_equipment' => 'transportation_equipment',
            'furnitures_fixtures' => 'furniture_fixtures',
        ];

        foreach ($map as $from => $to) {
            DB::table('items')->where('property_class', $from)->update(['property_class' => $to]);
        }
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('uacs_object_code_id');
            $table->dropColumn('ppe_property_number');
        });
    }
};
