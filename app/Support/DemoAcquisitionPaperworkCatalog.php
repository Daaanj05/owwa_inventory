<?php

namespace App\Support;

/**
 * PR/PO/IAR paperwork case definitions for demo seeding.
 *
 * @phpstan-type PaperworkLine array{item_code: string, quantity: int, unit_cost: float}
 * @phpstan-type PaperworkCase array{
 *     reference_code: string,
 *     category: string,
 *     office_code: string,
 *     requesting_office_code: string,
 *     purpose: string,
 *     pr_date: string,
 *     po_date: string,
 *     iar_date: string,
 *     received: bool,
 *     in_progress_stop: 'pr_draft'|'po_submitted'|null,
 *     lines: array<int, PaperworkLine>
 * }
 */
class DemoAcquisitionPaperworkCatalog
{
    public const CATEGORY_CONSUMABLES = 'Consumables';

    public const CATEGORY_SEMI = 'Semi-Expendable';

    public const CATEGORY_PPE = 'Property, Plant and Equipment';

    /**
     * @return array<int, PaperworkCase>
     */
    public static function cases(): array
    {
        return array_merge(
            self::consumableCases(),
            self::semiCases(),
            self::ppeCases(),
            self::satelliteCases(),
        );
    }

    /**
     * @return array<int, PaperworkCase>
     */
    public static function receivedCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (array $case): bool => $case['received'],
        ));
    }

    /**
     * @return array<int, PaperworkCase>
     */
    public static function inProgressCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (array $case): bool => ! $case['received'],
        ));
    }

    /**
     * Inflow totals from received paperwork keyed by "item_code|office_code".
     *
     * @return array<string, int>
     */
    public static function receivedInflowsByItemOffice(): array
    {
        $totals = [];

        foreach (self::receivedCases() as $case) {
            foreach ($case['lines'] as $line) {
                $key = $line['item_code'].'|'.$case['office_code'];
                $totals[$key] = ($totals[$key] ?? 0) + $line['quantity'];
            }
        }

        return $totals;
    }

    /**
     * @return array<int, PaperworkCase>
     */
    protected static function consumableCases(): array
    {
        return [
            [
                'reference_code' => 'DEMO-PR-CON-PSDBM-Q1',
                'category' => self::CATEGORY_CONSUMABLES,
                'office_code' => 'OWWA-IVA',
                'requesting_office_code' => 'OWWA-LAG',
                'purpose' => 'Q1 PS-DBM consumables replenishment',
                'pr_date' => '2026-01-10',
                'po_date' => '2026-01-12',
                'iar_date' => '2026-01-15',
                'received' => true,
                'in_progress_stop' => null,
                'lines' => [
                    ['item_code' => 'CON-001', 'quantity' => 100, 'unit_cost' => 185.00],
                    ['item_code' => 'CON-002', 'quantity' => 200, 'unit_cost' => 8.50],
                    ['item_code' => 'CON-004', 'quantity' => 150, 'unit_cost' => 12.00],
                    ['item_code' => 'CON-005', 'quantity' => 60, 'unit_cost' => 45.00],
                ],
            ],
            [
                'reference_code' => 'DEMO-PR-CON-INKWELL',
                'category' => self::CATEGORY_CONSUMABLES,
                'office_code' => 'OWWA-IVA',
                'requesting_office_code' => 'OWWA-LAG',
                'purpose' => 'Ink cartridge supplier procurement',
                'pr_date' => '2026-01-18',
                'po_date' => '2026-01-19',
                'iar_date' => '2026-01-20',
                'received' => true,
                'in_progress_stop' => null,
                'lines' => [
                    ['item_code' => 'CON-003', 'quantity' => 40, 'unit_cost' => 850.00],
                ],
            ],
            [
                'reference_code' => 'DEMO-PR-CON-PSDBM-FEB',
                'category' => self::CATEGORY_CONSUMABLES,
                'office_code' => 'OWWA-IVA',
                'requesting_office_code' => 'OWWA-LAG',
                'purpose' => 'February PS-DBM batch',
                'pr_date' => '2026-02-01',
                'po_date' => '2026-02-02',
                'iar_date' => '2026-02-03',
                'received' => true,
                'in_progress_stop' => null,
                'lines' => [
                    ['item_code' => 'CON-006', 'quantity' => 80, 'unit_cost' => 95.00],
                    ['item_code' => 'CON-007', 'quantity' => 120, 'unit_cost' => 35.00],
                    ['item_code' => 'CON-008', 'quantity' => 60, 'unit_cost' => 28.00],
                ],
            ],
            [
                'reference_code' => 'DEMO-PR-CON-PSDBM-Q2',
                'category' => self::CATEGORY_CONSUMABLES,
                'office_code' => 'OWWA-IVA',
                'requesting_office_code' => 'OWWA-LAG',
                'purpose' => 'Q2 PS-DBM replenishment',
                'pr_date' => '2026-03-28',
                'po_date' => '2026-03-30',
                'iar_date' => '2026-04-01',
                'received' => true,
                'in_progress_stop' => null,
                'lines' => [
                    ['item_code' => 'CON-001', 'quantity' => 50, 'unit_cost' => 185.00],
                    ['item_code' => 'CON-002', 'quantity' => 100, 'unit_cost' => 8.50],
                    ['item_code' => 'CON-006', 'quantity' => 40, 'unit_cost' => 95.00],
                ],
            ],
            [
                'reference_code' => 'DEMO-PR-CON-PENDING',
                'category' => self::CATEGORY_CONSUMABLES,
                'office_code' => 'OWWA-IVA',
                'requesting_office_code' => 'OWWA-LAG',
                'purpose' => 'Pending tissue paper request',
                'pr_date' => '2026-06-01',
                'po_date' => '2026-06-05',
                'iar_date' => '2026-06-10',
                'received' => false,
                'in_progress_stop' => 'po_submitted',
                'lines' => [
                    ['item_code' => 'CON-007', 'quantity' => 50, 'unit_cost' => 36.00],
                ],
            ],
        ];
    }

    /**
     * @return array<int, PaperworkCase>
     */
    protected static function semiCases(): array
    {
        $catalogLines = [];

        foreach (DemoSemiItemCatalog::catalogItems() as $spec) {
            $catalogLines[] = [
                'item_code' => $spec['code'],
                'quantity' => 2,
                'unit_cost' => 4500.00,
            ];
        }

        return [
            [
                'reference_code' => 'DEMO-PR-SEM-JAN',
                'category' => self::CATEGORY_SEMI,
                'office_code' => 'OWWA-IVA',
                'requesting_office_code' => 'OWWA-LAG',
                'purpose' => 'January semi-expendable office supplies',
                'pr_date' => '2026-01-18',
                'po_date' => '2026-01-20',
                'iar_date' => '2026-01-22',
                'received' => true,
                'in_progress_stop' => null,
                'lines' => [
                    ['item_code' => 'SEM-001', 'quantity' => 15, 'unit_cost' => 380.00],
                    ['item_code' => 'SEM-002', 'quantity' => 8, 'unit_cost' => 450.00],
                    ['item_code' => 'SEM-003', 'quantity' => 20, 'unit_cost' => 250.00],
                ],
            ],
            [
                'reference_code' => 'DEMO-PR-SEM-FEB',
                'category' => self::CATEGORY_SEMI,
                'office_code' => 'OWWA-IVA',
                'requesting_office_code' => 'OWWA-LAG',
                'purpose' => 'February fixtures batch',
                'pr_date' => '2026-02-08',
                'po_date' => '2026-02-09',
                'iar_date' => '2026-02-10',
                'received' => true,
                'in_progress_stop' => null,
                'lines' => [
                    ['item_code' => 'SEM-004', 'quantity' => 10, 'unit_cost' => 350.00],
                    ['item_code' => 'SEM-005', 'quantity' => 6, 'unit_cost' => 1200.00],
                ],
            ],
            [
                'reference_code' => 'DEMO-PR-SEM-CATALOG',
                'category' => self::CATEGORY_SEMI,
                'office_code' => 'OWWA-IVA',
                'requesting_office_code' => 'OWWA-LAG',
                'purpose' => 'Showcase semi items — one per property class',
                'pr_date' => '2026-05-01',
                'po_date' => '2026-05-10',
                'iar_date' => '2026-05-15',
                'received' => true,
                'in_progress_stop' => null,
                'lines' => $catalogLines,
            ],
            [
                'reference_code' => 'DEMO-PR-SEM-PENDING',
                'category' => self::CATEGORY_SEMI,
                'office_code' => 'OWWA-IVA',
                'requesting_office_code' => 'OWWA-LAG',
                'purpose' => 'Pending paper cutter replenishment',
                'pr_date' => '2026-06-01',
                'po_date' => '2026-06-05',
                'iar_date' => '2026-06-10',
                'received' => false,
                'in_progress_stop' => 'po_submitted',
                'lines' => [
                    ['item_code' => 'SEM-002', 'quantity' => 3, 'unit_cost' => 460.00],
                ],
            ],
        ];
    }

    /**
     * @return array<int, PaperworkCase>
     */
    protected static function ppeCases(): array
    {
        return [
            [
                'reference_code' => 'DEMO-PR-PPE-001',
                'category' => self::CATEGORY_PPE,
                'office_code' => 'OWWA-IVA',
                'requesting_office_code' => 'OWWA-LAG',
                'purpose' => 'Laptop procurement — Lenovo Philippines',
                'pr_date' => '2026-01-05',
                'po_date' => '2026-01-08',
                'iar_date' => '2026-01-10',
                'received' => true,
                'in_progress_stop' => null,
                'lines' => [
                    ['item_code' => 'PPE-001', 'quantity' => 10, 'unit_cost' => 55000.00],
                ],
            ],
            [
                'reference_code' => 'DEMO-PR-PPE-002',
                'category' => self::CATEGORY_PPE,
                'office_code' => 'OWWA-IVA',
                'requesting_office_code' => 'OWWA-LAG',
                'purpose' => 'Office desk procurement',
                'pr_date' => '2026-01-08',
                'po_date' => '2026-01-10',
                'iar_date' => '2026-01-12',
                'received' => true,
                'in_progress_stop' => null,
                'lines' => [
                    ['item_code' => 'PPE-002', 'quantity' => 12, 'unit_cost' => 55000.00],
                ],
            ],
            [
                'reference_code' => 'DEMO-PR-PPE-003',
                'category' => self::CATEGORY_PPE,
                'office_code' => 'OWWA-IVA',
                'requesting_office_code' => 'OWWA-LAG',
                'purpose' => 'Laser printer procurement',
                'pr_date' => '2026-01-08',
                'po_date' => '2026-01-10',
                'iar_date' => '2026-01-12',
                'received' => true,
                'in_progress_stop' => null,
                'lines' => [
                    ['item_code' => 'PPE-003', 'quantity' => 5, 'unit_cost' => 55000.00],
                ],
            ],
            [
                'reference_code' => 'DEMO-PR-PPE-004',
                'category' => self::CATEGORY_PPE,
                'office_code' => 'OWWA-IVA',
                'requesting_office_code' => 'OWWA-LAG',
                'purpose' => 'Air conditioning unit procurement',
                'pr_date' => '2026-01-15',
                'po_date' => '2026-01-16',
                'iar_date' => '2026-01-18',
                'received' => true,
                'in_progress_stop' => null,
                'lines' => [
                    ['item_code' => 'PPE-004', 'quantity' => 4, 'unit_cost' => 55000.00],
                ],
            ],
            [
                'reference_code' => 'DEMO-PR-PPE-PENDING',
                'category' => self::CATEGORY_PPE,
                'office_code' => 'OWWA-IVA',
                'requesting_office_code' => 'OWWA-LAG',
                'purpose' => 'Pending ACU expansion request',
                'pr_date' => '2026-06-01',
                'po_date' => '2026-06-05',
                'iar_date' => '2026-06-10',
                'received' => false,
                'in_progress_stop' => 'po_submitted',
                'lines' => [
                    ['item_code' => 'PPE-004', 'quantity' => 2, 'unit_cost' => 56000.00],
                ],
            ],
        ];
    }

    /**
     * @return array<int, PaperworkCase>
     */
    protected static function satelliteCases(): array
    {
        return [
            [
                'reference_code' => 'DEMO-PR-LAG-CON',
                'category' => self::CATEGORY_CONSUMABLES,
                'office_code' => 'OWWA-LAG',
                'requesting_office_code' => 'OWWA-LAG',
                'purpose' => 'Satellite consumables allocation',
                'pr_date' => '2026-01-15',
                'po_date' => '2026-01-18',
                'iar_date' => '2026-01-20',
                'received' => true,
                'in_progress_stop' => null,
                'lines' => [
                    ['item_code' => 'CON-001', 'quantity' => 30, 'unit_cost' => 185.00],
                    ['item_code' => 'CON-002', 'quantity' => 50, 'unit_cost' => 8.50],
                    ['item_code' => 'CON-006', 'quantity' => 20, 'unit_cost' => 95.00],
                ],
            ],
            [
                'reference_code' => 'DEMO-PR-LAG-SEM',
                'category' => self::CATEGORY_SEMI,
                'office_code' => 'OWWA-LAG',
                'requesting_office_code' => 'OWWA-LAG',
                'purpose' => 'Satellite semi-expendable allocation',
                'pr_date' => '2026-01-12',
                'po_date' => '2026-01-14',
                'iar_date' => '2026-01-15',
                'received' => true,
                'in_progress_stop' => null,
                'lines' => [
                    ['item_code' => 'SEM-001', 'quantity' => 5, 'unit_cost' => 380.00],
                ],
            ],
            [
                'reference_code' => 'DEMO-PR-LAG-PPE',
                'category' => self::CATEGORY_PPE,
                'office_code' => 'OWWA-LAG',
                'requesting_office_code' => 'OWWA-LAG',
                'purpose' => 'Satellite PPE laptop allocation',
                'pr_date' => '2026-01-12',
                'po_date' => '2026-01-14',
                'iar_date' => '2026-01-15',
                'received' => true,
                'in_progress_stop' => null,
                'lines' => [
                    ['item_code' => 'PPE-001', 'quantity' => 3, 'unit_cost' => 55000.00],
                ],
            ],
        ];
    }
}
