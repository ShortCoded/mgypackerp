@extends('layouts.app')

@section('title', __('sales_issue.issue_order').' '.$order->doc_num)

@section('content')
@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
<div class="card mb-3">
    <div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div><h5 class="mb-0">{{ __('sales_issue.issue_order') }} {{ $order->doc_num }}</h5><small class="text-600">{{ __('sales_issue.'.$order->status.'_status') }}</small></div>
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.delivery-notes.index') }}">{{ __('Back') }}</a>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-md-4"><strong>{{ __('sales_issue.invoice') }}</strong><div><a href="{{ route('admin.sales.sales-invoices.show', $order->invoice) }}">{{ $order->invoice?->doc_num }}</a></div></div>
            <div class="col-md-4"><strong>{{ __('sales_issue.customer') }}</strong><div>{{ $order->invoice?->customer?->name }}</div></div>
            <div class="col-md-4"><strong>{{ __('sales_issue.store') }}</strong><div>{{ $order->branchStore?->name ?: __('sales_issue.store_unassigned') }}</div></div>
        </div>
        <div class="table-responsive"><table class="table table-sm table-bordered align-middle mb-0"><thead><tr><th>{{ __('sales_issue.product') }}</th><th>{{ __('sales_issue.required_quantity') }}</th><th>{{ __('sales_issue.unit') }}</th></tr></thead><tbody>
            @foreach($lines as $row)<tr><td>{{ $row['line']->product?->doc_num }} — {{ $row['line']->product?->name }}</td><td>{{ $numbers->format($row['remaining']) }}</td><td>{{ $row['line']->unit?->name }}</td></tr>@endforeach
            @if($lines === [])<tr><td colspan="3" class="text-center text-600">{{ __('sales_issue.no_remaining_lines') }}</td></tr>@endif
        </tbody></table></div>
        @if($order->status === \Modules\Sales\Models\SalesIssueOrder::StatusPending)
            <p class="text-600 mt-3 mb-1">{{ __('sales_issue.warehouse_branch_help') }}</p>
            @if(auth()->user()?->can('inventory.documents.create') && auth()->user()?->can('inventory.documents.issue'))
                <a class="btn btn-primary btn-sm mt-3" href="{{ route('admin.inventory.documents.sales-issue.create', ['issue_order' => $order->doc_num]) }}">{{ __('sales_issue.issue_now') }}</a>
            @endif
        @endif
        @if($order->issues->isNotEmpty())
            <h6 class="mt-4">{{ __('sales_issue.warehouse_document') }}</h6>
            @foreach($order->issues as $issue)<div class="d-flex flex-wrap align-items-center gap-2 mb-2"><a href="{{ route('admin.sales.delivery-notes.show', $issue) }}">{{ $issue->doc_num }}</a>
                @if($issue->customerDeliveryReceipt)
                    <a href="{{ route('admin.sales.delivery-receipts.show', $issue->customerDeliveryReceipt) }}">{{ __('sales_issue.customer_receipt') }} {{ $issue->customerDeliveryReceipt->doc_num }}</a>
                @else
                    @can('sales_deliveries.receive')<a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.sales.delivery-receipts.create', ['inventoryDocument' => $issue]) }}">{{ __('sales_issue.record_receipt') }}</a>@endcan
                @endif
            </div>@endforeach
        @endif
    </div>
</div>
@endsection
