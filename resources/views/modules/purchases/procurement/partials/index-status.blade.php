@php
    $status = $record->trashed() ? 'deleted' : match ($screen) {
        'goods_receipts' => $record->posting_status,
        'goods_receipt_inspections' => $record->result,
        default => $record->status,
    };
    if ($screen === 'goods_receipts' && $record->posting_status === 'unposted' && $record->qc_status !== 'not_required') {
        $status = $record->qc_status;
    }
@endphp
<x-status-indicator :status="$status" :label="__('procurement.statuses.'.$status)" />
