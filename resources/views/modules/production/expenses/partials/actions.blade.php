@php
    $isTrashed = $record->trashed();
    $canView = ! $isTrashed && auth()->user()?->can('production.expenses.view');
    $canEdit = ! $isTrashed && $canChange && auth()->user()?->can('production.expenses.edit');
    $canClone = ! $isTrashed && auth()->user()?->can('production.expenses.clone');
    $canDelete = ! $isTrashed && $canChange && auth()->user()?->can('production.expenses.delete');
    $canRestore = $isTrashed && auth()->user()?->can('production.expenses.restore');
    $canPrint = ! $isTrashed && auth()->user()?->can('production.expenses.print');
@endphp

<div class="dropstart font-sans-serif position-static d-inline-block">
    <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal float-end" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-reference="parent" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}"><span class="fas fa-ellipsis-h fs-10"></span></button>
    <div class="py-2 border dropdown-menu dropdown-menu-end">
        @if($canView)<a class="dropdown-item" href="{{ route('admin.production.expenses.show', $record) }}">{{ __('common.actions.view') }}</a>@endif
        @if($canEdit)<a class="dropdown-item" href="{{ route('admin.production.expenses.edit', $record) }}">{{ __('common.actions.edit') }}</a>@endif
        @if($canClone)<a class="dropdown-item" href="{{ route('admin.production.expenses.clone', $record) }}">{{ __('common.actions.clone_record') }}</a>@endif
        @if($canPrint)<a class="dropdown-item" target="_blank" rel="noopener" href="{{ route('admin.production.expenses.print', $record) }}">{{ __('common.actions.print') }}</a>@endif
        @if($record->status === \Modules\Production\Models\ProductionExpenseRequest::StatusSubmitted && auth()->user()?->can('production.expenses.approve'))
            <div class="dropdown-divider"></div><button class="dropdown-item text-success" type="button" data-action="post" data-url="{{ route('admin.production.expenses.approve', $record) }}">{{ __('production_execution.actions.approve') }}</button>
        @endif
        @if($record->status === \Modules\Production\Models\ProductionExpenseRequest::StatusApproved && auth()->user()?->can('production.expenses.pay'))
            <button class="dropdown-item text-primary" type="button" data-action="post" data-url="{{ route('admin.production.expenses.pay', $record) }}">{{ __('production_execution.actions.pay') }}</button>
        @endif
        @if($record->status === \Modules\Production\Models\ProductionExpenseRequest::StatusPaid && auth()->user()?->can('production.expenses.reverse'))
            <button class="dropdown-item text-danger" type="button" data-action="reason" data-reason-key="reason" data-prompt="{{ __('production_execution.messages.reversal_reason_required') }}" data-url="{{ route('admin.production.expenses.reverse', $record) }}">{{ __('production_execution.actions.reverse') }}</button>
        @endif
        @if($canDelete)<div class="dropdown-divider"></div><button class="dropdown-item text-danger" type="button" data-action="delete" data-confirm="{{ __('production_execution.messages.confirm_delete') }}" data-url="{{ route('admin.production.expenses.destroy', $record) }}">{{ __('common.actions.delete') }}</button>@endif
        @if($canRestore)<button class="dropdown-item text-success" type="button" data-action="restore" data-url="{{ route('admin.production.expenses.restore', $record->doc_num) }}">{{ __('common.actions.restore') }}</button>@endif
    </div>
</div>
