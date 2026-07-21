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
        public ?string $dateAcquiredFormatted,
        public string $agencyLine1 = 'Republic of the Philippines',
        public string $agencyLine2 = 'OVERSEAS WORKERS WELFARE ADMINISTRATION',
        public string $agencyAddress = 'G/F Parian Commerce Center II, National Highway, Brgy. Parian, Calamba, Laguna',
        public string $spTagNo = '',
        public string $propertyNumberLabel = 'Property No.',
        public string $propertyNameLabel = 'Property',
        public string $endUser = '',
        public string $acquisitionCost = '',
    ) {}
}
