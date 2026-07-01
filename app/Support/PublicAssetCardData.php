<?php

namespace App\Support;

readonly class PublicAssetCardData
{
    public function __construct(
        public string $propertyNumber,
        public string $article,
        public string $description,
        public string $unitSection,
        public string $stockNumber,
        public ?string $endUser,
        public ?string $acquisitionCostFormatted,
        public ?string $dateAcquiredFormatted,
    ) {}
}
