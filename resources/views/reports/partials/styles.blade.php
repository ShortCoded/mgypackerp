<style>
    body {
        font-family: {{ $pdfFontFamily ?? 'dejavusans' }}, sans-serif;
        font-size: 9.5px;
        color: #1f2937;
        line-height: 1.35;
    }

    .report-header {
        width: 100%;
        border-collapse: collapse;
    }

    .report-header td {
        vertical-align: middle;
    }

    .report-logo {
        max-width: 110px;
        max-height: 60px;
        width: auto;
        height: auto;
        object-fit: contain;
    }

    .report-company-name {
        color: #1f2937;
        font-size: 11px;
        font-weight: 700;
    }

    .report-title {
        color: #1f2937;
        font-size: 15px;
        font-weight: 700;
        text-align: center;
    }

    .report-meta {
        color: #64748b;
        font-size: 8.5px;
        line-height: 1.45;
    }

    .report-header-rule {
        border-bottom: 1px solid #9aa7b8;
        margin-top: 7px;
        height: 1px;
    }

    .report-footer-rule {
        border-top: 1px solid #cbd5e1;
        margin-bottom: 5px;
        height: 1px;
    }

    .report-footer {
        width: 100%;
        border-collapse: collapse;
        color: #64748b;
        font-size: 8.5px;
    }

    .report-footer td {
        vertical-align: top;
    }

    .report-footer-info,
    .report-footer-company {
        line-height: 1.45;
    }

    .report-page-number {
        font-weight: 700;
        color: #334155;
    }

    .report-warning {
        background: #fff7ed;
        border: 1px solid #fed7aa;
        color: #9a3412;
        padding: 6px;
        margin-bottom: 8px;
    }

    .report-table {
        width: 100%;
        border-collapse: collapse;
    }

    .report-table th {
        background: #edf2f9;
        color: #344050;
        font-weight: 700;
        white-space: nowrap;
    }

    .report-table th,
    .report-table td {
        border: 1px solid #d8e2ef;
        padding: 4.5px;
        vertical-align: top;
    }

    .report-table td {
        color: #1f2937;
    }
</style>
