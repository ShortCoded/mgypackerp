@php
    $badgeClass = match ($record->status) {
        \Modules\Finance\Models\CashVoucher::StatusApproved => 'success',
        \Modules\Finance\Models\CashVoucher::StatusCancelled => 'danger',
        default => 'secondary',
    };
@endphp

<span class="badge rounded-pill badge-subtle-{{ $badgeClass }}">
    {{ __($translationKey.'.statuses.'.($record->trashed() ? 'deleted' : $record->status)) }}
</span>
