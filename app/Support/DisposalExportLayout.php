<?php

namespace App\Support;

use App\Models\Acquisition;
use App\Models\Disposal;
use App\Models\Item;
use App\Services\DisposalInventoryUnitService;

class DisposalExportLayout
{
    /**
     * @return array<string, string|int|float|null>
     */
    public static function cellValuesForWmr(Disposal $disposal): array
    {
        $item = $disposal->item;
        $office = $disposal->office;
        $placeOfStorage = $disposal->place_of_storage ?? $office?->name ?? '';
        $wmrMap = OwwaCellMapping::form('WMR');
        $detailStart = (int) ($wmrMap['detail']['start_row'] ?? 13);
        $cols = (array) ($wmrMap['detail']['columns'] ?? []);

        $values = [];
        OwwaCellMapping::applyHeader($values, (array) ($wmrMap['header'] ?? []), [
            'entity_name' => $office?->name ?? '',
            'fund_cluster' => '',
            'place_of_storage' => $placeOfStorage,
            'date' => $disposal->disposal_date?->format('Y-m-d') ?? '',
        ]);

        $values[OwwaCellMapping::columnCell($cols['item_no'] ?? 'A', $detailStart)] = $disposal->wmr_inspection_item_no ?? 1;
        $values[OwwaCellMapping::columnCell($cols['quantity'] ?? 'B', $detailStart)] = (string) $disposal->quantity;
        $values[OwwaCellMapping::columnCell($cols['unit'] ?? 'C', $detailStart)] = $item?->unit ?? '';
        $values[OwwaCellMapping::columnCell($cols['description'] ?? 'D', $detailStart)] = self::itemDescription($item)
            .($disposal->reason ? ' – '.$disposal->reason : '');
        $values[OwwaCellMapping::columnCell($cols['official_receipt_no'] ?? 'G', $detailStart)] = $disposal->official_receipt_no ?? '';
        $values[OwwaCellMapping::columnCell($cols['sale_date'] ?? 'H', $detailStart)] = $disposal->sale_date?->format('Y-m-d') ?? '';
        $values[OwwaCellMapping::columnCell($cols['sale_amount'] ?? 'I', $detailStart)] = $disposal->sale_amount !== null
            ? (float) $disposal->sale_amount
            : '';
        $totalCell = $wmrMap['detail']['total_cell'] ?? null;
        if (is_string($totalCell) && $totalCell !== '') {
            $values[$totalCell] = $disposal->sale_amount !== null
                ? (float) $disposal->sale_amount
                : '';
        }

        self::applyWmrDisposalMode($values, $disposal);
        self::applyWmrSignatures($values, $disposal);

        return $values;
    }

    /**
     * @return array<string, string|int|float|null>
     */
    public static function cellValuesForIirup(Disposal $disposal, string $formCode = 'IIRUP'): array
    {
        $disposal->loadMissing(['parIssuance', 'item', 'inventoryUnit.acquisition']);
        $item = $disposal->item;
        $office = $disposal->office;
        $map = OwwaCellMapping::form($formCode);
        $detailStart = (int) ($map['detail']['start_row'] ?? 15);
        $cols = (array) ($map['detail']['columns'] ?? []);
        $dateAcquired = $disposal->inventoryUnit?->acquisition?->acquisition_date?->format('Y-m-d')
            ?? self::lookupDateAcquired($disposal->item_id);
        $unitCost = app(DisposalInventoryUnitService::class)->resolveAcquisitionCost($disposal);
        $quantity = (int) $disposal->quantity;
        $totalCost = $unitCost !== null ? $unitCost * $quantity : null;
        $accumulatedDepreciation = (float) ($disposal->accumulated_depreciation ?? 0);
        $accumulatedImpairment = (float) ($disposal->accumulated_impairment_losses ?? 0);
        $carryingAmount = $totalCost !== null
            ? max(0, $totalCost - $accumulatedDepreciation - $accumulatedImpairment)
            : null;
        $remarks = collect([
            $disposal->reason,
            $disposal->remarks,
            $disposal->iirup_disposal_mode === 'others' ? $disposal->iirup_other_mode : null,
        ])->filter()->implode(' — ');

        $values = [];
        OwwaCellMapping::applyHeader($values, (array) ($map['header'] ?? []), [
            'as_at' => $disposal->disposal_date?->format('Y-m-d') ?? '',
            'entity_name' => $office?->name ?? '',
            'fund_cluster' => '',
            'accountable_officer' => $disposal->custodian_printed_name ?? '',
            'accountable_designation' => $disposal->accountable_officer_designation ?? '',
            'accountable_station' => $disposal->accountable_officer_station ?? $office?->name ?? '',
        ]);

        $values[OwwaCellMapping::columnCell($cols['date_acquired'] ?? 'B', $detailStart)] = $dateAcquired ?? '';
        $values[OwwaCellMapping::columnCell($cols['description'] ?? 'C', $detailStart)] = self::itemDescription($item);
        $values[OwwaCellMapping::columnCell($cols['property_no'] ?? 'D', $detailStart)] = $disposal->property_number ?? $item?->item_code ?? '';
        $values[OwwaCellMapping::columnCell($cols['quantity'] ?? 'E', $detailStart)] = (string) $quantity;
        $values[OwwaCellMapping::columnCell($cols['unit_cost'] ?? 'F', $detailStart)] = $unitCost ?? '';
        $values[OwwaCellMapping::columnCell($cols['total_cost'] ?? 'G', $detailStart)] = $totalCost ?? '';
        $values[OwwaCellMapping::columnCell($cols['accumulated_depreciation'] ?? 'H', $detailStart)] = $accumulatedDepreciation;
        $values[OwwaCellMapping::columnCell($cols['accumulated_impairment_losses'] ?? 'I', $detailStart)] = $accumulatedImpairment;
        $values[OwwaCellMapping::columnCell($cols['carrying_amount'] ?? 'J', $detailStart)] = $carryingAmount ?? '';
        $values[OwwaCellMapping::columnCell($cols['remarks'] ?? 'K', $detailStart)] = $remarks;
        $values[OwwaCellMapping::columnCell($cols['disposal_total'] ?? 'P', $detailStart)] = $disposal->iirup_disposal_amount !== null
            ? (float) $disposal->iirup_disposal_amount
            : '';
        $values[OwwaCellMapping::columnCell($cols['appraised_value'] ?? 'Q', $detailStart)] = $disposal->appraised_value !== null
            ? (float) $disposal->appraised_value
            : '';
        $values[OwwaCellMapping::columnCell($cols['official_receipt_no'] ?? 'R', $detailStart)] = $disposal->official_receipt_no ?? '';
        $values[OwwaCellMapping::columnCell($cols['sale_amount'] ?? 'S', $detailStart)] = $disposal->sale_amount !== null
            ? (float) $disposal->sale_amount
            : '';

        self::applyIirupDisposalMode($values, $disposal, $formCode, $detailStart);
        self::applyIirupSignatures($values, $disposal, $formCode);

        return $values;
    }

    /**
     * @return array<string, string|int|float|null>
     */
    public static function cellValuesForRlsddp(Disposal $disposal): array
    {
        $disposal->loadMissing(['parIssuance.department', 'department', 'item', 'office', 'inventoryUnit.acquisition', 'inventoryUnit.issuance']);
        $item = $disposal->item;
        $office = $disposal->office;
        $par = $disposal->parIssuance;
        $department = $disposal->department ?? $par?->department;
        $unitService = app(DisposalInventoryUnitService::class);
        $map = OwwaCellMapping::form('RLSDDP');
        $detailStart = (int) ($map['detail']['start_row'] ?? 20);
        $cols = (array) ($map['detail']['columns'] ?? []);
        $circumstances = $disposal->circumstances ?? $disposal->reason ?? '';
        $propertyNumber = $unitService->resolvePropertyNumber($disposal);
        $acquisitionCost = $unitService->resolveAcquisitionCost($disposal);

        $values = [];
        OwwaCellMapping::applyHeader($values, (array) ($map['header'] ?? []), [
            'entity_name' => $office?->name ?? '',
            'fund_cluster' => '',
            'department_office' => $department?->name ?? $office?->name ?? '',
            'rlsddp_no' => $disposal->reference_code ?? '',
            'accountable_officer' => $disposal->custodian_printed_name ?? '',
            'rlsddp_date' => $disposal->disposal_date?->format('Y-m-d') ?? '',
            'designation' => $disposal->accountable_officer_designation ?? '',
            'par_no' => $par?->reference_code ?? '',
            'par_date' => $par?->issuance_date?->format('Y-m-d') ?? '',
        ]);

        $values[OwwaCellMapping::columnCell($cols['property_no'] ?? 'B', $detailStart)] = $propertyNumber ?? '';
        $values[OwwaCellMapping::columnCell($cols['description'] ?? 'C', $detailStart)] = self::itemDescription($item);
        $values[OwwaCellMapping::columnCell($cols['acquisition_cost'] ?? 'G', $detailStart)] = $acquisitionCost !== null
            ? (float) $acquisitionCost
            : '';

        $extra = (array) ($map['extra'] ?? []);
        if (isset($extra['circumstances'])) {
            $values[$extra['circumstances']] = $circumstances;
        }

        self::applyRlsddpPoliceAndStatus($values, $disposal, $map);
        self::applyRlsddpSignatures($values, $disposal, $map);

        return $values;
    }

    /**
     * @param  array<string, string|int|float|null>  $values
     */
    protected static function applyWmrDisposalMode(array &$values, Disposal $disposal): void
    {
        $marks = (array) (OwwaCellMapping::form('WMR')['disposal_mode_marks'] ?? []);
        $itemNo = (string) ($disposal->wmr_inspection_item_no ?? 1);
        $mode = $disposal->disposal_mode;

        $cell = match ($mode) {
            'destroyed' => $marks['destroyed'] ?? null,
            'sold_private' => $marks['sold_private'] ?? null,
            'sold_public' => $marks['sold_public'] ?? null,
            'transferred_without_cost' => $marks['transferred_without_cost'] ?? null,
            default => null,
        };

        if ($cell !== null) {
            $values[$cell] = $itemNo;
        }
    }

    /**
     * @param  array<string, string|int|float|null>  $values
     */
    protected static function applyWmrSignatures(array &$values, Disposal $disposal): void
    {
        $signatures = (array) (OwwaCellMapping::form('WMR')['signatures'] ?? []);
        $pairs = [
            'prepared_by' => $disposal->custodian_printed_name ?? '',
            'approved_by' => $disposal->approved_by_printed_name ?? '',
            'inspected_by' => $disposal->inspection_officer_printed_name ?? '',
            'witness' => $disposal->witness_printed_name ?? '',
        ];

        foreach ($pairs as $field => $value) {
            if (isset($signatures[$field])) {
                $values[$signatures[$field]] = $value;
            }
        }
    }

    /**
     * @param  array<string, string|int|float|null>  $values
     */
    protected static function applyIirupDisposalMode(array &$values, Disposal $disposal, string $formCode, int $detailStart): void
    {
        $modeCols = (array) (OwwaCellMapping::form($formCode)['disposal_mode_columns'] ?? []);
        $mode = $disposal->iirup_disposal_mode;
        $row = $detailStart;

        $col = match ($mode) {
            'sale' => $modeCols['sale'] ?? null,
            'transfer' => $modeCols['transfer'] ?? null,
            'destruction' => $modeCols['destruction'] ?? null,
            'others' => $modeCols['others'] ?? null,
            default => null,
        };

        if ($col !== null) {
            $values[OwwaCellMapping::columnCell($col, $row)] = $disposal->iirup_disposal_amount !== null
                ? (float) $disposal->iirup_disposal_amount
                : '';
        }
    }

    /**
     * @param  array<string, string|int|float|null>  $values
     */
    protected static function applyIirupSignatures(array &$values, Disposal $disposal, string $formCode): void
    {
        $signatures = (array) (OwwaCellMapping::form($formCode)['signatures'] ?? []);
        $pairs = [
            'custodian' => $disposal->custodian_printed_name ?? '',
            'approved_by' => $disposal->approved_by_printed_name ?? '',
            'inspection_officer' => $disposal->inspection_officer_printed_name ?? '',
            'witness' => $disposal->witness_printed_name ?? '',
            'accountable_designation' => $disposal->accountable_officer_designation ?? '',
            'authorized_designation' => $disposal->authorized_official_designation ?? '',
        ];

        foreach ($pairs as $field => $value) {
            if (isset($signatures[$field])) {
                $values[$signatures[$field]] = $value;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $map
     * @param  array<string, string|int|float|null>  $values
     */
    protected static function applyRlsddpPoliceAndStatus(array &$values, Disposal $disposal, array $map): void
    {
        $police = (array) ($map['police'] ?? []);
        $statusMarks = (array) ($map['property_status_marks'] ?? []);
        $status = $disposal->property_status;

        if ($disposal->police_notified === true) {
            if (isset($police['yes_mark'])) {
                $values[$police['yes_mark']] = 'X';
            }
            if (isset($police['station'])) {
                $values[$police['station']] = $disposal->police_station ?? '';
            }
            if (isset($police['date'])) {
                $values[$police['date']] = $disposal->police_notified_date?->format('Y-m-d') ?? '';
            }
        } elseif ($disposal->police_notified === false && isset($police['no_mark'])) {
            $values[$police['no_mark']] = 'X';
        }

        $statusCell = match ($status) {
            'lost' => $statusMarks['lost'] ?? null,
            'stolen' => $statusMarks['stolen'] ?? null,
            'damaged' => $statusMarks['damaged'] ?? null,
            'destroyed' => $statusMarks['destroyed'] ?? null,
            default => null,
        };

        if ($statusCell !== null) {
            $values[$statusCell] = 'X';
        }

        $govId = (array) ($map['gov_id'] ?? []);
        if (isset($govId['type'])) {
            $values[$govId['type']] = 'Government Issued ID : '.($disposal->gov_id_type ?? '');
        }
        if (isset($govId['number'])) {
            $values[$govId['number']] = 'ID No. : '.($disposal->gov_id_no ?? '');
        }
        if (isset($govId['date_issued'])) {
            $values[$govId['date_issued']] = 'Date Issued : '.($disposal->gov_id_date_issued?->format('Y-m-d') ?? '');
        }
    }

    /**
     * @param  array<string, mixed>  $map
     * @param  array<string, string|int|float|null>  $values
     */
    protected static function applyRlsddpSignatures(array &$values, Disposal $disposal, array $map): void
    {
        $signatures = (array) ($map['signatures'] ?? []);
        $date = $disposal->disposal_date?->format('Y-m-d') ?? '';
        $pairs = [
            'accountable_officer' => $disposal->custodian_printed_name ?? '',
            'noted_by' => $disposal->immediate_supervisor_printed_name ?? $disposal->approved_by_printed_name ?? '',
            'accountable_date' => $date,
            'noted_date' => $date,
        ];

        foreach ($pairs as $field => $value) {
            if (isset($signatures[$field])) {
                $values[$signatures[$field]] = $value;
            }
        }
    }

    protected static function lookupDateAcquired(?int $itemId): ?string
    {
        if ($itemId === null) {
            return null;
        }

        $date = Acquisition::query()
            ->where('item_id', $itemId)
            ->orderByDesc('acquisition_date')
            ->value('acquisition_date');

        return $date?->format('Y-m-d');
    }

    protected static function itemDescription(?Item $item): string
    {
        if ($item === null) {
            return '';
        }

        $parts = array_filter([$item->name, $item->description]);

        return implode(' — ', $parts);
    }
}
