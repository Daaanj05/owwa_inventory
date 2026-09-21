<?php

namespace Database\Seeders;

use App\Models\DeliveryTerm;
use App\Support\ModeOfProcurementOptions;
use Illuminate\Database\Seeder;

class DeliveryTermSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ModeOfProcurementOptions::deliveryTermSuggestions() as $label) {
            DeliveryTerm::query()->firstOrCreate(
                ['label' => $label],
                ['is_active' => true],
            );
        }
    }
}
