@extends('layouts.app')
@section('title', __('posted_invoice_correction.title'))
@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $prefix = $kind === 'sales' ? 'admin.sales.sales-invoices' : 'admin.purchases.purchase-invoices';
    $lineRows = old('lines', $invoice->lines->map(fn ($line) => [
        'original_line_public_id' => $line->public_id, 'product_doc_num' => $line->product?->doc_num,
        'product_text' => $line->product?->name, 'unit_doc_num' => $line->unit?->doc_num, 'unit_text' => $line->unit?->name,
        'quantity' => $line->quantity, 'unit_price' => $line->unit_price, 'discount_amount' => $line->discount_amount,
        'tax_amount' => $line->tax_amount, 'discount_type' => $line->discount_type, 'discount_value' => $line->discount_value,
        'tax_rate' => $line->tax_rate,
        'source_line_public_id' => $kind === 'purchase' ? $line->purchaseOrderLine?->public_id : ($line->orderLine?->public_id ?? ($line->source_snapshot['sales_request_line_public_id'] ?? null)),
        'receipt_line_public_id' => $kind === 'purchase' ? $line->receiptLine?->public_id : null,
    ])->all());
    $hasSource = $kind === 'purchase' ? $invoice->purchase_order_id !== null : ($invoice->sales_order_id !== null || $invoice->source_type === 'sales_request');
@endphp
@section('content')
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between flex-wrap gap-2"><h5 class="mb-0">{{ __('posted_invoice_correction.title') }} — {{ $invoice->doc_num }}</h5><a class="btn btn-falcon-default btn-sm" href="{{ route($prefix.'.show', $invoice->doc_num) }}">{{ __('Back') }}</a></div>
    <div class="card-body">
        <p>{{ __('posted_invoice_correction.help') }}</p>
        @if($hasSource)<p>{{ __('posted_invoice_correction.source_help') }}</p>@endif
        @if($errors->any())<div class="alert alert-danger"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        @if($blockers !== [])<div class="alert alert-warning"><ul class="mb-0">@foreach($blockers as $blocker)<li>{{ $blocker }}</li>@endforeach</ul></div>
            @if($kind === 'sales')<a href="{{ route('admin.sales.sales-invoices.corrections.index', $invoice) }}">{{ __('invoice_correction.title') }}</a>@endif
        @elseif($canPrepare)
        <form method="POST" action="{{ route($prefix.'.line-corrections.store', $invoice->doc_num) }}" data-posted-invoice-correction novalidate>@csrf
            <x-forms.input type="hidden" name="source_fingerprint" :value="$fingerprint" />
            <div class="row g-3 mb-3"><div class="col-md-4"><x-forms.label for="correction_date" :label="__('sales_return_plan.posting_date')" required /><x-forms.date-input id="correction_date" name="posting_date" :value="old('posting_date', now()->toDateString())" /></div><div class="col-md-8"><x-forms.label for="correction_reason" :label="__('sales_return_plan.reason')" required /><x-forms.textarea id="correction_reason" name="reason">{{ old('reason') }}</x-forms.textarea></div></div>
            <div class="table-responsive"><table class="table table-sm table-bordered align-middle"><thead><tr><th>{{ __('Product') }}</th><th>{{ __('Unit') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Unit price') }}</th><th>{{ __('Discount') }}</th><th>{{ $kind === 'purchase' ? __('posted_invoice_correction.tax_rate') : __('posted_invoice_correction.tax_amount') }}</th>@if($hasSource)<th>{{ __('posted_invoice_correction.source_line') }}</th>@if($kind === 'purchase')<th>{{ __('posted_invoice_correction.receipt_line') }}</th>@endif @endif<th></th></tr></thead><tbody data-correction-lines>
            @foreach($lineRows as $index => $line) @include('modules.core.partials.posted-invoice-correction-line') @endforeach
            </tbody></table></div>
            <template data-correction-template>@include('modules.core.partials.posted-invoice-correction-line', ['index' => '__INDEX__', 'line' => []])</template>
            <button type="button" class="btn btn-falcon-default btn-sm" data-correction-add>{{ __('Add line') }}</button>
            <button class="btn btn-primary">{{ __('posted_invoice_correction.prepare') }}</button>
        </form>
        @endif
    </div>
</div>
<div class="card"><div class="card-header">{{ __('posted_invoice_correction.history') }}</div><div class="card-body">
@foreach($history as $proposal)
<div class="border rounded p-3 mb-3"><h6>#{{ $proposal->id }} — {{ __('posted_invoice_correction.'.$proposal->status) }}</h6><p>{{ $dates->formatDate($proposal->posting_date) }} — {{ $proposal->reason }}</p><p>{{ __('sales_return_plan.preparer') }}: {{ $proposal->preparer?->name }}</p>
<p>{{ __('posted_invoice_correction.stock_impact') }} — {{ __('Journal Entry') }}: {{ $proposal->impact['journal'] }}</p>
<div class="row">@foreach(['before', 'after'] as $side)<div class="col-md-6"><h6>{{ __('posted_invoice_correction.'.$side) }} — {{ $numbers->format($proposal->impact[$side.'_total']) }} {{ $invoice->currency->code }}</h6><table class="table table-sm"><thead><tr><th>{{ __('Product') }}</th><th>{{ __('Unit') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Unit price') }}</th><th>{{ __('Total') }}</th></tr></thead><tbody>@foreach($proposal->impact[$side.'_lines'] as $line)<tr><td>{{ $line['product'] }}</td><td>{{ $line['unit'] }}</td><td>{{ $numbers->format($line['quantity'], 8) }}</td><td>{{ isset($line['unit_price']) ? $numbers->format($line['unit_price'], 8) : '—' }}</td><td>{{ $numbers->format($line['total']) }}</td></tr>@endforeach</tbody></table></div>@endforeach</div>
<p>{{ __('Payment Schedule') }}: @foreach($proposal->impact['after_schedules'] as $schedule){{ $dates->formatDate($schedule['due_date']) }} / {{ $numbers->format($schedule['amount']) }}@if(!$loop->last) · @endif @endforeach</p>
@if($proposal->status === 'prepared' && $canApprove)
@if((int) $proposal->prepared_by !== (int) auth()->id())<form method="POST" class="mb-2" action="{{ route($prefix.'.line-corrections.approve', [$invoice->doc_num, $proposal->id]) }}" novalidate>@csrf<x-forms.label :for="'approval_'.$proposal->id" :label="__('sales_return_plan.approval_reason')" required /><x-forms.textarea :id="'approval_'.$proposal->id" name="approval_reason"></x-forms.textarea><button class="btn btn-success mt-2">{{ __('posted_invoice_correction.approve') }}</button></form>@endif
<form method="POST" action="{{ route($prefix.'.line-corrections.reject', [$invoice->doc_num, $proposal->id]) }}">@csrf<button class="btn btn-outline-danger">{{ __('posted_invoice_correction.reject') }}</button></form>
@elseif($proposal->status === 'approved')<p>{{ __('sales_return_plan.approver') }}: {{ $proposal->approver?->name }} — {{ $proposal->approval_reason }}</p><a href="{{ route($prefix.'.show', $proposal->execution_snapshot['replacement']['doc_num']) }}">{{ $proposal->execution_snapshot['replacement']['doc_num'] }}</a>@endif
</div>
@endforeach
{{ $history->links() }}
</div></div>
@endsection
@push('scripts')
<script src="{{ asset('assets/js/modules/Core/posted-invoice-correction.js') }}"></script>
@endpush
