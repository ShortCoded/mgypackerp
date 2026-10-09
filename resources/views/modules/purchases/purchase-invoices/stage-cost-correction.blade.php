@extends('layouts.app')
@section('title', __('production_invoice_cost.title').' — '.$record->doc_num)
@section('content')
@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
@php($dates = app(\Modules\Core\Services\DateFormatService::class))
<div class="card mb-3">
    <div class="card-header d-flex flex-wrap justify-content-between gap-2">
        <h5 class="mb-0">{{ __('production_invoice_cost.title') }} — {{ $record->doc_num }}</h5>
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.purchase-invoices.show', $record) }}">{{ __('Back') }}</a>
    </div>
    <div class="card-body"><p>{{ __('production_invoice_cost.help') }}</p><p class="mb-0">{{ __('production_invoice_cost.retained') }}</p></div>
</div>
@if($errors->any())<div class="alert alert-danger">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($blocker)<div class="alert alert-warning">{{ $blocker }}</div>@endif
@if($plan)
<div class="alert alert-info">{{ __($reverse ? 'production_invoice_cost.reverse_help' : 'production_invoice_cost.forward_help') }}</div>
@include('modules.purchases.purchase-invoices.stage-cost-impact', ['impact' => $plan, 'impactKey' => 'current'])
@can('production.runs.correct')
<form method="POST" action="{{ route('admin.purchases.purchase-invoices.stage-cost.store', $record) }}" class="card mb-3">@csrf
    <x-forms.input type="hidden" name="reverse" :value="(int) $reverse" />
    <x-forms.input type="hidden" name="fingerprint" :value="$fingerprint" />
    <div class="card-body row g-3">
        <div class="col-md-4"><x-forms.label for="posting_date" :label="__('production_invoice_cost.posting_date')" required /><x-forms.date-input id="posting_date" name="posting_date" :value="$plan['posting_date']" readonly required /><p class="text-muted mt-1">{{ __('production_invoice_cost.date_help') }}</p></div>
        <div class="col-md-4"><x-forms.label for="reason" :label="__('production_invoice_cost.reason')" required /><x-forms.textarea id="reason" name="reason" minlength="5" maxlength="2000" required>{{ old('reason') }}</x-forms.textarea></div>
        <div class="col-md-4"><x-forms.label for="price_evidence" :label="__('production_invoice_cost.price_evidence')" required /><x-forms.textarea id="price_evidence" name="price_evidence" minlength="5" maxlength="2000" required>{{ old('price_evidence') }}</x-forms.textarea><p class="text-muted mt-1">{{ __('production_invoice_cost.evidence_help') }}</p></div>
    </div>
    <div class="card-footer"><button class="btn btn-primary" type="submit">{{ __('production_invoice_cost.prepare') }}</button></div>
</form>
@endcan
@endif
@foreach($history as $correction)
@php($definition = json_decode($correction->corrected_output, true, flags: JSON_THROW_ON_ERROR))
<div class="card mb-3">
    <div class="card-header d-flex flex-wrap justify-content-between gap-2"><h6 class="mb-0">{{ __('production_invoice_cost.history') }} #{{ $correction->id }}</h6><span class="badge bg-secondary">{{ __('production_invoice_cost.'.$correction->status) }}</span></div>
    <div class="card-body"><p>{{ $correction->reason }}</p><p>{{ __('production_invoice_cost.price_evidence') }}: {{ $definition['price_evidence'] }}</p><p>{{ __('production_invoice_cost.posting_date') }}: {{ $dates->formatDate($correction->posting_date) }}</p><p class="mb-0">{{ __($definition['reverse'] ? 'production_invoice_cost.reverse_help' : 'production_invoice_cost.forward_help') }}</p></div>
    @include('modules.purchases.purchase-invoices.stage-cost-impact', ['impact' => $definition['plan'], 'impactKey' => $correction->id])
    @can('production.runs.correct_approve')
    @if(in_array($correction->status, ['prepared', 'approved'], true))
    <div class="card-footer d-flex flex-wrap gap-2">
        @if($correction->status === 'prepared' && (int) $correction->prepared_by !== (int) auth()->id())
        <form method="POST" action="{{ route('admin.purchases.purchase-invoices.stage-cost.approve', [$record, $correction->id]) }}">@csrf<button class="btn btn-success" type="submit">{{ __('production_invoice_cost.approve') }}</button></form>
        @endif
        <form method="POST" action="{{ route('admin.purchases.purchase-invoices.stage-cost.reject', [$record, $correction->id]) }}">@csrf<button class="btn btn-outline-danger" type="submit">{{ __('production_invoice_cost.reject') }}</button></form>
    </div>
    @endif
    @endcan
</div>
@endforeach
@endsection
