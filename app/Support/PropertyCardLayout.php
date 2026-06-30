<?php

namespace App\Support;

use App\Models\Acquisition;
use App\Models\Issuance;
use App\Models\Item;

class PropertyCardLayout
{
    public static function templatePath(): string
    {
        return (string) (OwwaCellMapping::form('PC')['template'] ?? 'ppe/Accquisition/Appendix 69 - PC.xls');
    }

    /**
     * @return array<string, string|int|float|null>
     */
    public static function buildFromItem(Item $item, ?\App\Models\Office $office, ?int $officeId, array $transactions): array
    {
        $latestProperty = Issuance::query()
            ->where('item_id', $item->id)
            ->whereNotNull('property_number')
            ->orderByDesc('issuance_date')
            ->value('property_number');

        $values = [];
        OwwaCellMapping::applyHeader($values, (array) (OwwaCellMapping::form('PC')['header'] ?? []), [
            'entity_name' => $office?->name ?? '',
            'fund_cluster' => $office?->fund_cluster ?? '',
            'property_number' => $latestProperty ?? $item->item_code ?? '',
            'description' => self::itemDescription($item),
        ]);

        self::applyLedgerRows($values, $transactions);

        return $values;
    }

    /**
     * @return array<string, string|int|float|null>
     */
    public static function buildFromAcquisition(Acquisition $acquisition): array
    {
        $acquisition->loadMissing(['item', 'office']);
        $item = $acquisition->item;
        $office = $acquisition->office;
        $quantity = (int) ($acquisition->quantity ?? 0);
        $unitCost = $acquisition->unit_cost !== null ? (float) $acquisition->unit_cost : null;

        $values = [];
        OwwaCellMapping::applyHeader($values, (array) (OwwaCellMapping::form('PC')['header'] ?? []), [
            'entity_name' => $office?->name ?? '',
            'fund_cluster' => $office?->fund_cluster ?? '',
            'property_number' => $item?->item_code ?? '',
            'description' => self::itemDescription($item),
        ]);

        if ($quantity <= 0) {
            return $values;
        }

        $amount = $unitCost !== null ? round($unitCost * $quantity, 2) : null;

        self::applyLedgerRows($values, [[
            'date' => $acquisition->acquisition_date?->format('Y-m-d'),
            'reference' => $acquisition->reference_code,
            'receipt_qty' => $quantity,
            'issue_qty' => null,
            'office_officer' => null,
            'balance' => $quantity,
            'amount' => $amount,
            'remarks' => $acquisition->remarks,
        ]]);

        return $values;
    }

    /**
     * @param  array<string, string|int|float|null>  $values
     * @param  array<int, array<string, mixed>>  $transactions
     */
    public static function applyLedgerRows(array &$values, array $transactions): void
    {
        $ledger = (array) (OwwaCellMapping::form('PC')['ledger'] ?? []);
        $startRow = (int) ($ledger['start_row'] ?? 12);
        $maxRows = (int) ($ledger['max_rows'] ?? 40);
        $cols = (array) ($ledger['columns'] ?? []);

        $row = $startRow;
        foreach ($transactions as $txn) {
            if ($row > $startRow + $maxRows - 1) {
                break;
            }

            if (filled($txn['date'] ?? null)) {
                $values[OwwaCellMapping::columnCell($cols['date'] ?? 'B', $row)] = $txn['date'];
            }
            if (filled($txn['reference'] ?? null)) {
                $values[OwwaCellMapping::columnCell($cols['reference'] ?? 'C', $row)] = $txn['reference'];
            }
            if (filled($txn['receipt_qty'] ?? null)) {
                $values[OwwaCellMapping::columnCell($cols['receipt_qty'] ?? 'D', $row)] = $txn['receipt_qty'];
            }
            if (filled($txn['issue_qty'] ?? null)) {
                $values[OwwaCellMapping::columnCell($cols['issue_qty'] ?? 'E', $row)] = $txn['issue_qty'];
                $officer = $txn['office_officer'] ?? $txn['issue_office'] ?? null;
                if (filled($officer)) {
                    $values[OwwaCellMapping::columnCell($cols['office_officer'] ?? 'F', $row)] = $officer;
                }
            }
            if (array_key_exists('balance', $txn) && $txn['balance'] !== null) {
                $values[OwwaCellMapping::columnCell($cols['balance_qty'] ?? 'H', $row)] = $txn['balance'];
            }
            if (filled($txn['amount'] ?? null)) {
                $values[OwwaCellMapping::columnCell($cols['amount'] ?? 'I', $row)] = $txn['amount'];
            }
            if (filled($txn['remarks'] ?? null)) {
                $values[OwwaCellMapping::columnCell($cols['remarks'] ?? 'J', $row)] = $txn['remarks'];
            }

            $row++;
        }
    }

    protected static function itemDescription(?Item $item): string
    {
        if ($item === null) {
            return '';
        }

        $parts = array_filter([$item->name, $item->description, $item->serial_number ? 'S/N: '.$item->serial_number : null]);

        return implode(' — ', $parts);
    }

    /**
     * @param  array<string, mixed>  $txn
     */
    public static function normalizeTransactionRow(array $txn): array
    {
        $receiptQty = filled($txn['receipt_qty'] ?? null) ? (int) $txn['receipt_qty'] : null;
        $unitCost = isset($txn['unit_cost']) ? (float) $txn['unit_cost'] : null;
        $amount = null;

        if ($receiptQty !== null && $unitCost !== null && $receiptQty > 0) {
            $amount = round($unitCost * $receiptQty, 2);
        }

        return [
            'date' => $txn['date'] ?? null,
            'reference' => $txn['reference'] ?? null,
            'receipt_qty' => $receiptQty,
            'issue_qty' => filled($txn['issue_qty'] ?? null) ? (int) $txn['issue_qty'] : null,
            'office_officer' => $txn['office_officer'] ?? $txn['issue_office'] ?? null,
            'balance' => $txn['balance'] ?? null,
            'amount' => $amount,
            'remarks' => $txn['remarks'] ?? null,
        ];
    }
}
