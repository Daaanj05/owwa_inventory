<?php

namespace App\Support;

/**
 * Single source of truth for demo stock movements and expected balances.
 *
 * @phpstan-type Outflow array{
 *     type: 'issuance'|'transfer_out'|'transfer_in'|'disposal',
 *     item_code: string,
 *     office_code: string,
 *     quantity: int,
 *     date: string,
 *     meta?: array<string, mixed>
 * }
 */
class DemoStockLedgerCatalog
{
    public const PHYSICAL_COUNT_DATE = '2026-06-30';

    public const REGIONAL_OFFICE = 'OWWA-IVA';

    public const SATELLITE_OFFICE = 'OWWA-LAG';

    /**
     * @return array<int, string>
     */
    public static function coreConsumableCodes(): array
    {
        return ['CON-001', 'CON-002', 'CON-003', 'CON-004', 'CON-005', 'CON-006', 'CON-007', 'CON-008'];
    }

    /**
     * @return array<int, string>
     */
    public static function coreSemiCodes(): array
    {
        return array_merge(
            array_keys(DemoSemiItemCatalog::coreItems()),
            array_column(DemoSemiItemCatalog::catalogItems(), 'code'),
        );
    }

    /**
     * @return array<int, string>
     */
    public static function corePpeCodes(): array
    {
        return ['PPE-001', 'PPE-002', 'PPE-003', 'PPE-004'];
    }

    /**
     * @return array<int, string>
     */
    public static function allCoreItemCodes(): array
    {
        return array_merge(
            self::coreConsumableCodes(),
            self::coreSemiCodes(),
            self::corePpeCodes(),
        );
    }

    /**
     * Non-paperwork inflows (legacy cost bucket, etc.) keyed by "item_code|office_code".
     *
     * @return array<string, int>
     */
    public static function supplementalInflows(): array
    {
        return [
            'SEM-001|'.self::REGIONAL_OFFICE => 3,
        ];
    }

    /**
     * @return array<string, int>
     */
    public static function totalInflowsByItemOffice(): array
    {
        $totals = DemoAcquisitionPaperworkCatalog::receivedInflowsByItemOffice();

        foreach (self::supplementalInflows() as $key => $qty) {
            $totals[$key] = ($totals[$key] ?? 0) + $qty;
        }

        return $totals;
    }

    /**
     * @return array<int, Outflow>
     */
    public static function outflows(): array
    {
        $regional = self::REGIONAL_OFFICE;
        $satellite = self::SATELLITE_OFFICE;

        return array_merge(
            self::mapIssuanceOutflows($regional),
            self::mapTransferOutflows($regional, $satellite),
            self::mapDisposalOutflows($regional),
            self::mapConnectionIssuanceOutflows($regional),
            self::mapIncidentDisposalOutflows($regional),
        );
    }

    public static function expectedStock(string $itemCode, string $officeCode): int
    {
        $key = $itemCode.'|'.$officeCode;
        $stock = self::totalInflowsByItemOffice()[$key] ?? 0;

        foreach (self::outflows() as $outflow) {
            if ($outflow['type'] === 'transfer_out'
                && $outflow['item_code'] === $itemCode
                && $outflow['office_code'] === $officeCode) {
                $stock -= $outflow['quantity'];
            }

            if ($outflow['type'] === 'transfer_in'
                && $outflow['item_code'] === $itemCode
                && $outflow['office_code'] === $officeCode) {
                $stock += $outflow['quantity'];
            }

            if ($outflow['type'] === 'issuance'
                && $outflow['item_code'] === $itemCode
                && $outflow['office_code'] === $officeCode) {
                $stock -= $outflow['quantity'];
            }

            if ($outflow['type'] === 'disposal'
                && $outflow['item_code'] === $itemCode
                && $outflow['office_code'] === $officeCode) {
                $stock -= $outflow['quantity'];
            }
        }

        return max(0, $stock);
    }

    /**
     * @return array<int, array{item_code: string, office_code: string, expected_stock: int}>
     */
    public static function corePositions(): array
    {
        $positions = [];

        foreach (self::allCoreItemCodes() as $itemCode) {
            foreach ([self::REGIONAL_OFFICE, self::SATELLITE_OFFICE] as $officeCode) {
                $expected = self::expectedStock($itemCode, $officeCode);

                if ($expected > 0 || self::hasMovement($itemCode, $officeCode)) {
                    $positions[] = [
                        'item_code' => $itemCode,
                        'office_code' => $officeCode,
                        'expected_stock' => $expected,
                    ];
                }
            }
        }

        return $positions;
    }

    /**
     * Variant consumable codes (siblings of CON-001–008). Kept outside core ledger asserts.
     *
     * @return array<int, string>
     */
    public static function variantConsumableCodes(): array
    {
        return ['CON-009', 'CON-010', 'CON-011', 'CON-012', 'CON-013'];
    }

    /**
     * @return array<int, string>
     */
    public static function variantPpeCodes(): array
    {
        return ['PPE-005'];
    }

    /**
     * Issuance rows for sub-item variants — not included in core expected-stock math.
     *
     * @return array<int, array{item: string, qty: int, date: string, dept_code: string}>
     */
    public static function variantIssuanceBatchRows(): array
    {
        return [
            ['item' => 'CON-009', 'qty' => 10, 'date' => '2026-02-12', 'dept_code' => 'ADM'],
            ['item' => 'CON-009', 'qty' => 8, 'date' => '2026-03-08', 'dept_code' => 'OPS'],
            ['item' => 'CON-010', 'qty' => 6, 'date' => '2026-02-12', 'dept_code' => 'FIN'],
            ['item' => 'CON-011', 'qty' => 15, 'date' => '2026-02-18', 'dept_code' => 'ADM'],
            ['item' => 'CON-012', 'qty' => 12, 'date' => '2026-03-05', 'dept_code' => 'OPS'],
            ['item' => 'CON-013', 'qty' => 10, 'date' => '2026-03-15', 'dept_code' => 'FIN'],
            ['item' => 'PPE-005', 'qty' => 1, 'date' => '2026-02-20', 'dept_code' => 'ADM'],
            ['item' => 'PPE-005', 'qty' => 1, 'date' => '2026-03-01', 'dept_code' => 'OPS'],
        ];
    }

    /**
     * Satellite Welfare issuances for office → department filter demos.
     *
     * @return array<int, array{item: string, qty: int, date: string, dept_code: string}>
     */
    public static function satelliteVariantIssuanceBatchRows(): array
    {
        return [
            ['item' => 'CON-009', 'qty' => 5, 'date' => '2026-02-25', 'dept_code' => 'WSU'],
            ['item' => 'CON-011', 'qty' => 8, 'date' => '2026-03-10', 'dept_code' => 'WSU'],
            ['item' => 'CON-010', 'qty' => 4, 'date' => '2026-03-12', 'dept_code' => 'WSU'],
        ];
    }

    /**
     * Issuance rows grouped by date|department for {@see DemoInventoryWorkflow::seedIssuanceBatchesFromGroups}.
     *
     * @return array<int, array{item: string, qty: int, date: string, dept_code: string}>
     */
    public static function issuanceBatchRows(): array
    {
        return [
            ['item' => 'CON-001', 'qty' => 15, 'date' => '2026-01-20', 'dept_code' => 'ADM'],
            ['item' => 'CON-002', 'qty' => 30, 'date' => '2026-01-20', 'dept_code' => 'ADM'],
            ['item' => 'CON-004', 'qty' => 20, 'date' => '2026-01-20', 'dept_code' => 'ADM'],
            ['item' => 'CON-001', 'qty' => 10, 'date' => '2026-01-22', 'dept_code' => 'OPS'],
            ['item' => 'CON-002', 'qty' => 25, 'date' => '2026-01-22', 'dept_code' => 'OPS'],
            ['item' => 'CON-003', 'qty' => 5, 'date' => '2026-01-25', 'dept_code' => 'OPS'],
            ['item' => 'CON-001', 'qty' => 12, 'date' => '2026-02-10', 'dept_code' => 'FIN'],
            ['item' => 'CON-002', 'qty' => 20, 'date' => '2026-02-10', 'dept_code' => 'FIN'],
            ['item' => 'CON-006', 'qty' => 10, 'date' => '2026-02-10', 'dept_code' => 'ADM'],
            ['item' => 'CON-007', 'qty' => 15, 'date' => '2026-02-12', 'dept_code' => 'ADM'],
            ['item' => 'CON-005', 'qty' => 8, 'date' => '2026-02-15', 'dept_code' => 'OPS'],
            ['item' => 'CON-008', 'qty' => 10, 'date' => '2026-02-15', 'dept_code' => 'OPS'],
            ['item' => 'CON-001', 'qty' => 10, 'date' => '2026-03-05', 'dept_code' => 'ADM'],
            ['item' => 'CON-002', 'qty' => 25, 'date' => '2026-03-05', 'dept_code' => 'ADM'],
            ['item' => 'CON-003', 'qty' => 8, 'date' => '2026-03-10', 'dept_code' => 'OPS'],
            ['item' => 'CON-006', 'qty' => 15, 'date' => '2026-03-10', 'dept_code' => 'OPS'],
            ['item' => 'CON-007', 'qty' => 20, 'date' => '2026-03-12', 'dept_code' => 'FIN'],
            ['item' => 'CON-004', 'qty' => 25, 'date' => '2026-03-15', 'dept_code' => 'FIN'],
            ['item' => 'CON-001', 'qty' => 10, 'date' => '2026-04-02', 'dept_code' => 'OPS'],
            ['item' => 'CON-002', 'qty' => 20, 'date' => '2026-04-02', 'dept_code' => 'OPS'],
            ['item' => 'CON-006', 'qty' => 12, 'date' => '2026-04-03', 'dept_code' => 'ADM'],
            ['item' => 'SEM-001', 'qty' => 3, 'date' => '2026-01-25', 'dept_code' => 'ADM'],
            ['item' => 'SEM-002', 'qty' => 2, 'date' => '2026-01-25', 'dept_code' => 'OPS'],
            ['item' => 'SEM-003', 'qty' => 4, 'date' => '2026-02-01', 'dept_code' => 'ADM'],
            ['item' => 'SEM-003', 'qty' => 3, 'date' => '2026-02-05', 'dept_code' => 'OPS'],
            ['item' => 'SEM-004', 'qty' => 2, 'date' => '2026-02-15', 'dept_code' => 'FIN'],
            ['item' => 'SEM-005', 'qty' => 1, 'date' => '2026-03-01', 'dept_code' => 'ADM'],
            ['item' => 'PPE-001', 'qty' => 2, 'date' => '2026-01-15', 'dept_code' => 'ADM'],
            ['item' => 'PPE-001', 'qty' => 2, 'date' => '2026-01-18', 'dept_code' => 'OPS'],
            ['item' => 'PPE-002', 'qty' => 3, 'date' => '2026-01-20', 'dept_code' => 'ADM'],
            ['item' => 'PPE-002', 'qty' => 2, 'date' => '2026-01-22', 'dept_code' => 'OPS'],
            ['item' => 'PPE-003', 'qty' => 1, 'date' => '2026-01-25', 'dept_code' => 'ADM'],
            ['item' => 'PPE-003', 'qty' => 1, 'date' => '2026-02-01', 'dept_code' => 'OPS'],
            ['item' => 'PPE-004', 'qty' => 1, 'date' => '2026-02-01', 'dept_code' => 'ADM'],
            ['item' => 'PPE-004', 'qty' => 1, 'date' => '2026-02-10', 'dept_code' => 'OPS'],
        ];
    }

    /**
     * @return array<int, array{item: string, qty: int, date: string}>
     */
    public static function transferRows(): array
    {
        return [
            ['item' => 'CON-004', 'qty' => 20, 'date' => '2026-02-20'],
            ['item' => 'CON-005', 'qty' => 10, 'date' => '2026-02-20'],
            ['item' => 'SEM-001', 'qty' => 2, 'date' => '2026-03-01'],
            ['item' => 'SEM-002', 'qty' => 1, 'date' => '2026-03-08'],
            ['item' => 'PPE-002', 'qty' => 2, 'date' => '2026-03-05'],
            ['item' => 'PPE-001', 'qty' => 1, 'date' => '2026-03-12'],
        ];
    }

    /**
     * @return array<int, Outflow>
     */
    protected static function mapIssuanceOutflows(string $officeCode): array
    {
        $rows = [];

        foreach (self::issuanceBatchRows() as $row) {
            $rows[] = [
                'type' => 'issuance',
                'item_code' => $row['item'],
                'office_code' => $officeCode,
                'quantity' => $row['qty'],
                'date' => $row['date'],
            ];
        }

        $rows[] = [
            'type' => 'issuance',
            'item_code' => 'CON-001',
            'office_code' => $officeCode,
            'quantity' => 8,
            'date' => '2026-03-05',
            'meta' => ['requisition' => 'REQ-2026-0003'],
        ];
        $rows[] = [
            'type' => 'issuance',
            'item_code' => 'CON-002',
            'office_code' => $officeCode,
            'quantity' => 10,
            'date' => '2026-03-05',
            'meta' => ['requisition' => 'REQ-2026-0003'],
        ];
        $rows[] = [
            'type' => 'issuance',
            'item_code' => 'CON-006',
            'office_code' => $officeCode,
            'quantity' => 5,
            'date' => '2026-03-05',
            'meta' => ['requisition' => 'REQ-2026-0003'],
        ];
        $rows[] = [
            'type' => 'issuance',
            'item_code' => 'CON-008',
            'office_code' => $officeCode,
            'quantity' => 3,
            'date' => '2026-03-05',
            'meta' => ['requisition' => 'REQ-2026-0003'],
        ];

        return $rows;
    }

    /**
     * @return array<int, Outflow>
     */
    protected static function mapTransferOutflows(string $fromOffice, string $toOffice): array
    {
        $rows = [];

        foreach (self::transferRows() as $row) {
            $rows[] = [
                'type' => 'transfer_out',
                'item_code' => $row['item'],
                'office_code' => $fromOffice,
                'quantity' => $row['qty'],
                'date' => $row['date'],
            ];
            $rows[] = [
                'type' => 'transfer_in',
                'item_code' => $row['item'],
                'office_code' => $toOffice,
                'quantity' => $row['qty'],
                'date' => $row['date'],
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, Outflow>
     */
    protected static function mapDisposalOutflows(string $officeCode): array
    {
        return [
            [
                'type' => 'disposal',
                'item_code' => 'CON-003',
                'office_code' => $officeCode,
                'quantity' => 3,
                'date' => '2026-03-25',
                'meta' => ['reference_code' => '2026-03-0001'],
            ],
            [
                'type' => 'disposal',
                'item_code' => 'CON-007',
                'office_code' => $officeCode,
                'quantity' => 2,
                'date' => '2026-03-25',
                'meta' => ['reference_code' => '2026-03-0001'],
            ],
            [
                'type' => 'disposal',
                'item_code' => 'SEM-004',
                'office_code' => $officeCode,
                'quantity' => 1,
                'date' => '2026-03-20',
                'meta' => ['reference_code' => '2026-03-0002'],
            ],
            [
                'type' => 'disposal',
                'item_code' => 'PPE-003',
                'office_code' => $officeCode,
                'quantity' => 1,
                'date' => '2026-03-15',
                'meta' => ['reference_code' => '2026-03-0003'],
            ],
            [
                'type' => 'disposal',
                'item_code' => 'PPE-004',
                'office_code' => $officeCode,
                'quantity' => 1,
                'date' => '2026-03-15',
                'meta' => ['reference_code' => '2026-03-0003'],
            ],
            [
                'type' => 'disposal',
                'item_code' => 'SEM-002',
                'office_code' => $officeCode,
                'quantity' => 1,
                'date' => '2026-03-28',
                'meta' => ['reference_code' => '2026-03-0004'],
            ],
            [
                'type' => 'disposal',
                'item_code' => 'SEM-005',
                'office_code' => $officeCode,
                'quantity' => 1,
                'date' => '2026-03-28',
                'meta' => ['reference_code' => '2026-03-0004'],
            ],
        ];
    }

    /**
     * Post–physical-count incident disposals ({@see IncidentReportDemoSeeder}).
     *
     * @return array<int, Outflow>
     */
    protected static function mapIncidentDisposalOutflows(string $officeCode): array
    {
        return [
            [
                'type' => 'disposal',
                'item_code' => 'SEM-SP-001',
                'office_code' => $officeCode,
                'quantity' => 1,
                'date' => '2026-07-10',
                'meta' => ['reference_code' => '2026-07-0001'],
            ],
            [
                'type' => 'disposal',
                'item_code' => 'PPE-004',
                'office_code' => $officeCode,
                'quantity' => 1,
                'date' => '2026-07-12',
                'meta' => ['reference_code' => '2026-07-0002'],
            ],
        ];
    }

    /**
     * Property-tag issuances from {@see DemoInventoryConnectionsSeeder}.
     *
     * @return array<int, Outflow>
     */
    protected static function mapConnectionIssuanceOutflows(string $officeCode): array
    {
        $rows = [];

        foreach (DemoSemiItemCatalog::catalogItems() as $spec) {
            $rows[] = [
                'type' => 'issuance',
                'item_code' => $spec['code'],
                'office_code' => $officeCode,
                'quantity' => 1,
                'date' => '2026-05-20',
                'meta' => ['requisition' => 'REQ-DEMO-SEM-CATALOG'],
            ];
        }

        foreach (['PPE-001', 'PPE-002'] as $code) {
            $rows[] = [
                'type' => 'issuance',
                'item_code' => $code,
                'office_code' => $officeCode,
                'quantity' => 1,
                'date' => '2026-05-25',
                'meta' => ['requisition' => 'REQ-DEMO-PROP-PPE'],
            ];
        }

        $rows[] = [
            'type' => 'issuance',
            'item_code' => 'SEM-001',
            'office_code' => $officeCode,
            'quantity' => 1,
            'date' => '2026-05-25',
            'meta' => ['requisition' => 'REQ-DEMO-PROP-SEM-001'],
        ];

        return $rows;
    }

    protected static function hasMovement(string $itemCode, string $officeCode): bool
    {
        $key = $itemCode.'|'.$officeCode;

        if (isset(self::totalInflowsByItemOffice()[$key])) {
            return true;
        }

        foreach (self::outflows() as $outflow) {
            if ($outflow['item_code'] === $itemCode && $outflow['office_code'] === $officeCode) {
                return true;
            }
        }

        return false;
    }
}
