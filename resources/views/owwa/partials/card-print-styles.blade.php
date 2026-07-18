<style>
    @page {
        size: legal landscape;
        margin: 8mm 10mm 12mm 10mm;
    }

    body {
        font-family: DejaVu Sans, sans-serif;
        font-size: 8.5px;
        color: #000;
        margin: 0;
    }

    .owwa-form-card {
        width: 100%;
        page-break-inside: avoid;
    }

    .owwa-form-brand {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 4px;
    }

    .owwa-form-brand td {
        vertical-align: middle;
        padding: 0;
    }

    .owwa-form-brand-logo {
        width: 88px;
        height: auto;
        max-height: 78px;
    }

    .owwa-form-brand-logo--left {
        text-align: left;
        width: 110px;
        padding: 2px 4px 2px 0;
    }

    .owwa-form-brand-logo--right {
        text-align: right;
        width: 110px;
        padding: 2px 0 2px 4px;
    }

    .owwa-form-brand-center {
        text-align: center;
        vertical-align: middle;
        padding: 0 8px;
    }

    .owwa-form-meta {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 2px;
    }

    .owwa-form-meta td {
        vertical-align: top;
        padding: 0;
    }

    .owwa-form-appendix {
        text-align: right;
        font-size: 9px;
        font-weight: 700;
    }

    .owwa-form-agency {
        text-align: center;
        font-size: 9px;
        line-height: 1.3;
        margin: 0;
    }

    .owwa-form-title {
        text-align: center;
        font-size: 13px;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        margin: 4px 0 0;
    }

    .owwa-form-header {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 6px;
    }

    .owwa-form-header td {
        padding: 2px 4px;
        vertical-align: bottom;
        border-bottom: 1px solid #000;
    }

    .owwa-form-header .field-label {
        width: 18%;
        font-weight: 700;
        border-bottom: none;
        white-space: nowrap;
    }

    .owwa-form-header .field-value {
        width: 32%;
    }

    .owwa-form-header .field-label-right {
        width: 18%;
        font-weight: 700;
        border-bottom: none;
        white-space: nowrap;
        text-align: right;
        padding-right: 6px;
    }

    .owwa-form-header .field-span {
        width: 82%;
    }

    .owwa-form-ledger {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }

    .owwa-form-ledger th,
    .owwa-form-ledger td {
        border: 1px solid #000;
        padding: 3px 4px;
        vertical-align: middle;
        word-wrap: break-word;
    }

    .owwa-form-ledger thead th {
        background: #f3f3f3;
        font-weight: 700;
        text-align: center;
        font-size: 8px;
    }

    .owwa-form-ledger .subhead th {
        font-weight: 600;
        font-size: 7.5px;
    }

    .owwa-form-ledger .c { text-align: center; }
    .owwa-form-ledger .r { text-align: right; }
    .owwa-form-ledger .l { text-align: left; }

    .owwa-form-empty {
        text-align: center;
        font-style: italic;
        color: #444;
        padding: 8px;
    }

    .owwa-pdf-page-break {
        page-break-after: always;
    }

    /* DomPDF repeats fixed elements on every page */
    .owwa-pdf-disclaimer {
        position: fixed;
        right: 10mm;
        bottom: 4mm;
        margin: 0;
        color: #666;
        font-size: 8px;
        font-style: italic;
        text-align: right;
    }
</style>
