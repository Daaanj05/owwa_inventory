<?php

namespace App\Jobs;

use App\Services\AiProcurementRecommendationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateAiProcurementRecommendationJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int>  $officeIds
     */
    public function __construct(
        public int $runId,
        public string $periodFrom,
        public string $periodTo,
        public ?int $categoryId,
        public array $officeIds,
    ) {}

    public function handle(AiProcurementRecommendationService $service): void
    {
        $service->processRun(
            runId: $this->runId,
            periodFrom: $this->periodFrom,
            periodTo: $this->periodTo,
            categoryId: $this->categoryId,
            officeIds: $this->officeIds,
        );
    }

    public function failed(Throwable $exception): void
    {
        app(AiProcurementRecommendationService::class)->markRunFailed(
            $this->runId,
            $exception->getMessage(),
        );
    }
}
