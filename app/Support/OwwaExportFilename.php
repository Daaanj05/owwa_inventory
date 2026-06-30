<?php

namespace App\Support;

use App\Models\PhysicalCountSession;
use DateTimeInterface;
use Illuminate\Support\Carbon;

class OwwaExportFilename
{
    /**
     * Single-record export: {FORM}-{reference}.xlsx
     */
    public static function transaction(string $formCode, string $reference, string $ext = 'xlsx'): string
    {
        $formCode = self::sanitizeSegment($formCode);
        $reference = self::sanitizeSegment($reference);

        if (str_starts_with(strtoupper($reference), strtoupper($formCode).'-')) {
            return $reference.'.'.$ext;
        }

        return $formCode.'-'.$reference.'.'.$ext;
    }

    /**
     * Merged / bulk workbook: {FORM}-batch-{Y-m-d_His}.xlsx
     */
    public static function batch(string $formCode, ?DateTimeInterface $at = null, string $ext = 'xlsx'): string
    {
        $timestamp = Carbon::make($at ?? now())?->format('Y-m-d_His') ?? now()->format('Y-m-d_His');

        return self::sanitizeSegment($formCode).'-batch-'.$timestamp.'.'.$ext;
    }

    /**
     * Bulk merged workbook label from OwwaBulkExportController.
     */
    public static function bulkWorkbook(string $label, ?DateTimeInterface $at = null, string $ext = 'xlsx'): string
    {
        $formCode = match (strtolower($label)) {
            'requisitions' => 'RIS',
            'transfers' => 'PTR',
            'acquisitions' => 'SC',
            'disposals' => 'Disposal',
            'par' => 'PAR',
            'ics' => 'ICS',
            'pc' => 'PC',
            'annexa1' => 'AnnexA1',
            'issuances' => 'Issuances',
            default => ucfirst(strtolower($label)),
        };

        return self::batch($formCode, $at, $ext);
    }

    /**
     * Item-level report: {FORM}-{item_code}.xlsx
     */
    public static function itemReport(string $formSlug, string $itemCode, string $ext = 'xlsx'): string
    {
        return self::transaction(self::itemFormCode($formSlug), $itemCode, $ext);
    }

    public static function itemFormCode(string $formSlug): string
    {
        return match ($formSlug) {
            'sc' => 'SC',
            'pc' => 'PC',
            'annex_a1' => 'AnnexA1',
            'annex_a4' => 'AnnexA4',
            default => strtoupper($formSlug),
        };
    }

    public static function physicalCount(string $countType, string $referenceCode, string $ext = 'xlsx'): string
    {
        $formCode = match ($countType) {
            PhysicalCountSession::TYPE_RPCPPE => 'RPCPPE',
            PhysicalCountSession::TYPE_RPCSP => 'RPCSP',
            default => 'RPCI',
        };

        return self::transaction($formCode, $referenceCode, $ext);
    }

    /**
     * COA PDF: COA-{Type}-{date|reference|batch-timestamp}.pdf
     */
    public static function coaReport(string $reportType, string $identifier, bool $isBatch = false, string $ext = 'pdf'): string
    {
        $type = self::sanitizeSegment($reportType);

        if ($isBatch) {
            return 'COA-'.$type.'-batch-'.self::sanitizeSegment($identifier).'.'.$ext;
        }

        return 'COA-'.$type.'-'.self::sanitizeSegment($identifier).'.'.$ext;
    }

    public static function qrLabel(string $context, string $identifier, string $ext = 'pdf'): string
    {
        return 'QR-'.self::sanitizeSegment($context).'-'.self::sanitizeSegment($identifier).'.'.$ext;
    }

    /**
     * Named CSV export: {Name}-{Y-m-d}.csv
     */
    public static function csvExport(string $name, ?DateTimeInterface $at = null): string
    {
        $date = Carbon::make($at ?? now())?->format('Y-m-d') ?? now()->format('Y-m-d');

        return self::sanitizeSegment($name).'-'.$date.'.csv';
    }

    public static function sanitizeSegment(string $value): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($value));

        return $sanitized !== '' && $sanitized !== null ? $sanitized : 'export';
    }
}
