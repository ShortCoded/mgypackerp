<style>
    .report-filter-summary { background: #f3f4f6; border: 1px solid #d1d5db; margin-bottom: 12px; padding: 7px; }
    .report-filter-summary span { display: inline-block; margin: 0 0 4px 10px; }
    .transcript-conversation { page-break-after: always; }
    .transcript-conversation:last-of-type { page-break-after: auto; }
    .transcript-conversation h2 { color: #1e3a5f; font-size: 16px; margin: 0 0 8px; }
    .chat-transcript-report table { border-collapse: collapse; margin-bottom: 12px; table-layout: fixed; width: 100%; }
    .chat-transcript-report th, .chat-transcript-report td { border: 1px solid #cbd5e1; padding: 6px; text-align: start; vertical-align: top; word-wrap: break-word; }
    .chat-transcript-report th { background: #eaf2ff; }
    .meta-table th { width: 15%; } .sender-column { width: 18%; } .date-column { width: 17%; }
    .reference { border-right: 3px solid #3b82f6; color: #475569; margin-bottom: 4px; padding-right: 5px; }
    .attachments { background: #f8fafc; margin-top: 6px; padding: 5px; }
    .deleted-message { background: #fff1f2; } .deleted-label { color: #be123c; font-weight: bold; margin-top: 3px; }
    .attachment-note { color: #64748b; font-size: 9px; }
</style>

@include('modules.core.chat.reports._transcript')
