@php
    $isTrashed = $record->trashed();
    $canView = auth()->user()?->can('fund_transfers.view') && $record->doc_num !== null;
    $canClone = ! $isTrashed && auth()->user()?->can('fund_transfers.clone') && $record->doc_num !== null;
    $canEdit = ! $isTrashed && ! $record->isLockedForEditing() && auth()->user()?->can('fund_transfers.edit') && $record->doc_num !== null;
    $canDelete = ! $isTrashed && $record->isDeletable() && auth()->user()?->can('fund_transfers.delete') && $record->doc_num !== null;
    $canRestore = $isTrashed && $record->isDeletable() && auth()->user()?->can('fund_transfers.restore') && $record->doc_num !== null;
    $canApprove = ! $isTrashed && $record->isDraft() && auth()->user()?->can('fund_transfers.approve') && $record->doc_num !== null;
    $canCancel = ! $isTrashed && ! $record->isCancelled() && auth()->user()?->can('fund_transfers.cancel') && $record->doc_num !== null;
@endphp

@if ((! $isTrashed && ($canView || $canEdit || $canClone || $canApprove || $canCancel || $canDelete)) || ($isTrashed && ($canView || $canRestore)))
    <div class="dropstart font-sans-serif position-static d-inline-block">
        <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal float-end" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-reference="parent" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}">
            <span class="fas fa-ellipsis-h fs-10"></span>
        </button>
        <div class="py-2 border dropdown-menu dropdown-menu-end">
            @if ($canView)
                <a class="dropdown-item" href="{{ route('admin.finance.fund-transfers.show', $record->doc_num) }}" data-doc-num="{{ $record->doc_num }}">{{ __('common.actions.view') }}</a>
            @endif
            @if ($isTrashed)
                @if ($canRestore)
                    @if ($canView)<div class="dropdown-divider"></div>@endif
                    <button type="button" class="dropdown-item text-success js-restore-record" data-doc-num="{{ $record->doc_num }}" data-restore-url="{{ route('admin.finance.fund-transfers.restore', $record->doc_num) }}">{{ __('common.actions.restore') }}</button>
                @endif
            @else
                @if ($canEdit)
                    <a class="dropdown-item js-edit-record" href="{{ route('admin.finance.fund-transfers.edit', $record->doc_num) }}" data-doc-num="{{ $record->doc_num }}">{{ __('common.actions.edit') }}</a>
                @endif
                @if ($canClone)
                    <a class="dropdown-item js-clone-record" href="{{ route('admin.finance.fund-transfers.clone', $record->doc_num) }}" data-doc-num="{{ $record->doc_num }}">{{ __('common.actions.clone_record') }}</a>
                @endif
                @if ($canApprove || $canCancel || $canDelete)
                    <div class="dropdown-divider"></div>
                @endif
                @if ($canApprove)
                    <button type="button" class="dropdown-item text-success js-fund-transfer-approve" data-doc-num="{{ $record->doc_num }}" data-url="{{ route('admin.finance.fund-transfers.approve', $record->doc_num) }}">{{ __('fund_transfers.actions.approve') }}</button>
                @endif
                @if ($canCancel)
                    <button type="button" class="dropdown-item text-warning js-fund-transfer-cancel" data-doc-num="{{ $record->doc_num }}" data-url="{{ route('admin.finance.fund-transfers.cancel', $record->doc_num) }}">{{ __('fund_transfers.actions.cancel') }}</button>
                @endif
                @if ($canDelete)
                    <button type="button" class="dropdown-item text-danger js-delete-record" data-doc-num="{{ $record->doc_num }}" data-delete-url="{{ route('admin.finance.fund-transfers.destroy', $record->doc_num) }}">{{ __('common.actions.delete') }}</button>
                @endif
            @endif
        </div>
    </div>
@endif
