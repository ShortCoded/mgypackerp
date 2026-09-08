@extends('layouts.app')
@section('title', __('Sales Request').' '.$record->doc_num)
@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $statusLabel = static fn (?string $status): string => __(str((string) $status)->replace('_', ' ')->title()->toString());
    $actionLabel = static fn (string $status): string => match ($status) {
        'submitted' => __('Submit for Approval'),
        'approved' => __('Approve'),
        'rejected' => __('Reject'),
        'cancelled' => __('Cancel'),
        'closed' => __('Close'),
        default => $statusLabel($status),
    };
    $overviewFields = array_filter([
        __('Customer') => $record->customer?->name ?? __('Internal request'),
        __('Sales representative') => $record->salesEmployee?->full_name ?? $record->salesEmployee?->name ?? __('Unassigned'),
        __('Date') => $dates->formatDate($record->request_date, ''),
        __('Required date') => $dates->formatDate($record->required_delivery_date, ''),
        __('Status') => $statusLabel($record->status),
    ], fn ($value): bool => filled($value));
@endphp
@section('content')
@if($record->sales_employee_id && !$record?->business_employee_id)<div class="alert alert-subtle-warning">{{ __('sales_ui.employee_unresolved') }}</div>@endif
<div class="card mb-3"><div class="card-header d-flex flex-wrap justify-content-between gap-2"><h5>{{ __('Sales Request') }} {{ $record->doc_num }}</h5><div class="d-flex gap-2"><a class="btn btn-falcon-default btn-sm" data-shortcut-action="form.back" href="{{ route('admin.sales.customer-requests.index') }}">{{ __('Back') }}</a>@can('sales_requests.print')<a class="btn btn-falcon-default btn-sm" target="_blank" href="{{ route('admin.sales.customer-requests.print', $record) }}">{{ __('Print') }}</a>@endcan @if(in_array($record->status, ['draft','rejected']))@can('sales_requests.edit')<a class="btn btn-primary btn-sm" data-shortcut-action="form.edit" href="{{ route('admin.sales.customer-requests.edit', $record) }}">{{ __('Edit') }}</a>@endcan @endif</div></div>
<div class="card-body row g-3">@foreach($overviewFields as $label => $value)<div class="col-md-4"><small>{{ $label }}</small><div class="fw-semibold">{{ $value }}</div></div>@endforeach</div></div>
@php $actions = match($record->status) { 'draft', 'rejected' => ['submitted' => 'edit', 'cancelled' => 'cancel'], 'submitted' => ['approved' => 'approve', 'rejected' => 'approve', 'cancelled' => 'cancel'], 'approved' => ['closed' => 'cancel','cancelled' => 'cancel'], 'converted','partially_converted' => ['closed' => 'cancel'], default => [] }; @endphp
@if(collect($actions)->contains(fn (string $permission): bool => auth()->user()?->can('sales_requests.'.$permission)))
<div class="card mb-3"><div class="card-body d-flex flex-wrap gap-3">
@foreach($actions as $status => $permission)@can('sales_requests.'.$permission)<form data-sales-ui class="js-sales-cycle-action" action="{{ route('admin.sales.customer-requests.transition', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><input type="hidden" name="status" value="{{ $status }}"><div class="alert alert-danger d-none js-sales-form-alert"></div>@if(in_array($status, ['rejected','cancelled','closed']))<input class="form-control form-control-sm mb-2" name="reason" placeholder="{{ __('Reason') }}" required>@endif<button class="btn btn-falcon-primary btn-sm" type="submit">{{ $actionLabel($status) }}</button></form>@endcan @endforeach
</div></div>
@endif
@if(in_array($record->status, ['approved', 'partially_converted'], true) && $record->customer_id && $record->currency_id)
<div class="card mb-3"><div class="card-header py-2"><h6 class="mb-0">{{ __('sales_ui.continue_sales_cycle') }}</h6></div><div class="card-body py-3 d-flex flex-wrap gap-2">
    @can('quotations.create')<a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.sales.quotations.create', ['source_request_doc_num' => $record->doc_num]) }}">{{ __('sales_ui.create_quotation') }}</a>@endcan
    @can('sales_orders.create')<a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.sales.sales-orders.create', ['source_request_doc_num' => $record->doc_num]) }}">{{ __('Create Sales Order') }}</a>@endcan
    @can('customer_invoices.create')<a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.sales.sales-invoices.create', ['source_request_doc_num' => $record->doc_num]) }}">{{ __('sales_ui.create_invoice') }}</a>@endcan
</div></div>
@endif
<form data-sales-ui class="js-sales-cycle-action" method="POST" action="{{ route('admin.sales.customer-requests.convert', $record) }}">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><div class="alert alert-danger d-none js-sales-form-alert"></div>
@if($record->status === 'approved')
<div class="row g-3 mb-3">
@if(!$record->customer_id)<div class="col-md-4"><label class="form-label">{{ __('Customer') }}</label><select class="js-select2-ajax form-select" name="customer_doc_num" required data-url="{{ route('admin.sales.select2.customers') }}" data-allow-clear="true"><option value="">{{ __('Select customer') }}</option>@foreach($customers as $customer)<option value="{{ $customer->doc_num }}">{{ $customer->name }}</option>@endforeach</select></div>@endif
@if(!$record->currency_id)<div class="col-md-4"><label class="form-label">{{ __('Currency') }}</label><select class="form-select js-select2-ajax" name="currency_doc_num" data-url="{{ route('admin.select2.currencies') }}" data-placeholder="{{ __('Currency') }}" required>@foreach($currencies as $currency)<option value="{{ $currency->doc_num }}">{{ $currency->code }} — {{ $currency->name }}</option>@endforeach</select></div><div class="col-md-4"><label class="form-label">{{ __('Exchange Rate') }}</label><input class="form-control" name="exchange_rate" value="1" required inputmode="decimal"></div>@endif
</div>
@endif
<div class="card mb-3"><div class="card-header"><h6>{{ __('Request lines and conversion') }}</h6></div><div class="table-responsive"><table class="table"><thead><tr><th>#</th><th>{{ __('Product') }}</th><th>{{ __('Unit') }}</th><th>{{ __('Requested') }}</th><th>{{ __('Converted') }}</th><th>{{ __('Remaining') }}</th><th>{{ __('Convert now') }}</th></tr></thead><tbody>@foreach($record->lines as $index => $line)<tr><td>{{ $line->line_number }}</td><td>{{ $line->product?->doc_num }} / {{ $line->product?->name }} {{ $line->product?->color?->name }}</td><td>{{ $line->unit?->name }}</td><td>{{ $numbers->format($line->quantity) }}</td><td>{{ $numbers->format($line->converted_quantity) }}</td><td>{{ $numbers->format($line->remainingQuantity()) }}</td><td><input type="hidden" name="lines[{{ $index }}][public_id]" value="{{ $line->public_id }}"><input class="form-control" name="lines[{{ $index }}][quantity]" inputmode="decimal" value="{{ $numbers->format($line->remainingQuantity()) }}" @disabled(!in_array($record->status,['approved','partially_converted']))></td></tr>@endforeach</tbody></table></div></div>
@if(in_array($record->status,['approved','partially_converted']))@can('sales_requests.convert')<div class="d-flex flex-wrap gap-2 mb-3"><select class="form-select w-auto" name="target">@can('quotations.create')<option value="quotation">{{ __('Quotation') }}</option>@endcan @can('sales_orders.create')<option value="order">{{ __('Sales Order') }}</option>@endcan</select><button class="btn btn-primary" type="submit">{{ __('Convert selected quantities') }}</button></div>@endcan @endif
</form>
<div class="card mb-3"><div class="card-header py-2">{{ __('Document chain') }}</div><div class="card-body py-3 d-flex flex-wrap gap-3">@foreach($record->quotations as $quotation)@can('quotations.view')<a href="{{ route('admin.sales.quotations.show', $quotation) }}">{{ $quotation->doc_num }} · {{ $statusLabel($quotation->status) }}</a>@endcan @endforeach @foreach($record->orders as $order)@can('sales_orders.view')<a href="{{ route('admin.sales.sales-orders.show', $order) }}">{{ $order->doc_num }} · {{ $statusLabel($order->status) }}</a>@endcan @endforeach</div></div>
<div class="card mb-3"><div class="card-header py-2">{{ __('Status history') }}</div><div class="card-body py-3">@foreach($record->status_history ?? [] as $event)<div class="mb-2">{{ $dates->formatDateTime($event['at'] ?? null, '') }} · {{ $statusLabel($event['to'] ?? null) }}@if(filled($event['reason'] ?? null)) · {{ $event['reason'] }}@endif</div>@endforeach @if(filled($record->notes))<p class="mb-0">{{ $record->notes }}</p>@endif</div></div>
@if(in_array('sales_request', ['sales_order', 'sales_delivery', 'invoice', 'credit_note', 'sales_return', 'sales_request']))
@include('modules.sales.cycle.partials.attachments', ['attachmentRecord' => $record, 'attachmentKind' => 'sales_request', 'attachmentsReadonly' => !auth()->user()?->can(match('sales_request') { 'sales_request' => 'sales_requests.edit', 'sales_order' => 'sales_orders.edit', 'sales_delivery' => 'sales_deliveries.create', 'sales_return' => 'sales_returns.create', default => 'customer_invoices.edit' })])
@endif
@endsection
@push('scripts')@include('modules.sales.cycle.partials.scripts')@endpush
