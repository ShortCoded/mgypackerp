@php($status = $record->trashed() ? 'deleted' : ($screen === 'goods_receipts' ? $record->posting_status : $record->status))
<span class="badge badge-subtle-{{ in_array($status, ['approved', 'posted', 'fully_converted']) ? 'success' : (in_array($status, ['cancelled','rejected','deleted','reversed']) ? 'danger' : 'primary') }}">{{ __('procurement.ui.statuses.'.$status) }}</span>
