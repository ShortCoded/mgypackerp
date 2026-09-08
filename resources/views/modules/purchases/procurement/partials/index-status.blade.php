@php
    $status = $record->trashed() ? 'deleted' : ($screen === 'goods_receipts' ? $record->posting_status : $record->status);
    if ($screen === 'goods_receipts' && $record->posting_status === 'unposted' && $record->qc_status !== 'not_required') {
        $status = $record->qc_status;
    }
@endphp
<x-status-indicator :status="$status" :label="__('procurement.ui.statuses.'.$status)" />
