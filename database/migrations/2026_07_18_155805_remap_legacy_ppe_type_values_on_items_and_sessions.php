<?php

use App\Support\PpePropertyType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (PpePropertyType::LegacyMap as $from => $to) {
            DB::table('items')->where('ppe_type', $from)->update(['ppe_type' => $to]);
            DB::table('physical_count_sessions')->where('ppe_type', $from)->update(['ppe_type' => $to]);
        }
    }

    public function down(): void
    {
        $reverse = [
            PpePropertyType::TechnicalScientificEquipment => 'information_technology',
            PpePropertyType::FurnitureFixturesBooks => 'furniture_fixtures',
            PpePropertyType::MotorVehicle => 'transportation_equipment',
        ];

        foreach ($reverse as $from => $to) {
            DB::table('items')->where('ppe_type', $from)->update(['ppe_type' => $to]);
            DB::table('physical_count_sessions')->where('ppe_type', $from)->update(['ppe_type' => $to]);
        }
    }
};
