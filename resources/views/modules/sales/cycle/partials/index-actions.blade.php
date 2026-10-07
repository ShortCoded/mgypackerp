@php
    $trashed = $record->trashed();
    $workflow = [];
    $openDocumentType = null;
    if (!$trashed && $kind === 'sales_requests') {
        $states = match($record->status) {
            'draft', 'rejected', 'reopened' => [
                'submitted' => ['edit', __('Submit for approval')],
                'cancelled' => ['cancel', __('Cancel')],
            ],
            'submitted' => [
                'approved' => ['approve', __('Approve')],
                'rejected' => ['approve', __('Reject')],
                'cancelled' => ['cancel', __('Cancel')],
            ],
            'approved' => [
                'closed' => ['cancel', __('Close')],
            ],
            'partially_converted' => ['closed' => ['cancel', __('Close')]],
            default => [],
        };
        if ((($record->approved_at !== null || $record->closed_at !== null) && ! ($record->status === 'reopened' && $record->isEditable()))
            || (bool) $record->getAttribute('has_converted_lines')
            || (bool) $record->getAttribute('has_quotations')
            || (bool) $record->getAttribute('has_orders')
            || (bool) $record->getAttribute('has_direct_invoices')) {
            unset($states['cancelled']);
        }
        foreach ($states as $status => [$permission, $label]) {
            $workflow[] = ['url' => route($prefix.'.transition', $record), 'permission' => $kind.'.'.$permission, 'label' => $label, 'status' => $status, 'reason' => in_array($status, ['rejected', 'cancelled', 'closed'], true)];
        }
        $canReopenRequest = in_array($record->status, ['approved', 'partially_converted', 'converted'], true);
        if ($canReopenRequest) {
            $openDocumentType = 'sales_requests';
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
        if (in_array($record->status, ['approved', 'partially_fulfilled', 'fulfilled', 'rejected', 'closed'], true)) {
            $openDocumentType = 'sales_orders';
        }
        if ($record->canCancelSafely()) {
            $workflow[] = ['url' => route($prefix.'.cancel', $record), 'permission' => 'sales_orders.cancel', 'label' => __('Cancel'), 'reason' => true];
        }
    }
    if (!$trashed && $kind === 'customer_invoices' && in_array($record->status, ['draft', 'reopened']) && !$record->is_closed) {
        $workflow[] = ['url' => route($prefix.'.post', $record), 'permission' => 'customer_invoices.post', 'label' => __('Post')];
    }
    if (!$trashed && $kind === 'customer_invoices' && $record->document_type === 'invoice' && $record->posting_status === 'posted') {
        $openDocumentType = 'customer_invoices';
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
        if (in_array($record->status, ['pending_authorization', 'authorized'], true)) {
            $workflow[] = ['url' => route($prefix.'.cancel', $record), 'permission' => 'sales_returns.cancel', 'label' => __('Cancel'), 'reason' => true];
        }
        if ($record->status === 'received') {
            $workflow[] = ['url' => route($prefix.'.correct-receipt', $record), 'permission' => 'sales_returns.correct_receipt', 'label' => __('sales_return_correction.action'), 'reason' => true];
        }
        if ($record->status === 'inspected') {
            $workflow[] = ['url' => route($prefix.'.correct-disposition', $record), 'permission' => 'sales_returns.correct_disposition', 'label' => __('sales_return_correction.inspected_action'), 'reason' => true];
        }
        if ($record->status === 'closed') {
            $workflow[] = ['url' => route($prefix.'.correct-closed', $record), 'permission' => 'sales_returns.correct_closed', 'label' => __('sales_return_correction.closed_action'), 'reason' => true];
        }
    }
    if (!$trashed && $kind === 'customer_receipts' && $record->status === 'approved') {
        $workflow[] = ['url' => route($prefix.'.reverse', $record), 'permission' => 'customer_receipts.cancel', 'label' => __('Cancel'), 'reason' => true];
    }
    $editable = !$trashed && match($kind) {
        'sales_requests' => $record->isEditable(),
        'sales_orders', 'customer_invoices' => $record->isEditable(),
        default => false,
    };
    $deletable = !$trashed && ($canDeleteDraft ?? false);
@endphp
<div class="dropstart font-sans-serif position-static d-inline-block">
    <button class="btn btn-link text-600 btn-sm dropdown-toggle dropdown-caret-none btn-reveal" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-label="{{ __('common.fields.actions') }}"><span class="fas fa-ellipsis-h fs-10"></span></button>
    <div class="dropdown-menu dropdown-menu-end py-2">
        @if(!$trashed)
            <a class="dropdown-item" href="{{ route($prefix.'.show', $record) }}">{{ __('common.actions.view') }}</a>
            <x-document-owner-actions :record="$record" />
            @if($editable)@can($kind.'.edit')<a class="dropdown-item" href="{{ route($prefix.'.edit', $record) }}">{{ __('common.actions.edit') }}</a>@endcan @endif
            @can($kind.'.print')<a class="dropdown-item" href="{{ route($prefix.'.print', $record) }}" target="_blank">{{ __('common.actions.print') }}</a>@endcan
            @foreach($workflow as $action)
                @can($action['permission'])<button class="dropdown-item js-sales-index-action" type="button" data-url="{{ $action['url'] }}" data-status="{{ $action['status'] ?? '' }}" data-reason="{{ ($action['reason'] ?? false) ? '1' : '0' }}">{{ $action['label'] }}</button>@endcan
            @endforeach
            @if($openDocumentType) @can($kind.'.reopen')<a class="dropdown-item" href="{{ route('admin.tools.open-documents.index', ['document_type' => $openDocumentType, 'from_number' => $record->doc_number, 'to_number' => $record->doc_number]) }}">{{ __('open_documents.actions.review_edit_reopen') }}</a>@endcan @endif
            @if($deletable)@can($kind.'.delete')<button class="dropdown-item text-danger js-sales-index-action" type="button" data-url="{{ route($prefix.'.destroy', $record) }}" data-method="DELETE">{{ __('common.actions.delete') }}</button>@endcan @endif
        @elseif(($kind === 'sales_requests' && in_array($record->status, ['draft', 'reopened'], true))
            || ($kind === 'sales_orders' && in_array($record->status, ['draft', 'cancelled'], true))
            || ($kind === 'customer_invoices' && (($record->status === 'cancelled' && (int) $record->posting_revision > 0)
                || ($record->status === 'draft' && $record->document_type === 'invoice'
                    && (in_array($record->source_type, [null, 'direct', 'sales_order'], true) || $record->source_type === 'sales_request')))))
            @can($kind.'.restore')<button class="dropdown-item text-success js-sales-index-action" type="button" data-url="{{ route($prefix.'.restore', $record->doc_num) }}" data-method="PATCH">{{ __('common.actions.restore') }}</button>@endcan
        @endif
    </div>
</div>
