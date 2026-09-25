@extends('layouts.app')

@section('title', __('sales_issue.customer_receipt').' '.$receipt->doc_num)

@section('content')
@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
@php($dates = app(\Modules\Core\Services\DateFormatService::class))
<div class="card">
    <div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="mb-0">{{ __('sales_issue.customer_receipt') }} {{ $receipt->doc_num }}</h5>
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.issue-orders.show', $receipt->stockIssue->salesIssueOrder) }}">{{ __('Back') }}</a>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-md-4"><strong>{{ __('sales_issue.invoice') }}</strong><div><a href="{{ route('admin.sales.sales-invoices.show', $receipt->invoice) }}">{{ $receipt->invoice?->doc_num }}</a></div></div>
            <div class="col-md-4"><strong>{{ __('sales_issue.warehouse_document') }}</strong><div><a href="{{ route('admin.sales.delivery-notes.show', $receipt->stockIssue) }}">{{ $receipt->stockIssue?->doc_num }}</a></div></div>
            <div class="col-md-4"><strong>{{ __('sales_issue.customer') }}</strong><div>{{ $receipt->invoice?->customer?->name }}</div></div>
            <div class="col-md-4"><strong>{{ __('sales_issue.recipient_name') }}</strong><div>{{ $receipt->recipient_name }}</div></div>
            <div class="col-md-4"><strong>{{ __('sales_issue.recipient_phone') }}</strong><div>{{ $receipt->recipient_phone ?: '—' }}</div></div>
            <div class="col-md-4"><strong>{{ __('sales_issue.received_at') }}</strong><div>{{ $dates->formatDateTime($receipt->received_at, '') }}</div></div>
        </div>
        <div class="table-responsive"><table class="table table-sm table-bordered align-middle mb-3"><thead><tr><th>{{ __('sales_issue.product') }}</th><th>{{ __('sales_issue.required_quantity') }}</th><th>{{ __('sales_issue.unit') }}</th></tr></thead><tbody>
            @foreach($receipt->stockIssue->lines as $line)<tr><td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}</td><td>{{ $numbers->format($line->transaction_quantity) }}</td><td>{{ $line->transactionUnit?->name }}</td></tr>@endforeach
        </tbody></table></div>
        <a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.sales.delivery-receipts.signature', $receipt) }}">{{ __('sales_issue.download_signature') }}</a>
        @if($receipt->notes)<p class="mt-3 mb-0"><strong>{{ __('sales_issue.notes') }}:</strong> {{ $receipt->notes }}</p>@endif
    </div>
</div>
@endsection
