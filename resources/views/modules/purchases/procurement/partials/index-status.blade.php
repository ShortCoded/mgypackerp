@php($status = $record->trashed() ? 'deleted' : ($screen === 'goods_receipts' ? $record->posting_status : $record->status))
<x-status-indicator :status="$status" :label="__('procurement.ui.statuses.'.$status)" />
