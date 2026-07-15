@php
    $isTrashed = $record->trashed();
    $canView = auth()->user()?->can('quotations.view') && $record->doc_num !== null;
    $canClone = ! $isTrashed && auth()->user()?->can('quotations.clone') && $record->doc_num !== null;
    $canEdit = ! $isTrashed && auth()->user()?->can('quotations.edit') && $record->doc_num !== null;
    $canDelete = ! $isTrashed && auth()->user()?->can('quotations.delete') && $record->doc_num !== null;
    $canRestore = $isTrashed && auth()->user()?->can('quotations.restore') && $record->doc_num !== null;
@endphp

@if ((! $isTrashed && ($canView || $canEdit || $canClone || $canDelete)) || ($isTrashed && ($canView || $canRestore)))
    <div class="dropstart font-sans-serif position-static d-inline-block">
        <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal float-end" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-reference="parent" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}">
            <span class="fas fa-ellipsis-h fs-10"></span>
        </button>
        <div class="py-2 border dropdown-menu dropdown-menu-end">
            @if ($canView)
                <a class="dropdown-item" href="{{ route('admin.sales.quotations.show', $record->doc_num) }}" data-doc-num="{{ $record->doc_num }}">
                    {{ __('common.actions.view') }}
                </a>
            @endif

            @if ($isTrashed)
                @if ($canRestore)
                    <button type="button" class="dropdown-item text-success js-restore-record" data-doc-num="{{ $record->doc_num }}" data-restore-url="{{ route('admin.sales.quotations.restore', $record->doc_num) }}">
                        {{ __('common.actions.restore') }}
                    </button>
                @endif
            @else
                @if ($canEdit)
                    <a class="dropdown-item js-edit-record" href="{{ route('admin.sales.quotations.edit', $record->doc_num) }}" data-doc-num="{{ $record->doc_num }}">
                        {{ __('common.actions.edit') }}
                    </a>
                @endif
                @if ($canClone)
                    <a class="dropdown-item js-clone-record" href="{{ route('admin.sales.quotations.clone', $record->doc_num) }}" data-doc-num="{{ $record->doc_num }}">
                        {{ __('common.actions.clone_record') }}
                    </a>
                @endif
                @if ($canDelete)
                    <div class="dropdown-divider"></div>
                    <button type="button" class="dropdown-item text-danger js-delete-record" data-doc-num="{{ $record->doc_num }}" data-delete-url="{{ route('admin.sales.quotations.destroy', $record->doc_num) }}">
                        {{ __('common.actions.delete') }}
                    </button>
                @endif
            @endif
        </div>
    </div>
@endif
