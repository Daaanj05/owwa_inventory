<?php

namespace App\Observers;

use App\Models\Distribution;
use App\Services\EmployeeRequisitionClosureService;

class DistributionObserver
{
    public function created(Distribution $distribution): void
    {
        app(EmployeeRequisitionClosureService::class)->closeFromDistribution($distribution);
    }
}
