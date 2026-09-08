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

    .report-filter-summary {
        background: #f8fafc;
        border: 1px solid #d8e2ef;
        border-radius: 4px;
        color: #344050;
        font-size: 8.2px;
        line-height: 1.45;
        margin-bottom: 8px;
        padding: 6px 8px;
    }

    .business-partner-report-table {
        table-layout: fixed;
    }

    .business-partner-report-table th,
    .business-partner-report-table td {
        font-size: 7.4px;
        line-height: 1.3;
        overflow-wrap: break-word;
        vertical-align: top;
    }

    .document-identity-table,
    .document-meta-table,
    .document-totals-table,
    .document-authorization-table {
        border-collapse: collapse;
        margin-bottom: 9px;
        width: 100%;
    }

    .document-identity-table td,
    .document-meta-table td,
    .document-totals-table th,
    .document-totals-table td {
        border: 1px solid #d8e2ef;
        padding: 5px;
        vertical-align: top;
    }

    .document-identity-table {
        color: #475569;
        font-size: 7.8px;
    }

    .document-title-row {
        margin-bottom: 9px;
    }

    .document-title-row h1 {
        font-size: 16px;
        margin: 0 0 3px;
    }

    .document-status {
        background: #edf2f9;
        border: 1px solid #d8e2ef;
        display: inline-block;
        padding: 3px 6px;
    }

    .document-authorization-table {
        margin-top: 16px;
        page-break-inside: avoid;
    }

    .document-authorization-table td {
        border-top: 1px solid #94a3b8;
        height: 80px;
        padding: 8px;
        text-align: center;
        vertical-align: top;
        width: 50%;
    }

    .document-authorization-table img {
        max-height: 55px;
        max-width: 120px;
    }

    .report-table thead {
        display: table-header-group;
    }

    .report-table tr,
    .document-meta-table,
    .document-totals-table {
        page-break-inside: avoid;
    }

    .text-end {
        text-align: right;
    }

    [dir="rtl"] .text-end {
        text-align: left;
    }
    .operational-print { font-size: 10.5px; line-height: 1.55; color: #172b3a; }
    .operational-print .document-title-row { border-bottom: 2px solid #23475b; padding-bottom: 8px; margin-bottom: 12px; }
    .operational-print .document-title-row h1 { font-size: 21px; color: #17374b; margin: 0 0 4px; }
    .operational-print .report-table th { background: #e9f0f3; color: #183b50; white-space: normal; padding: 7px 5px; }
    .operational-print .report-table td { padding: 7px 5px; border-color: #dbe4e8; }
    .operational-print .report-table tr:nth-child(even) td { background: #f7f9fa; }
    .operational-print .document-meta-table td { border: 0; border-bottom: 1px solid #e2e8ed; padding: 7px; }
    .operational-print .document-meta-table strong { color: #526672; font-size: 9px; }
    .document-item-code { color: #526672; font-size: 9px; }
    .document-item-details { color: #526672; font-size: 9px; margin-top: 3px; }
    .document-signatures { width: 100%; border-collapse: collapse; margin-top: 24px; page-break-inside: avoid; }
    .document-signatures td { text-align: center; padding: 9px; border-top: 1px solid #718692; vertical-align: top; }
    .document-signature-space { height: 35px; }
    .document-amount-words { margin-top: 10px; padding: 8px; background: #f1f5f7; }
    .document-notes { margin-top: 12px; white-space: pre-line; }
    .document-terms { width: 100%; border-collapse: collapse; margin-top: 10px; }
    .document-terms td { width: 50%; vertical-align: top; border-top: 1px solid #dbe4e8; padding: 8px; }
    .document-terms tr { page-break-inside: avoid; }
    .document-terms p { margin: 3px 0; }
    .operational-print h3 { font-size: 11px; margin: 12px 0 5px; }
    .sales-copy-designation { border-radius: 3px; font-size: 9px; font-weight: 700; margin: 0 0 8px; padding: 4px 8px; text-align: center; }
    .sales-copy-designation-operational { background: #eef4f7; border: 1px solid #cbdbe3; color: #355368; }
    .sales-copy-designation-legal { background: #e8f2ff; border: 1px solid #7bb3f4; color: #145ca8; }
</style>
