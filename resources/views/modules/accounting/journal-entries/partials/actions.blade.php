<div class="dropdown font-sans-serif position-static">
    <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal" type="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
        <span class="fas fa-ellipsis-h fs--1"></span>
    </button>
    <div class="dropdown-menu dropdown-menu-end border py-0">
        <div class="py-2">
            @can('journal_entries.view')
                <a class="dropdown-item" href="{{ $entry->trashed() ? route('admin.accounting.journal-entries.trashed.show', $entry->doc_num) : route('admin.accounting.journal-entries.show', $entry->doc_num) }}">{{ __('common.actions.view') }}</a>
            @endcan
            @if($entry->trashed())
                @can('journal_entries.restore')
                    <button class="dropdown-item js-journal-entry-restore" type="button" data-url="{{ route('admin.accounting.journal-entries.restore', $entry->doc_num) }}">{{ __('common.actions.restore') }}</button>
                @endcan
            @elseif($entry->status === \Modules\Accounting\Models\JournalEntry::StatusDraft && ! $entry->is_system_generated)
                @can('journal_entries.edit')
                    <a class="dropdown-item" href="{{ route('admin.accounting.journal-entries.edit', $entry->doc_num) }}">{{ __('common.actions.edit') }}</a>
                @endcan
                @can('journal_entries.post')
                    <button class="dropdown-item text-success js-journal-entry-post" type="button" data-url="{{ route('admin.accounting.journal-entries.post', $entry->doc_num) }}">{{ __('journal_entries.actions.post') }}</button>
                @endcan
                @can('journal_entries.delete')
                    <button class="dropdown-item text-danger js-journal-entry-delete" type="button" data-url="{{ route('admin.accounting.journal-entries.destroy', $entry->doc_num) }}">{{ __('common.actions.delete') }}</button>
                @endcan
            @endif
        </div>
    </div>
</div>
