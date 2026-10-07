@extends('layouts.app')

@section('title', __('Correct Sales Invoice').' '.$record->doc_num)

@section('content')
@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $directDiscountInputs = $record->sales_order_id === null && $record->lines->every(fn ($line) => $line->sales_order_line_id === null);
    $products = $record->lines->pluck('product')->filter()->unique('id')->values();
    $productUnits = $record->lines->groupBy(fn ($line) => $line->product?->doc_num)->map(fn ($lines) => $lines->map(fn ($line) => ['id' => $line->unit?->doc_num, 'text' => trim($line->unit?->doc_num.' / '.$line->unit?->name)])->unique('id')->values());
@endphp
<form class="js-sales-cycle-form" data-sales-ui @if($directDiscountInputs) data-sales-document-summary data-sales-discount-inputs data-sales-auto-single-schedule @endif data-index-url="{{ route('admin.sales.sales-invoices.index') }}" action="{{ route('admin.sales.sales-invoices.update', $record) }}" method="POST" novalidate>
    @csrf
        <x-forms.line-item-cards :line-label="__('sales_ui.line')" /> @method('PUT')
    <div class="alert alert-danger d-none js-sales-form-alert"></div>
    <div class="card mb-3"><div class="card-header d-flex justify-content-between"><div><h5 class="mb-0">{{ __('Correct Sales Invoice') }}</h5><small>{{ $record->doc_num }} · {{ __('Posting revision') }} {{ $record->posting_revision }}</small></div><a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.sales-invoices.show', $record) }}">{{ __('Back') }}</a></div><div class="card-body">@unless($directDiscountInputs)<div class="alert alert-warning">{{ __('This correction uses the original Sales Order and Delivery lineage. Quantities cannot exceed their original delivered/service eligibility. Reposting creates a new revision journal after the original reversal.') }}</div>@endunless
        @if($directDiscountInputs)
        <p class="text-muted">{{ __('sales_ui.invoice_direct_amendment_help') }}</p>
        <div class="table-responsive"><table class="table table-sm table-bordered align-middle sales-order-grid"><thead><tr><th>#</th><th>{{ __('Product') }}</th><th>{{ __('Unit') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Unit price') }}</th><th>{{ __('Discount') }}</th><th>{{ __('sales_ui.tax_rate') }}</th><th>{{ __('Line total') }}</th><th></th></tr></thead><tbody data-sales-lines>
        @foreach($record->lines as $index => $invoiceLine)
            @include('modules.sales.cycle.partials.sales-order-line', ['index' => $index, 'line' => [
                'invoice_line_public_id' => $invoiceLine->public_id, 'product_doc_num' => $invoiceLine->product?->doc_num,
                'unit_doc_num' => $invoiceLine->unit?->doc_num, 'quantity' => old('lines.'.$index.'.quantity', $invoiceLine->quantity),
                'unit_price' => $invoiceLine->unit_price, 'discount_type' => old('lines.'.$index.'.discount_type', $invoiceLine->discount_type ?? 'fixed'),
                'discount_value' => old('lines.'.$index.'.discount_value', $invoiceLine->discount_value ?? bcsub((string) $invoiceLine->discount_amount, (string) $invoiceLine->header_discount_amount, 4)),
                'discount_amount' => $invoiceLine->discount_amount, 'tax_amount' => $invoiceLine->tax_amount, 'tax_rate' => old('lines.'.$index.'.tax_rate', $invoiceLine->tax_rate), 'tax_calculation_basis' => $invoiceLine->tax_calculation_basis,
                'invoice_booked_quantity' => $invoiceLine->quantity,
                'linked_existing_line' => true, 'price_locked' => true], 'showRequestedDate' => false, 'discountInputsEnabled' => true])
        @endforeach
        </tbody></table></div>
        @include('modules.sales.cycle.partials.invoice-discount-inputs', ['record' => $record])
        <x-forms.document-summary />
        @else
        <div class="table-responsive"><table class="table table-sm table-bordered align-middle" style="min-width:850px"><thead><tr><th>#</th><th>{{ __('Product') }}</th><th>{{ __('Source') }}</th><th>{{ __('Unit') }}</th><th>{{ __('Corrected quantity') }}</th></tr></thead><tbody>@foreach($record->lines as $index => $line)<tr><td>{{ $line->line_number }}<x-forms.input type="hidden" name="lines[{{ $index }}][invoice_line_public_id]" value="{{ $line->public_id }}" /></td><td>{{ $line->product?->doc_num }} / {{ $line->product?->name }}</td><td>{{ $line->deliveryLine?->document?->doc_num ?: __('Service order line') }}</td><td>{{ $line->unit?->name }}</td><td><x-forms.input class="form-control form-control-sm text-end" name="lines[{{ $index }}][quantity]" value="{{ $numbers->formatForInput($line->quantity) }}" inputmode="decimal" required /></td></tr>@endforeach</tbody></table></div>
        @endif
    </div></div>
    <div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('Corrected payment schedule') }}</h6></div><div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>#</th><th>{{ __('Due date') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Notes') }}</th></tr></thead><tbody>@foreach($record->paymentSchedules as $index => $schedule)<tr><td>{{ $index + 1 }}</td><td><x-forms.date-input class="form-control form-control-sm js-date-picker" name="payment_schedules[{{ $index }}][due_date]" value="{{ $dates->formatDate($schedule->due_date, '') }}" required /></td><td><x-forms.input class="form-control form-control-sm text-end" name="payment_schedules[{{ $index }}][amount]" value="{{ $numbers->formatForInput($schedule->amount) }}" inputmode="decimal" required /></td><td><x-forms.input class="form-control form-control-sm" name="payment_schedules[{{ $index }}][notes]" value="{{ $schedule->notes }}" /></td></tr>@endforeach</tbody></table></div></div>
    <div class="card mb-3">@include('modules.sales.cycle.partials.withholding-inputs')</div>
    @include('modules.finance.partials.form-actions' , ['mode' => 'edit', 'record' => $record, 'resource' => 'customer_invoices', 'routePrefix' => 'admin.sales.sales-invoices', 'canClone' => false])
</form>
@endsection

@push('scripts')@include('modules.sales.cycle.partials.scripts')@endpush
