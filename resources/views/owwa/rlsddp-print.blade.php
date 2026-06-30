<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        body { font-family: system-ui, sans-serif; font-size: 12px; max-width: 900px; margin: 1rem auto; padding: 0 1rem; }
        h1 { font-size: 1rem; text-align: center; margin-bottom: 1rem; }
        .header-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem 2rem; margin-bottom: 1rem; }
        .header-grid dt { font-weight: 600; }
        table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
        th, td { border: 1px solid #333; padding: 6px 8px; text-align: left; }
        th { background: #eee; }
        .signature-block { margin-top: 1.5rem; display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .signature-block .name { border-bottom: 1px solid #333; padding: 4px 0; min-height: 1.5em; }
        .signature-block .label { font-size: 0.85em; color: #555; margin-top: 2px; }
        .circumstances { border: 1px solid #333; padding: 8px; min-height: 4em; margin: 1rem 0; }
        .meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem 2rem; margin: 1rem 0; }
        @media print { body { margin: 0; } }
    </style>
</head>
<body>
    <h1>REPORT OF LOST, STOLEN, DAMAGED OR DESTROYED PROPERTY</h1>
    <p style="text-align: center; font-size: 0.9em;">Appendix 75 - RLSDDP</p>

    <dl class="header-grid">
        <div>
            <dt>Entity Name</dt>
            <dd>{{ $disposal->office?->name ?? '—' }}</dd>
        </div>
        <div>
            <dt>Fund Cluster</dt>
            <dd>{{ $disposal->office?->fund_cluster ?? '—' }}</dd>
        </div>
        <div>
            <dt>Department/Office</dt>
            <dd>{{ $disposal->office?->name ?? '—' }}</dd>
        </div>
        <div>
            <dt>RLSDDP No.</dt>
            <dd>{{ $disposal->reference_code ?? '—' }}</dd>
        </div>
        <div>
            <dt>Accountable Officer</dt>
            <dd>{{ $disposal->custodian_printed_name ?? '—' }}</dd>
        </div>
        <div>
            <dt>RLSDDP Date</dt>
            <dd>{{ $disposal->disposal_date?->format('Y-m-d') ?? '—' }}</dd>
        </div>
        <div>
            <dt>Designation</dt>
            <dd>{{ $disposal->accountable_officer_designation ?? '—' }}</dd>
        </div>
        <div>
            <dt>{{ $disposal->item?->category?->getTemplateSlug() === 'semi_expendable' ? 'ICS No.' : 'PAR No.' }}</dt>
            <dd>{{ $disposal->parIssuance?->reference_code ?? '—' }}</dd>
        </div>
        <div>
            <dt>{{ $disposal->item?->category?->getTemplateSlug() === 'semi_expendable' ? 'ICS Date' : 'PAR Date' }}</dt>
            <dd>{{ $disposal->parIssuance?->issuance_date?->format('Y-m-d') ?? '—' }}</dd>
        </div>
    </dl>

    <table>
        <thead>
            <tr>
                <th>Property No.</th>
                <th>Description</th>
                <th>Acquisition Cost</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $propertyNumber ?? '—' }}</td>
                <td>{{ $disposal->item?->name }}{{ $disposal->item?->description ? ' — ' . $disposal->item->description : '' }}</td>
                <td>{{ $acquisitionCost !== null ? number_format((float) $acquisitionCost, 2) : '—' }}</td>
            </tr>
        </tbody>
    </table>

    <div class="meta-grid">
        <div>
            <strong>Property status:</strong>
            {{ match ($disposal->property_status) {
                'lost' => 'Lost',
                'stolen' => 'Stolen',
                'damaged' => 'Damaged',
                'destroyed' => 'Destroyed',
                default => '—',
            } }}
        </div>
        <div>
            <strong>Police notified:</strong>
            {{ $disposal->police_notified === true ? 'Yes' : ($disposal->police_notified === false ? 'No' : '—') }}
        </div>
        @if ($disposal->police_notified)
            <div>
                <strong>Police station:</strong> {{ $disposal->police_station ?? '—' }}
            </div>
            <div>
                <strong>Police notification date:</strong> {{ $disposal->police_notified_date?->format('Y-m-d') ?? '—' }}
            </div>
        @endif
    </div>

    <p><strong>Circumstances:</strong></p>
    <div class="circumstances">{{ $disposal->circumstances ?? $disposal->reason ?? '—' }}</div>

    @if (filled($disposal->gov_id_type) || filled($disposal->gov_id_no))
        <div class="meta-grid">
            <div><strong>Government ID:</strong> {{ $disposal->gov_id_type ?? '—' }}</div>
            <div><strong>ID No.:</strong> {{ $disposal->gov_id_no ?? '—' }}</div>
            <div><strong>ID date issued:</strong> {{ $disposal->gov_id_date_issued?->format('Y-m-d') ?? '—' }}</div>
        </div>
    @endif

    <div class="signature-block">
        <div>
            <div class="name">{{ $disposal->custodian_printed_name ?? '_____________________________' }}</div>
            <div class="label">Signature over Printed Name of the Accountable Officer</div>
            <div class="label">{{ $disposal->disposal_date?->format('Y-m-d') ?? '' }}</div>
        </div>
        <div>
            <div class="name">{{ $disposal->immediate_supervisor_printed_name ?? $disposal->approved_by_printed_name ?? '_____________________________' }}</div>
            <div class="label">Signature over Printed Name of the Immediate Supervisor</div>
            <div class="label">{{ $disposal->disposal_date?->format('Y-m-d') ?? '' }}</div>
        </div>
    </div>
    <p style="margin-top: 0.5rem; font-size: 0.9em;">(Sign physically on this form when printing.)</p>
</body>
</html>
