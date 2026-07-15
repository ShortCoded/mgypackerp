@php
    $badges = [];
    if ($record->trashed()) {
        $badges[] = ['class' => 'danger', 'label' => __('opening_balances.statuses.deleted')];
    } elseif ($record->approved) {
        $badges[] = ['class' => 'success', 'label' => __('opening_balances.statuses.approved')];
    } elseif ($record->is_cancelled) {
        $badges[] = ['class' => 'danger', 'label' => __('opening_balances.statuses.cancelled')];
    } else {
        $badges[] = ['class' => 'secondary', 'label' => __('opening_balances.statuses.draft')];
    }

    if ($record->is_closed) {
        $badges[] = ['class' => 'warning', 'label' => __('opening_balances.statuses.closed')];
    }
@endphp
<div class="d-flex flex-wrap gap-1">
    @foreach($badges as $badge)
        <span class="badge rounded-pill badge-subtle-{{ $badge['class'] }}">{{ $badge['label'] }}</span>
    @endforeach
</div>
