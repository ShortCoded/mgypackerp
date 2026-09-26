@php
    $canPostMovement = auth()->user()?->can('inventory.documents.post');
    $canCreateSalesIssue = ! $record && $mode === 'create' && auth()->user()?->can('inventory.documents.issue');
@endphp

@include('modules.finance.partials.form-actions', [
    'mode' => $mode,
    'record' => $record,
    'resource' => 'inventory.documents',
    'routePrefix' => 'admin.inventory.documents',
    'extraDropdownActionsView' => $canPostMovement || $canCreateSalesIssue
        ? 'modules.inventory.documents.partials.dropdown-actions'
        : null,
])
