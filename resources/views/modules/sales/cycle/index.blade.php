@extends('layouts.app')

@php
    $titles = [
        'sales_orders' => __('Sales Orders'), 'customer_invoices' => __('Sales Invoices and Credit Notes'),
        'customer_receipts' => __('Customer Receipts'), 'sales_returns' => __('Sales Returns'),
        'sales_deliveries' => __('Delivery Notes'),
    ];
    $routeNames = [
        'sales_orders' => 'admin.sales.sales-orders.show', 'customer_invoices' => 'admin.sales.customer-invoices.show',
        'customer_receipts' => 'admin.sales.customer-receipts.show', 'sales_returns' => 'admin.sales.sales-returns.show',
        'sales_deliveries' => 'admin.sales.sales-deliveries.show',
    ];
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $canViewAmounts = match ($kind) {
        'sales_orders' => (bool) auth()->user()?->can('sales_orders.view_prices'),
        'customer_invoices' => (bool) auth()->user()?->can('customer_invoices.view_prices'),
        'sales_deliveries' => false,
        default => true,
    };
    $createRoutes = [
        'sales_orders' => 'admin.sales.sales-orders.create',
        'customer_receipts' => 'admin.sales.customer-receipts.create',
    ];
    $createPermissions = ['sales_orders' => 'sales_orders.create', 'customer_receipts' => 'customer_receipts.create'];
@endphp

@section('title', $titles[$kind])

@section('content')
    <div class="card mb-3">
        <div class="card-header py-2"><button class="btn btn-link p-0 text-decoration-none" type="button" data-bs-toggle="collapse" data-bs-target="#sales-cycle-filters"><span class="fas fa-filter me-1"></span>{{ __('Filters') }}</button></div>
        <div class="collapse show" id="sales-cycle-filters"><div class="card-body"><form method="GET" class="row g-3 align-items-end">
            <div class="col-md-2"><label class="form-label">{{ __('Document number') }}</label><input class="form-control form-control-sm" name="document" value="{{ request('document') }}"></div>
            <div class="col-md-3"><label class="form-label">{{ __('Customer') }}</label><input class="form-control form-control-sm" name="customer" value="{{ request('customer') }}"></div>
            <div class="col-md-2"><label class="form-label">{{ __('From date') }}</label><input class="form-control form-control-sm" type="date" name="date_from" value="{{ request('date_from') }}"></div>
            <div class="col-md-2"><label class="form-label">{{ __('To date') }}</label><input class="form-control form-control-sm" type="date" name="date_to" value="{{ request('date_to') }}"></div>
            <div class="col-md-2"><label class="form-label">{{ __('Status') }}</label><select class="form-select form-select-sm" name="status"><option value="">{{ __('All statuses') }}</option>@foreach(['draft','pending_approval','held_credit','approved','partially_fulfilled','fulfilled','posted','approved','received','inspected','closed','cancelled'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ str($status)->replace('_', ' ')->title() }}</option>@endforeach</select></div>
            @if($kind === 'sales_orders')
                <div class="col-md-2"><label class="form-label">{{ __('Required from') }}</label><input class="form-control form-control-sm" type="date" name="required_from" value="{{ request('required_from') }}"></div>
                <div class="col-md-2"><label class="form-label">{{ __('Required to') }}</label><input class="form-control form-control-sm" type="date" name="required_to" value="{{ request('required_to') }}"></div>
                <div class="col-md-2"><label class="form-label">{{ __('Credit / approval state') }}</label><select class="form-select form-select-sm" name="credit_status"><option value="">{{ __('All') }}</option>@foreach(['pending','clear','blocked','overridden'] as $credit)<option value="{{ $credit }}" @selected(request('credit_status') === $credit)>{{ str($credit)->title() }}</option>@endforeach</select></div>
                <div class="col-md-2"><label class="form-label">{{ __('Sales representative') }}</label><input class="form-control form-control-sm" name="salesman" value="{{ request('salesman') }}"></div>
                <div class="col-md-2"><label class="form-label">{{ __('Fulfillment') }}</label><select class="form-select form-select-sm" name="fulfillment"><option value="">{{ __('All') }}</option><option value="open" @selected(request('fulfillment') === 'open')>{{ __('Open') }}</option><option value="partial" @selected(request('fulfillment') === 'partial')>{{ __('Partial') }}</option><option value="complete" @selected(request('fulfillment') === 'complete')>{{ __('Complete') }}</option></select></div>
                <div class="col-md-auto"><div class="form-check"><input class="form-check-input" type="checkbox" name="overdue" value="1" id="overdue" @checked(request()->boolean('overdue'))><label class="form-check-label" for="overdue">{{ __('Overdue open orders') }}</label></div></div>
            @endif
            <div class="col-md-auto"><button class="btn btn-falcon-primary btn-sm" type="submit">{{ __('Apply') }}</button> <a class="btn btn-falcon-default btn-sm" href="{{ url()->current() }}">{{ __('Reset') }}</a></div>
        </form></div></div>
    </div>
    <div class="card erp-datatable-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">{{ $titles[$kind] }}</h5>
            <div class="d-flex gap-2"><a class="btn btn-falcon-default btn-sm" href="{{ route('admin.reports.sales.sales-orders.index') }}">{{ __('Sales Cycle Report') }}</a>@if(isset($createRoutes[$kind])) @can($createPermissions[$kind])<a class="btn btn-falcon-primary btn-sm" href="{{ route($createRoutes[$kind]) }}"><span class="fas fa-plus me-1"></span>{{ __('Create') }}</a>@endcan @endif</div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 data-table erp-datatable" id="sales-cycle-table" data-datatables='{"paging":false,"searching":false,"info":false,"responsive":false}'>
                    <thead><tr><th>{{ __('Document') }}</th><th>{{ __('Date') }}</th><th>{{ __('Customer') }}</th>@if($kind === 'sales_orders')<th>{{ __('Required date') }}</th><th>{{ __('Credit') }}</th><th class="text-end">{{ __('Delivered %') }}</th><th class="text-end">{{ __('Invoiced %') }}</th>@endif<th>{{ __('Status') }}</th><th class="text-end">{{ __('Amount') }}</th>@if($kind === 'sales_orders')<th>{{ __('Created by') }}</th><th>{{ __('Updated') }}</th>@endif<th>{{ __('Actions') }}</th></tr></thead>
                    <tbody>
                        @forelse($records as $record)
                            @php
                                $date = $record->order_date ?? $record->invoice_date ?? $record->receipt_date ?? $record->return_date ?? $record->document_date;
                                $amount = $record->total_amount ?? $record->amount;
                                $ordered = $kind === 'sales_orders' ? (float) $record->lines->sum('quantity') : 0;
                                $delivered = $kind === 'sales_orders' ? (float) $record->lines->sum('delivered_quantity') : 0;
                                $invoiced = $kind === 'sales_orders' ? (float) $record->lines->sum('invoiced_quantity') : 0;
                            @endphp
                            <tr>
                                <td><a href="{{ route($routeNames[$kind], $record) }}">{{ $record->doc_num }}</a></td>
                                <td>{{ $dates->formatDate($date, '') }}</td>
                                <td>{{ trim(implode(' / ', array_filter([$record->customer?->doc_num, $record->customer?->name]))) }}</td>
                                @if($kind === 'sales_orders')<td>{{ $dates->formatDate($record->expected_delivery_date, '') }}</td><td><span class="badge bg-{{ $record->credit_status === 'blocked' ? 'danger' : 'secondary' }}">{{ str($record->credit_status)->replace('_', ' ')->title() }}</span></td><td class="text-end">{{ $ordered > 0 ? number_format(($delivered / $ordered) * 100, 1) : '0.0' }}%</td><td class="text-end">{{ $ordered > 0 ? number_format(($invoiced / $ordered) * 100, 1) : '0.0' }}%</td>@endif
                                <td><span class="badge bg-secondary">{{ str($record->status)->replace('_', ' ')->title() }}</span></td>
                                <td class="text-end" dir="ltr">{{ $amount !== null && $canViewAmounts ? $numbers->format($amount) : '—' }}</td>
                                @if($kind === 'sales_orders')<td>{{ $record->createdBy?->name }}</td><td>{{ $record->updated_at?->format('Y-m-d H:i') }}</td>@endif
                                <td class="text-nowrap"><a class="btn btn-falcon-default btn-sm" href="{{ route($routeNames[$kind], $record) }}">{{ __('View') }}</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ $kind === 'sales_orders' ? 12 : 6 }}" class="text-center py-5 text-500">{{ __('No records found.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($records->hasPages())<div class="card-footer">{{ $records->links() }}</div>@endif
    </div>
@endsection
