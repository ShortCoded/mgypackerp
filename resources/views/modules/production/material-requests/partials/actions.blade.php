@php
    $isTrashed = $record->trashed();
    $canView = ! $isTrashed && auth()->user()?->can('production.material_requests.view');
    $canEdit = ! $isTrashed && $canChange && auth()->user()?->can('production.material_requests.edit');
    $canClone = ! $isTrashed && auth()->user()?->can('production.material_requests.clone');
    $canDelete = ! $isTrashed && $canChange && auth()->user()?->can('production.material_requests.delete');
    $canRestore = $isTrashed && auth()->user()?->can('production.material_requests.restore');
    $canPrint = ! $isTrashed && auth()->user()?->can('production.material_requests.print');
@endphp

<div class="dropstart font-sans-serif position-static d-inline-block">
    <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal float-end" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-reference="parent" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}"><span class="fas fa-ellipsis-h fs-10"></span></button>
    <div class="py-2 border dropdown-menu dropdown-menu-end">
        @if($canView)<a class="dropdown-item" href="{{ route('admin.production.material-requests.show', $record) }}">{{ __('common.actions.view') }}</a>@endif
        @if($canEdit)<a class="dropdown-item" href="{{ route('admin.production.material-requests.edit', $record) }}">{{ __('common.actions.edit') }}</a>@endif
        @if($canClone)<a class="dropdown-item" href="{{ route('admin.production.material-requests.clone', $record) }}">{{ __('common.actions.clone_record') }}</a>@endif
        @if($canPrint)<a class="dropdown-item" target="_blank" rel="noopener" href="{{ route('admin.production.material-requests.print', $record) }}">{{ __('common.actions.print') }}</a>@endif
        @if($record->status === \Modules\Production\Models\ProductionMaterialRequest::StatusSubmitted && auth()->user()?->can('production.material_requests.approve'))
            <div class="dropdown-divider"></div><button class="dropdown-item text-success" type="button" data-action="post" data-url="{{ route('admin.production.material-requests.approve', $record) }}">{{ __('production_execution.actions.approve') }}</button>
        @endif
        @if(in_array($record->status, [\Modules\Production\Models\ProductionMaterialRequest::StatusShortage, \Modules\Production\Models\ProductionMaterialRequest::StatusPartiallyIssued], true) && $hasShortage && auth()->user()?->can('production.material_requests.approve'))
            <button class="dropdown-item text-warning" type="button" data-action="post" data-url="{{ route('admin.production.material-requests.allocate-shortage', $record) }}">{{ __('production_execution.actions.recheck_stock') }}</button>
        @endif
        @if(in_array($record->status, [\Modules\Production\Models\ProductionMaterialRequest::StatusApproved, \Modules\Production\Models\ProductionMaterialRequest::StatusShortage, \Modules\Production\Models\ProductionMaterialRequest::StatusPartiallyIssued], true) && auth()->user()?->can('production.material_requests.issue'))
            <a class="dropdown-item text-primary" href="{{ route('admin.production.material-requests.show', $record) }}#production-material-partial-issue">{{ __('production_execution.actions.issue') }}</a>
        @endif
        @if($canDelete)<div class="dropdown-divider"></div><button class="dropdown-item text-danger" type="button" data-action="delete" data-confirm="{{ __('production_execution.messages.confirm_delete') }}" data-url="{{ route('admin.production.material-requests.destroy', $record) }}">{{ __('common.actions.delete') }}</button>@endif
        @if($canRestore)<button class="dropdown-item text-success" type="button" data-action="restore" data-url="{{ route('admin.production.material-requests.restore', $record->doc_num) }}">{{ __('common.actions.restore') }}</button>@endif
    </div>
</div>
