<?php

namespace App\Support;

readonly class PublicAssetCardData
{
    public function __construct(
        public string $propertyNumber,
        public string $itemName,
        public string $categoryName,
        public string $officeName,
        public string $statusLabel,
        public ?string $unitCostFormatted,
        public ?string $adminUrl = null,
    ) {}
}
