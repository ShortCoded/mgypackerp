@php
    $isTrashed = $record->trashed();
    $canView = auth()->user()?->can('price_lists.view') && $record->doc_num !== null;
    $canEdit = ! $isTrashed && auth()->user()?->can('price_lists.edit') && $record->doc_num !== null;
    $canClone = ! $isTrashed && auth()->user()?->can('price_lists.clone') && $record->doc_num !== null;
    $canDelete = ! $isTrashed && auth()->user()?->can('price_lists.delete') && $record->doc_num !== null;
    $canRestore = $isTrashed && auth()->user()?->can('price_lists.restore') && $record->doc_num !== null;
    $canUseTrashed = ! $isTrashed || auth()->user()?->can('price_lists.view_trashed');
    $canPrint = $canView && $canUseTrashed && auth()->user()?->can('price_lists.print');
    $canExport = $canView && $canUseTrashed && auth()->user()?->can('price_lists.export');
@endphp

@if ($canView || $canEdit || $canClone || $canDelete || $canRestore || $canPrint || $canExport)
    <div class="dropstart font-sans-serif position-static d-inline-block">
        <button class="btn btn-link text-600 btn-sm dropdown-toggle dropdown-caret-none btn-reveal float-end" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-reference="parent" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}">
            <span class="fas fa-ellipsis-h fs-10"></span>
        </button>
        <div class="py-2 border dropdown-menu dropdown-menu-end">
            @if ($canView)
                <a class="dropdown-item" href="{{ route('admin.sales.price-lists.show', $record->doc_num) }}" data-doc-num="{{ $record->doc_num }}">
                    {{ __('common.actions.view') }}
                </a>
            @endif
            @if ($canPrint)
                <a class="dropdown-item" href="{{ route('admin.sales.price-lists.pdf', $record->doc_num) }}">{{ __('price_lists.actions.pdf') }}</a>
            @endif
            @if ($canExport)
                <a class="dropdown-item" href="{{ route('admin.sales.price-lists.export.xlsx', $record->doc_num) }}">{{ __('price_lists.actions.excel') }}</a>
                <a class="dropdown-item" href="{{ route('admin.sales.price-lists.export.csv', $record->doc_num) }}">{{ __('price_lists.actions.csv') }}</a>
            @endif
            @if ($isTrashed)
                @if ($canRestore)
                    <button type="button" class="dropdown-item text-success js-restore-record" data-doc-num="{{ $record->doc_num }}" data-restore-url="{{ route('admin.sales.price-lists.restore', $record->doc_num) }}">
                        {{ __('common.actions.restore') }}
                    </button>
                @endif
            @else
                @if ($canEdit)
                    <a class="dropdown-item js-edit-record" href="{{ route('admin.sales.price-lists.edit', $record->doc_num) }}" data-doc-num="{{ $record->doc_num }}">
                        {{ __('common.actions.edit') }}
                    </a>
                @endif
                @if ($canClone)
                    <a class="dropdown-item" href="{{ route('admin.sales.price-lists.clone', $record->doc_num) }}">
                        {{ __('price_lists.actions.clone') }}
                    </a>
                @endif
                @if ($canEdit)
                    <button type="button" class="dropdown-item js-increase-price-list" data-doc-num="{{ $record->doc_num }}" data-increase-url="{{ route('admin.sales.price-lists.increase-by-percentage', $record->doc_num) }}">
                        {{ __('price_lists.actions.increase') }}
                    </button>
                @endif
                @if ($canView && $record->doc_num !== null)
                    <a class="dropdown-item" href="{{ route('admin.sales.price-lists.history', $record->doc_num) }}">
                        {{ __('price_lists.actions.history') }}
                    </a>
                @endif
                @if ($canDelete)
                    <div class="dropdown-divider"></div>
                    <button type="button" class="dropdown-item text-danger js-delete-record" data-doc-num="{{ $record->doc_num }}" data-delete-url="{{ route('admin.sales.price-lists.destroy', $record->doc_num) }}">
                        {{ __('common.actions.delete') }}
                    </button>
                @endif
            @endif
        </div>
    </div>
@endif
