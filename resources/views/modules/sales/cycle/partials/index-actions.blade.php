@php
    $trashed = $record->trashed();
    $workflow = [];
    if (!$trashed && $kind === 'sales_requests') {
        $states = match($record->status) {
            'draft', 'rejected' => ['submitted' => ['edit', __('Submit for approval')]],
            'submitted' => ['approved' => ['approve', __('Approve')], 'rejected' => ['approve', __('Reject')]],
            default => [],
        };
        foreach ($states as $status => [$permission, $label]) {
            $workflow[] = ['url' => route($prefix.'.transition', $record), 'permission' => $kind.'.'.$permission, 'label' => $label, 'status' => $status, 'reason' => $status === 'rejected'];
        }
    }
    if (!$trashed && $kind === 'sales_orders') {
        if (in_array($record->status, ['draft', 'reopened'])) {
            $workflow[] = ['url' => route($prefix.'.submit', $record), 'permission' => 'sales_orders.edit', 'label' => __('Submit for approval')];
        }
        if (in_array($record->status, ['draft', 'pending_approval', 'held_credit'])) {
            $workflow[] = ['url' => route($prefix.'.approve', $record), 'permission' => 'sales_orders.approve', 'label' => __('Approve')];
        }
        if (in_array($record->status, ['pending_approval', 'held_credit'])) {
            $workflow[] = ['url' => route($prefix.'.reject', $record), 'permission' => 'sales_orders.reject', 'label' => __('Reject'), 'reason' => true];
        }
        if (in_array($record->status, ['approved', 'rejected', 'closed'], true) && ! $record->has_amendment_quantities) {
            $workflow[] = ['url' => route($prefix.'.reopen', $record), 'permission' => 'sales_orders.reopen', 'label' => __('Reopen for Amendment'), 'reason' => true];
        }
        if (! in_array($record->status, ['cancelled', 'closed'], true) && ! $record->has_fulfillment_quantities && ! $record->has_active_production_orders) {
            $workflow[] = ['url' => route($prefix.'.cancel', $record), 'permission' => 'sales_orders.cancel', 'label' => __('Cancel'), 'reason' => true];
        }
    }
    if (!$trashed && $kind === 'customer_invoices' && in_array($record->status, ['draft', 'reopened']) && !$record->is_closed) {
        $workflow[] = ['url' => route($prefix.'.post', $record), 'permission' => 'customer_invoices.post', 'label' => __('Post')];
    }
    if (!$trashed && $kind === 'sales_returns') {
        if ($record->status === 'pending_authorization') {
            $workflow[] = ['url' => route($prefix.'.authorize', $record), 'permission' => 'sales_returns.authorize', 'label' => __('Authorize Return')];
        }
        if ($record->status === 'authorized') {
            $workflow[] = ['url' => route($prefix.'.receive', $record), 'permission' => 'sales_returns.receive', 'label' => __('Receive for Quality Inspection')];
        }
        if ($record->status === 'inspected' || ($record->status === 'authorized' && (int) $record->physical_lines_count === 0)) {
            $workflow[] = ['url' => route($prefix.'.close', $record), 'permission' => 'sales_returns.close', 'label' => __('Close and Post Credit Note')];
        }
        if (!in_array($record->status, ['closed', 'cancelled'])) {
            $workflow[] = ['url' => route($prefix.'.cancel', $record), 'permission' => 'sales_returns.cancel', 'label' => __('Cancel'), 'reason' => true];
        }
    }
    if (!$trashed && $kind === 'customer_receipts' && $record->status === 'approved') {
        $workflow[] = ['url' => route($prefix.'.reverse', $record), 'permission' => 'customer_receipts.cancel', 'label' => __('Cancel'), 'reason' => true];
    }
    $editable = !$trashed && match($kind) {
        'sales_requests' => in_array($record->status, ['draft', 'rejected']),
        'sales_orders', 'customer_invoices' => $record->isEditable(),
        default => false,
    };
    $deletable = !$trashed && ($canDeleteDraft ?? false);
@endphp
<div class="dropstart font-sans-serif position-static d-inline-block">
    <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-label="{{ __('common.fields.actions') }}"><span class="fas fa-ellipsis-h fs-10"></span></button>
    <div class="dropdown-menu dropdown-menu-end py-2">
        @if(!$trashed)
            <a class="dropdown-item" href="{{ route($prefix.'.show', $record) }}">{{ __('common.actions.view') }}</a>
            @if($editable)@can($kind.'.edit')<a class="dropdown-item" href="{{ route($prefix.'.edit', $record) }}">{{ __('common.actions.edit') }}</a>@endcan @endif
            @can($kind.'.print')<a class="dropdown-item" href="{{ route($prefix.'.print', $record) }}" target="_blank">{{ __('common.actions.print') }}</a>@endcan
            @foreach($workflow as $action)
                @can($action['permission'])<button class="dropdown-item js-sales-index-action" type="button" data-url="{{ $action['url'] }}" data-status="{{ $action['status'] ?? '' }}" data-reason="{{ ($action['reason'] ?? false) ? '1' : '0' }}">{{ $action['label'] }}</button>@endcan
            @endforeach
            @if($deletable)@can($kind.'.delete')<button class="dropdown-item text-danger js-sales-index-action" type="button" data-url="{{ route($prefix.'.destroy', $record) }}" data-method="DELETE">{{ __('common.actions.delete') }}</button>@endcan @endif
        @elseif(in_array($kind, ['sales_requests', 'sales_orders']) && $record->status === 'draft')
            @can($kind.'.restore')<button class="dropdown-item text-success js-sales-index-action" type="button" data-url="{{ route($prefix.'.restore', $record->doc_num) }}" data-method="PATCH">{{ __('common.actions.restore') }}</button>@endcan
        @endif
    </div>
</div>
