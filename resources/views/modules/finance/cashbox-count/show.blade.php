@extends('layouts.app')

@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
@endphp
@section('title', __('cashbox_count.document_title', ['document' => $count->doc_num]))

@section('content')
<div data-cashbox-count-document>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">{{ __('cashbox_count.document_title', ['document' => $count->doc_num]) }}</h1>
        @unless($print)
            <div class="d-flex gap-2">
                <a class="btn btn-falcon-default btn-sm" target="_blank" href="{{ route('admin.finance.cashbox-count.print', $count) }}">{{ __('cashbox_count.actions.print') }}</a>
                @if($count->status === \Modules\Finance\Models\CashboxCount::StatusSaved)
                    @can('finance.cashbox_count.reopen')
                        <form method="POST" action="{{ route('admin.finance.cashbox-count.reopen', $count) }}">
                            @csrf
                            <button class="btn btn-falcon-warning btn-sm" type="submit">{{ __('cashbox_count.actions.reopen') }}</button>
                        </form>
                    @endcan
                @endif
            </div>
        @endunless
    </div>

    <div class="card">
        <div class="card-body">
            <div class="row g-3">
                @foreach([
                    'count_date' => $dates->formatDate($count->count_date, ''),
                    'cashbox' => $count->cashbox?->name,
                    'branch' => $count->branch?->name,
                    'currency' => $count->currency?->code,
                    'book_balance' => $numbers->format($count->book_balance),
                    'counted_balance' => $numbers->format($count->actual_amount),
                    'variance' => $numbers->format($count->variance),
                    'status' => __('cashbox_count.statuses.'.$count->status),
                    'creator' => $count->createdBy?->name,
                ] as $key => $value)
                    <div class="col-12 col-md-4">
                        <div class="text-600 small">{{ __('cashbox_count.columns.'.$key) }}</div>
                        <div class="fw-semibold">{{ filled($value) ? $value : __('cashbox_count.values.not_available') }}</div>
                    </div>
                @endforeach
                <div class="col-12">
                    <div class="text-600 small">{{ __('cashbox_count.columns.notes') }}</div>
                    <div>{{ filled($count->notes) ? $count->notes : __('cashbox_count.values.not_available') }}</div>
                </div>
            </div>
        </div>
    </div>

    @if(! $print && $count->status === \Modules\Finance\Models\CashboxCount::StatusReopened)
        @can('finance.cashbox_count.edit')
            <div class="card mt-3">
                <div class="card-header"><h2 class="h6 mb-0">{{ __('cashbox_count.edit_title') }}</h2></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.finance.cashbox-count.update', $count) }}" class="row g-3">
                        @csrf
                        @method('PUT')
                        <div class="col-md-4">
                            <x-forms.label :label="__('cashbox_count.columns.counted_balance')" required />
                            <x-forms.numeric-input name="actual_amount" :value="$count->actual_amount" :scale="4" min="0" step="0.0001" required />
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">{{ __('cashbox_count.columns.notes') }}</label>
                            <x-forms.input class="form-control" name="notes" value="{{ $count->notes }}" />
                        </div>
                        <div class="col-12"><button class="btn btn-falcon-primary" type="submit">{{ __('cashbox_count.actions.save') }}</button></div>
                    </form>
                </div>
            </div>
        @endcan
    @endif
</div>
@endsection

@if($print)
    @push('scripts')
        <script>window.addEventListener('load', () => window.print());</script>
    @endpush
@endif
