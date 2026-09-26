@php
    $canPostMovement = auth()->user()?->can('inventory.documents.post');
    $canCreateSalesIssue = ! $record && $mode === 'create' && auth()->user()?->can('inventory.documents.issue');
@endphp

@if($canPostMovement || $canCreateSalesIssue)
    @if($canView || $canEdit || $canList || $canClone)
        <div class="dropdown-divider"></div>
    @endif
    @if($canPostMovement)
        <button class="dropdown-item js-finance-submit-action" type="submit" data-submit-action="post_and_view">
            <span class="fas fa-check me-1 text-success"></span>{{ __('inventory.movements.actions.post_and_view') }}
        </button>
    @endif
    @if($canCreateSalesIssue)
        <a class="dropdown-item" href="{{ route('admin.inventory.documents.sales-issue.create') }}">
            <span class="fas fa-file-invoice me-1"></span>{{ __('sales_issue.warehouse_issue') }}
        </a>
    @endif
@endif
