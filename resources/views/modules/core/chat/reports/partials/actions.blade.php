<div class="dropdown d-inline-block">
    <button class="btn btn-sm btn-falcon-default dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
        {{ __('chat.report.actions.view') }}
    </button>
    <div class="dropdown-menu dropdown-menu-end">
        <a class="dropdown-item" href="{{ route('admin.chat.reports.show', $conversation) }}">
            <span class="fas fa-eye me-2"></span>{{ __('chat.report.actions.view') }}
        </a>
        @can('chat.reports.export')
            <a class="dropdown-item" href="{{ route('admin.chat.reports.conversation.excel', $conversation) }}">
                <span class="fas fa-file-excel me-2"></span>{{ __('chat.report.actions.excel') }}
            </a>
        @endcan
        @can('chat.reports.pdf')
            <a class="dropdown-item" href="{{ route('admin.chat.reports.conversation.pdf', $conversation) }}" target="_blank" rel="noopener">
                <span class="fas fa-file-pdf me-2"></span>{{ __('chat.report.actions.pdf') }}
            </a>
        @endcan
        @can('chat.reports.print')
            <a class="dropdown-item" href="{{ route('admin.chat.reports.conversation.print', $conversation) }}" target="_blank" rel="noopener">
                <span class="fas fa-print me-2"></span>{{ __('chat.report.actions.print') }}
            </a>
        @endcan
    </div>
</div>
