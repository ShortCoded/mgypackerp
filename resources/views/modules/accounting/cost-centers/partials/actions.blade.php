@php
    $isTrashed = $costCenter->trashed();
    $isProtectedRoot = $costCenter->isProtectedRoot();
    $canView = auth()->user()?->can('cost_centers.view') && $costCenter->doc_num !== null;
    $canEdit = ! $isTrashed && auth()->user()?->can('cost_centers.edit') && $costCenter->doc_num !== null;
    $canClone = ! $isTrashed && auth()->user()?->can('cost_centers.clone') && $costCenter->doc_num !== null;
    $canDelete = ! $isTrashed && ! $isProtectedRoot && auth()->user()?->can('cost_centers.delete') && $costCenter->doc_num !== null;
    $canRestore = $isTrashed && auth()->user()?->can('cost_centers.restore') && $costCenter->doc_num !== null;
@endphp

@if ((! $isTrashed && ($canView || $canEdit || $canClone || $canDelete)) || ($isTrashed && ($canView || $canRestore)))
    <div class="dropstart font-sans-serif position-static d-inline-block">
        <button class="btn btn-link text-600 btn-sm dropdown-toggle dropdown-caret-none btn-reveal float-end" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-reference="parent" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}">
            <span class="fas fa-ellipsis-h fs-10"></span>
        </button>
        <div class="py-2 border dropdown-menu dropdown-menu-end">
            @if ($isTrashed)
                @if ($canView)
                    <a class="dropdown-item" href="{{ route('admin.accounting.cost-centers.show', $costCenter->doc_num) }}" data-doc-num="{{ $costCenter->doc_num }}">
                        {{ __('common.actions.view') }}
                    </a>
                @endif
                @if ($canRestore)
                    @if ($canView)
                        <div class="dropdown-divider"></div>
                    @endif
                    <button type="button" class="dropdown-item text-success js-restore-record" data-doc-num="{{ $costCenter->doc_num }}" data-record-name="{{ $costCenter->name }}" data-restore-url="{{ route('admin.accounting.cost-centers.restore', $costCenter->doc_num) }}">
                        {{ __('cost_centers.trash.restore') }}
                    </button>
                @endif
            @else
                @if ($canView)
                    <a class="dropdown-item" href="{{ route('admin.accounting.cost-centers.show', $costCenter->doc_num) }}" data-doc-num="{{ $costCenter->doc_num }}">
                        {{ __('common.actions.view') }}
                    </a>
                @endif
                @if ($canEdit)
                    <a class="dropdown-item js-edit-record" href="{{ route('admin.accounting.cost-centers.edit', $costCenter->doc_num) }}" data-doc-num="{{ $costCenter->doc_num }}">
                        {{ __('common.actions.edit') }}
                    </a>
                @endif
                @if ($canClone)
                    <a class="dropdown-item js-clone-record" href="{{ route('admin.accounting.cost-centers.clone', $costCenter->doc_num) }}" data-doc-num="{{ $costCenter->doc_num }}">
                        {{ __('common.actions.clone_record') }}
                    </a>
                @endif
                @if ($canDelete)
                    <div class="dropdown-divider"></div>
                    <button type="button" class="dropdown-item text-danger js-delete-record" data-doc-num="{{ $costCenter->doc_num }}" data-record-name="{{ $costCenter->name }}" data-delete-url="{{ route('admin.accounting.cost-centers.destroy', $costCenter->doc_num) }}">
                        {{ __('common.actions.delete') }}
                    </button>
                @endif
            @endif
        </div>
    </div>
@endif
