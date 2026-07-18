<?php

namespace App\Support;

use App\Models\PhysicalCountLine;

class PhysicalCountScanResult
{
    public function __construct(
        public PhysicalCountScanOutcome $outcome,
        public ?PhysicalCountLine $line = null,
        public ?string $message = null,
    ) {}

    public function isSuccess(): bool
    {
        return in_array($this->outcome, [
            PhysicalCountScanOutcome::Found,
            PhysicalCountScanOutcome::Overage,
            PhysicalCountScanOutcome::NeedsQuantity,
        ], true);
    }

    public function needsQuantity(): bool
    {
        return $this->outcome === PhysicalCountScanOutcome::NeedsQuantity;
    }
}
