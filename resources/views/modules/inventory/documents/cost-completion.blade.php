@extends('layouts.app')
@section('title', __('inventory.movements.receipt_completion_title').' — '.$record->doc_num)
@section('content')
@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
@endphp
<div class="container-fluid py-3">
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    <div class="card mb-3"><div class="card-body d-flex justify-content-between flex-wrap gap-3"><div><h4>{{ __('inventory.movements.receipt_completion_title') }} — {{ $record->doc_num }}</h4><p class="text-muted mb-0">{{ $dates->formatDate($record->document_date) }} · {{ __('inventory.movements.receipt_completion_help') }}</p></div><a class="btn btn-falcon-default btn-sm" href="{{ route('admin.inventory.cost-completions.index') }}">{{ __('Back') }}</a></div></div>
    @include('modules.inventory.documents.partials.receipt-cost-card', ['receiptCostCompletion' => true])
</div>
@endsection
