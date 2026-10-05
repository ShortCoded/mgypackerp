@extends('layouts.app')
@section('title', __('sales_ui.wht.title'))
@section('content')
@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
@php($dates = app(\Modules\Core\Services\DateFormatService::class))
<div class="card mb-3"><div class="card-header d-flex justify-content-between"><h5>{{ __('sales_ui.wht.title') }} @if($invoice) — {{ $invoice->doc_num }} @endif</h5>@if($invoice)<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.sales-invoices.show', $invoice) }}">{{ __('Back') }}</a>@endif</div><div class="card-body">
<p>{{ __('sales_ui.wht.help') }}</p>
@if($errors->any())<div class="alert alert-danger"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($sourceError ?? null)<div class="alert alert-warning">{{ $sourceError }}</div>@endif
@if($invoice)
<div class="row g-3 mb-3">@foreach(['total_amount' => __('Net Invoice'), 'paid_amount' => __('sales_ui.wht.cash'), 'actual_withholding_amount' => __('sales_ui.wht.actual'), 'remaining_amount' => __('sales_ui.wht.remaining')] as $field => $label)<div class="col-md-3"><span>{{ $label }}</span><div class="fw-semibold" dir="ltr">{{ $numbers->format($invoice->{$field}) }} {{ $invoice->currency?->code }}</div></div>@endforeach</div>
@can('customer_withholding_settlements.prepare')
<form method="GET" action="{{ route('admin.sales.sales-invoices.withholding.index', $invoice) }}" class="row g-2 align-items-end">
<div class="col-md-4"><x-forms.label for="wht_schedule" :label="__('Payment Schedule')" /><x-forms.select class="form-select" id="wht_schedule" name="payment_schedule_id" required>@foreach($invoice->paymentSchedules as $schedule)<option value="{{ $schedule->id }}" @selected((int) request('payment_schedule_id') === $schedule->id)>#{{ $schedule->sequence }} — {{ $dates->formatDate($schedule->due_date) }} — {{ $numbers->format($schedule->outstanding_amount) }}</option>@endforeach</x-forms.select></div>
<div class="col-md-5"><x-forms.label for="wht_receipt" :label="__('Customer Receipt')" /><x-forms.select class="form-select" id="wht_receipt" name="customer_receipt_id" required><option value="">—</option>@foreach($receipts as $receipt)<option value="{{ $receipt->id }}" @selected((int) request('customer_receipt_id') === $receipt->id)>{{ $receipt->doc_num }} — {{ $numbers->format($receipt->amount) }}</option>@endforeach</x-forms.select></div><div class="col-md-3"><button class="btn btn-primary">{{ __('sales_ui.wht.review') }}</button></div>
</form>
@endcan
@endif
</div></div>
@if($source)
@can('customer_withholding_settlements.prepare')
<div class="card mb-3" data-sales-attachments><div class="card-body">
<p>{{ __('sales_ui.wht.expected') }}: <strong dir="ltr">{{ $numbers->format($source['expected_amount']) }}</strong> · {{ __('sales_ui.wht.cash') }}: <strong dir="ltr">{{ $numbers->format($source['receipt']->amount) }}</strong></p>
<form method="POST" action="{{ route('admin.sales.sales-invoices.withholding.store', $invoice) }}">@csrf
<x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" />
<x-forms.input type="hidden" name="source_fingerprint" :value="$source['fingerprint']" />
<x-forms.input type="hidden" name="payment_schedule_id" :value="$source['schedule']->id" />
<x-forms.input type="hidden" name="customer_receipt_id" :value="$source['receipt']->id" />
<div class="row g-3"><div class="col-md-6"><x-forms.label for="certificate_reference" :label="__('sales_ui.wht.reference')" required /><x-forms.input id="certificate_reference" name="certificate_reference" :value="old('certificate_reference')" required /></div><div class="col-md-6"><x-forms.label for="actual_wht" :label="__('sales_ui.wht.actual')" required /><x-forms.numeric-input id="actual_wht" name="amount" :scale="4" :value="old('amount')" required /></div><div class="col-md-6"><x-forms.label :label="__('sales_ui.wht.certificate_date')" required /><x-forms.date-input name="certificate_date" :value="old('certificate_date', today())" required /></div><div class="col-md-6"><x-forms.label :label="__('sales_ui.wht.posting_date')" required /><x-forms.date-input name="posting_date" :value="old('posting_date', today())" required /></div><div class="col-12"><x-forms.label for="certificate_reason" :label="__('sales_ui.wht.reason')" required /><x-forms.textarea id="certificate_reason" name="reason" required>{{ old('reason') }}</x-forms.textarea></div><div class="col-12">
@include('modules.sales.cycle.partials.withholding-certificate-picker')
<button class="btn btn-primary mt-2">{{ __('sales_ui.wht.prepare') }}</button></div></div>
</form></div></div>
@endcan
@endif
<div class="card"><div class="card-header">{{ __('sales_ui.wht.history') }}</div><div class="card-body">
@forelse($history as $settlement)
<div class="border rounded p-3 mb-3" data-sales-attachments>
<h6>{{ $settlement->certificate_reference }} — {{ __('sales_ui.wht.'.$settlement->status) }}</h6>
@if(!$invoice)<p><a href="{{ route('admin.sales.sales-invoices.withholding.index', $settlement->invoice) }}">{{ $settlement->invoice?->doc_num }} — {{ $settlement->invoice?->customer?->name }}</a></p>@endif
<p>{{ __('sales_ui.wht.actual') }}: <strong dir="ltr">{{ $numbers->format($settlement->amount) }}</strong> · {{ __('sales_ui.wht.expected') }}: <strong dir="ltr">{{ $numbers->format($settlement->expected_amount) }}</strong></p>
<p>{{ $settlement->reason }} · {{ $settlement->preparer?->name }} · {{ $dates->formatDate($settlement->posting_date) }}</p>
@if($settlement->journal_entry_id)<p>{{ __('Journal Entry') }} #{{ $settlement->journal_entry_id }} · {{ $settlement->approver?->name }} · {{ $settlement->approval_reason }}</p>@endif
@if($settlement->reversal_journal_entry_id)<p>{{ __('sales_ui.wht.recovery_reference') }}: {{ $settlement->recovery_reference }} · {{ __('Journal Entry') }} #{{ $settlement->reversal_journal_entry_id }} · {{ $settlement->reversal_reason }}</p>@endif
@if($invoice)
@can('customer_withholding_settlements.print')<a class="btn btn-falcon-default btn-sm mb-2" href="{{ route('admin.sales.sales-invoices.withholding.print', [$invoice, $settlement->id]) }}">{{ __('Print') }}</a>@endcan
@if($settlement->status === 'prepared' && (int) $settlement->prepared_by !== (int) auth()->id())
@can('customer_withholding_settlements.approve')<form method="POST" action="{{ route('admin.sales.sales-invoices.withholding.approve', [$invoice, $settlement->id]) }}">@csrf<x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" /><x-forms.label :label="__('validation.attributes.approval_reason')" required /><x-forms.textarea name="approval_reason" required></x-forms.textarea><button class="btn btn-success mt-2">{{ __('sales_ui.wht.approve') }}</button></form>@endcan
@elseif($settlement->status === 'approved')
@can('customer_withholding_settlements.reverse')<details><summary>{{ __('sales_ui.wht.reverse') }}</summary><p class="small">{{ __('sales_ui.wht.recovery_help') }}</p><form method="POST" action="{{ route('admin.sales.sales-invoices.withholding.reverse', [$invoice, $settlement->id]) }}">@csrf<x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" /><div class="row g-2"><div class="col-md-6"><x-forms.label :label="__('sales_ui.wht.recovery_reference')" required /><x-forms.input name="recovery_reference" required /></div><div class="col-md-6"><x-forms.label :label="__('sales_ui.wht.posting_date')" required /><x-forms.date-input name="posting_date" :value="today()" required /></div><div class="col-12"><x-forms.label :label="__('Reason')" required /><x-forms.textarea name="reason" required></x-forms.textarea></div></div>
@include('modules.sales.cycle.partials.withholding-certificate-picker')
<button class="btn btn-warning mt-2">{{ __('sales_ui.wht.reverse') }}</button></form></details>@endcan
@endif
@endif
</div>
@empty<p>{{ __('sales_ui.reports.no_results') }}</p>@endforelse
{{ $history->links() }}
</div></div>
@can('file_manager.view')<x-file-picker-modal />@endcan
@endsection
@push('scripts')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Sales/sales-attachments.js') }}"></script>@endpush
