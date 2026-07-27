<?php

use App\Models\ReferenceSeries;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ReferenceSeries::query()
            ->where('type', ReferenceSeries::TYPE_EMPLOYEE_REQUISITION_TRANSACTION)
            ->update(['pattern' => '{Y}-{seq:4}']);
    }

    public function down(): void
    {
        ReferenceSeries::query()
            ->where('type', ReferenceSeries::TYPE_EMPLOYEE_REQUISITION_TRANSACTION)
            ->update(['pattern' => '{Y}-{m}-{seq:4}']);
    }
};
