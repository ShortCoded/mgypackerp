@php
    $isTrashed = $record->trashed();
    $canView = auth()->user()?->can('cheques.view') && $record->doc_num !== null;
    $canClone = ! $isTrashed && auth()->user()?->can('cheques.clone') && $record->doc_num !== null;
    $canEdit = ! $isTrashed && ! $record->isLockedForEditing() && auth()->user()?->can('cheques.edit') && $record->doc_num !== null;
    $canDelete = ! $isTrashed && $record->isDeletable() && auth()->user()?->can('cheques.delete') && $record->doc_num !== null;
    $canRestore = $isTrashed && $record->isDeletable() && auth()->user()?->can('cheques.restore') && $record->doc_num !== null;
    $actions = [];
    if (! $isTrashed && $record->isReceived() && $record->status === \Modules\Finance\Models\Cheque::StatusReceived && auth()->user()?->can('cheques.mark_deposited')) {
        $actions[] = ['key' => 'mark_deposited', 'url' => route('admin.finance.cheques.mark-deposited', $record->doc_num), 'class' => 'text-info'];
    }
    if (! $isTrashed && $record->isReceived() && $record->status === \Modules\Finance\Models\Cheque::StatusDeposited && auth()->user()?->can('cheques.mark_collected')) {
        $actions[] = ['key' => 'mark_collected', 'url' => route('admin.finance.cheques.mark-collected', $record->doc_num), 'class' => 'text-success'];
    }
    if (! $isTrashed && (($record->isReceived() && in_array($record->status, ['received', 'deposited'], true)) || ($record->isIssued() && in_array($record->status, ['issued', 'delivered'], true))) && auth()->user()?->can('cheques.mark_returned')) {
        $actions[] = ['key' => 'mark_returned', 'url' => route('admin.finance.cheques.mark-returned', $record->doc_num), 'class' => 'text-warning'];
    }
    if (! $isTrashed && $record->isIssued() && $record->status === \Modules\Finance\Models\Cheque::StatusDraft && auth()->user()?->can('cheques.mark_issued')) {
        $actions[] = ['key' => 'mark_issued', 'url' => route('admin.finance.cheques.mark-issued', $record->doc_num), 'class' => 'text-info'];
    }
    if (! $isTrashed && $record->isIssued() && $record->status === \Modules\Finance\Models\Cheque::StatusIssued && auth()->user()?->can('cheques.mark_delivered')) {
        $actions[] = ['key' => 'mark_delivered', 'url' => route('admin.finance.cheques.mark-delivered', $record->doc_num), 'class' => 'text-info'];
    }
    if (! $isTrashed && $record->isIssued() && in_array($record->status, ['issued', 'delivered'], true) && auth()->user()?->can('cheques.mark_cleared')) {
        $actions[] = ['key' => 'mark_cleared', 'url' => route('admin.finance.cheques.mark-cleared', $record->doc_num), 'class' => 'text-success'];
    }
    $canCancel = ! $isTrashed && (($record->isReceived() && in_array($record->status, ['received', 'deposited'], true)) || ($record->isIssued() && in_array($record->status, ['draft', 'issued', 'delivered'], true))) && auth()->user()?->can('cheques.cancel');
@endphp

@if ((! $isTrashed && ($canView || $canEdit || $canClone || $actions || $canCancel || $canDelete)) || ($isTrashed && ($canView || $canRestore)))
    <div class="dropstart font-sans-serif position-static d-inline-block">
        <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal float-end" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-reference="parent" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}">
            <span class="fas fa-ellipsis-h fs-10"></span>
        </button>
        <div class="py-2 border dropdown-menu dropdown-menu-end">
            @if ($canView)
                <a class="dropdown-item" href="{{ route('admin.finance.cheques.show', $record->doc_num) }}" data-doc-num="{{ $record->doc_num }}">{{ __('common.actions.view') }}</a>
            @endif
            @if ($isTrashed)
                @if ($canRestore)
                    @if ($canView)<div class="dropdown-divider"></div>@endif
                    <button type="button" class="dropdown-item text-success js-restore-record" data-doc-num="{{ $record->doc_num }}" data-restore-url="{{ route('admin.finance.cheques.restore', $record->doc_num) }}">{{ __('common.actions.restore') }}</button>
                @endif
            @else
                @if ($canEdit)
                    <a class="dropdown-item js-edit-record" href="{{ route('admin.finance.cheques.edit', $record->doc_num) }}" data-doc-num="{{ $record->doc_num }}">{{ __('common.actions.edit') }}</a>
                @endif
                @if ($canClone)
                    <a class="dropdown-item js-clone-record" href="{{ route('admin.finance.cheques.clone', $record->doc_num) }}" data-doc-num="{{ $record->doc_num }}">{{ __('common.actions.clone_record') }}</a>
                @endif
                @if ($actions || $canCancel || $canDelete)
                    <div class="dropdown-divider"></div>
                @endif
                @foreach($actions as $action)
                    <button type="button" class="dropdown-item {{ $action['class'] }} js-cheque-status-action" data-doc-num="{{ $record->doc_num }}" data-url="{{ $action['url'] }}">{{ __('cheques.actions.'.$action['key']) }}</button>
                @endforeach
                @if ($canCancel)
                    <button type="button" class="dropdown-item text-warning js-cheque-cancel" data-doc-num="{{ $record->doc_num }}" data-url="{{ route('admin.finance.cheques.cancel', $record->doc_num) }}">{{ __('cheques.actions.cancel') }}</button>
                @endif
                @if ($canDelete)
                    <button type="button" class="dropdown-item text-danger js-delete-record" data-doc-num="{{ $record->doc_num }}" data-delete-url="{{ route('admin.finance.cheques.destroy', $record->doc_num) }}">{{ __('common.actions.delete') }}</button>
                @endif
            @endif
        </div>
    </div>
@endif
