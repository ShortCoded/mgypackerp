@extends('layouts.app')

@section('title', __('sales_issue.issue_orders'))

@section('content')
<div class="card erp-datatable-card">
    <div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="mb-0">{{ __('sales_issue.issue_orders') }}</h5>
        @can('sales_deliveries.create')<a class="btn btn-primary btn-sm" href="{{ route('admin.sales.delivery-notes.create') }}">{{ __('sales_issue.find_invoice') }}</a>@endcan
    </div>
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end mb-3">
            <div class="col-md-5"><x-forms.label for="issue-order-search" :label="__('sales_issue.issue_order')" /><x-forms.input id="issue-order-search" name="document" class="form-control" :value="request('document')" /></div>
            <div class="col-md-4"><x-forms.label for="issue-order-status" :label="__('sales_issue.status')" /><x-forms.select id="issue-order-status" name="status" variant="local"><option value="">{{ __('sales_issue.all_statuses') }}</option><option value="pending" @selected(request('status') === 'pending')>{{ __('sales_issue.pending') }}</option><option value="issued" @selected(request('status') === 'issued')>{{ __('sales_issue.issued_status') }}</option></x-forms.select></div>
            <div class="col-md-3"><button class="btn btn-falcon-primary btn-sm" type="submit">{{ __('common.actions.apply') }}</button></div>
        </form>
        <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th>{{ __('sales_issue.issue_order') }}</th><th>{{ __('sales_issue.invoice') }}</th><th>{{ __('sales_issue.customer') }}</th><th>{{ __('sales_issue.store') }}</th><th>{{ __('sales_issue.status') }}</th><th>{{ __('sales_issue.warehouse_document') }}</th></tr></thead>
            <tbody>
                @forelse($orders as $order)
                    <tr>
                        <td><a href="{{ route('admin.sales.issue-orders.show', $order) }}">{{ $order->doc_num }}</a></td>
                        <td>{{ $order->invoice?->doc_num }}</td>
                        <td>{{ $order->invoice?->customer?->name }}</td>
                        <td>{{ $order->branchStore?->name ?: '—' }}</td>
                        <td>{{ __('sales_issue.'.$order->status) }}</td>
                        <td>@foreach($order->issues as $issue)<a class="d-block" href="{{ route('admin.sales.delivery-notes.show', $issue) }}">{{ $issue->doc_num }}</a>@endforeach</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-600 py-4">{{ __('sales_issue.no_orders') }}</td></tr>
                @endforelse
            </tbody>
        </table></div>
        <div class="mt-3">{{ $orders->links() }}</div>
    </div>
</div>
@endsection
