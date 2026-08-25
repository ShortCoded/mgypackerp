@php
    $buttons = [];
    if ($record->isReceived() && $record->status === \Modules\Finance\Models\Cheque::StatusReceived && auth()->user()?->can('cheques.mark_deposited')) {
        $buttons[] = ['key' => 'mark_deposited', 'url' => route('admin.finance.cheques.mark-deposited', $record->doc_num), 'class' => 'btn-falcon-info'];
    }
    if ($record->isReceived() && $record->status === \Modules\Finance\Models\Cheque::StatusDeposited && auth()->user()?->can('cheques.mark_collected')) {
        $buttons[] = ['key' => 'mark_collected', 'url' => route('admin.finance.cheques.mark-collected', $record->doc_num), 'class' => 'btn-falcon-success'];
    }
    if ((($record->isReceived() && in_array($record->status, ['received', 'deposited'], true)) || ($record->isIssued() && in_array($record->status, ['issued', 'delivered'], true))) && auth()->user()?->can('cheques.mark_returned')) {
        $buttons[] = ['key' => 'mark_returned', 'url' => route('admin.finance.cheques.mark-returned', $record->doc_num), 'class' => 'btn-falcon-warning'];
    }
    if ($record->isIssued() && $record->status === \Modules\Finance\Models\Cheque::StatusDraft && auth()->user()?->can('cheques.mark_issued')) {
        $buttons[] = ['key' => 'mark_issued', 'url' => route('admin.finance.cheques.mark-issued', $record->doc_num), 'class' => 'btn-falcon-info'];
    }
    if ($record->isIssued() && $record->status === \Modules\Finance\Models\Cheque::StatusIssued && auth()->user()?->can('cheques.mark_delivered')) {
        $buttons[] = ['key' => 'mark_delivered', 'url' => route('admin.finance.cheques.mark-delivered', $record->doc_num), 'class' => 'btn-falcon-info'];
    }
    if ($record->isIssued() && in_array($record->status, ['issued', 'delivered'], true) && auth()->user()?->can('cheques.mark_cleared')) {
        $buttons[] = ['key' => 'mark_cleared', 'url' => route('admin.finance.cheques.mark-cleared', $record->doc_num), 'class' => 'btn-falcon-success'];
    }
    if ($record->isIssued() && $record->status === \Modules\Finance\Models\Cheque::StatusClearingReversed && auth()->user()?->can('cheques.mark_issued')) {
        $buttons[] = ['key' => 'represent', 'url' => route('admin.finance.cheques.represent', $record->doc_num), 'class' => 'btn-falcon-info'];
    }
    $canReverseClearing = $record->isIssued() && $record->status === \Modules\Finance\Models\Cheque::StatusCleared && auth()->user()?->can('cheques.mark_cleared');
    $canCancel = (($record->isReceived() && in_array($record->status, ['received', 'deposited'], true)) || ($record->isIssued() && in_array($record->status, ['draft', 'issued', 'delivered', 'clearing_reversed'], true))) && auth()->user()?->can('cheques.cancel');
@endphp

@foreach($buttons as $button)
    <button type="button" class="btn {{ $button['class'] }} btn-sm js-cheque-status-action" data-url="{{ $button['url'] }}">
        {{ __('cheques.actions.'.$button['key']) }}
    </button>
@endforeach

@if($canReverseClearing)
    <button type="button" class="btn btn-falcon-warning btn-sm js-cheque-reverse-clearing" data-url="{{ route('admin.finance.cheques.reverse-clearing', $record->doc_num) }}">
        {{ __('cheques.actions.reverse_clearing') }}
    </button>
@endif

@if($canCancel)
    <button type="button" class="btn btn-falcon-warning btn-sm js-cheque-cancel" data-url="{{ route('admin.finance.cheques.cancel', $record->doc_num) }}">
        {{ __('cheques.actions.cancel') }}
    </button>
@endif
