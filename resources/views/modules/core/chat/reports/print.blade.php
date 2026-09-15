<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ config('languages.available.'.app()->getLocale().'.dir', 'rtl') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        body { color: #1f2937; font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; margin: 24px; }
        .print-toolbar { align-items: center; display: flex; justify-content: space-between; margin-bottom: 16px; }
        .print-button { background: #2563eb; border: 0; border-radius: 5px; color: #fff; cursor: pointer; padding: 8px 14px; }
        h1 { font-size: 22px; margin: 0; } h2 { font-size: 17px; margin: 0 0 10px; }
        .report-filter-summary { background: #f3f4f6; border: 1px solid #d1d5db; margin-bottom: 14px; padding: 8px; }
        .report-filter-summary span { display: inline-block; margin-inline-end: 12px; }
        .transcript-conversation { break-after: page; page-break-after: always; }
        .transcript-conversation:last-of-type { break-after: auto; page-break-after: auto; }
        table { border-collapse: collapse; margin-bottom: 14px; table-layout: fixed; width: 100%; }
        th, td { border: 1px solid #cbd5e1; padding: 7px; text-align: start; vertical-align: top; word-break: break-word; }
        th { background: #eaf2ff; } .meta-table th { width: 15%; } .sender-column { width: 18%; } .date-column { width: 17%; }
        .reference { border-inline-start: 3px solid #3b82f6; color: #475569; margin-bottom: 5px; padding-inline-start: 6px; }
        .attachments { background: #f8fafc; margin-top: 7px; padding: 6px; }
        .deleted-message { background: #fff1f2; } .deleted-label { color: #be123c; font-weight: bold; margin-top: 4px; }
        .attachment-note { color: #64748b; font-size: 10px; }
        @media print { body { margin: 0; } .print-toolbar { display: none; } }
    </style>
</head>
<body>
    <div class="print-toolbar">
        <h1>{{ $title }}</h1>
        <button class="print-button" type="button" onclick="window.print()">{{ __('chat.report.actions.print') }}</button>
    </div>
    @include('modules.core.chat.reports._transcript')
</body>
</html>
