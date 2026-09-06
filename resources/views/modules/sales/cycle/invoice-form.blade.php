@extends('layouts.app')

@section('title', __('Correct Sales Invoice').' '.$record->doc_num)

@section('content')
@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
<form class="js-sales-cycle-form" data-sales-ui data-index-url="{{ route('admin.sales.sales-invoices.index') }}" action="{{ route('admin.sales.sales-invoices.update', $record) }}" method="POST" novalidate>
    @csrf
        <x-forms.line-item-cards :line-label="__('sales_ui.line')" /> @method('PUT')
    <div class="alert alert-danger d-none js-sales-form-alert"></div>
    <div class="card mb-3"><div class="card-header d-flex justify-content-between"><div><h5 class="mb-0">{{ __('Correct Sales Invoice') }}</h5><small>{{ $record->doc_num }} · {{ __('Posting revision') }} {{ $record->posting_revision }}</small></div><a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.sales-invoices.show', $record) }}">{{ __('Back') }}</a></div><div class="card-body"><div class="alert alert-warning">{{ __('This correction uses the original Sales Order and Delivery lineage. Quantities cannot exceed their original delivered/service eligibility. Reposting creates a new revision journal after the original reversal.') }}</div>
        <div class="table-responsive"><table class="table table-sm table-bordered align-middle" style="min-width:850px"><thead><tr><th>#</th><th>{{ __('Product') }}</th><th>{{ __('Source') }}</th><th>{{ __('Unit') }}</th><th>{{ __('Corrected quantity') }}</th></tr></thead><tbody>@foreach($record->lines as $index => $line)<tr><td>{{ $line->line_number }}<input type="hidden" name="lines[{{ $index }}][invoice_line_public_id]" value="{{ $line->public_id }}"></td><td>{{ $line->product?->doc_num }} / {{ $line->product?->name }}</td><td>{{ $line->deliveryLine?->document?->doc_num ?: __('Service order line') }}</td><td>{{ $line->unit?->name }}</td><td><input class="form-control form-control-sm text-end" name="lines[{{ $index }}][quantity]" value="{{ $numbers->formatForInput($line->quantity) }}" inputmode="decimal" required></td></tr>@endforeach</tbody></table></div>
    </div></div>
    <div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('Corrected payment schedule') }}</h6></div><div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>#</th><th>{{ __('Due date') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Notes') }}</th></tr></thead><tbody>@foreach($record->paymentSchedules as $index => $schedule)<tr><td>{{ $index + 1 }}</td><td><input class="form-control form-control-sm js-date-picker" name="payment_schedules[{{ $index }}][due_date]" value="{{ $schedule->due_date?->toDateString() }}" required></td><td><input class="form-control form-control-sm text-end" name="payment_schedules[{{ $index }}][amount]" value="{{ $numbers->formatForInput($schedule->amount) }}" inputmode="decimal" required></td><td><input class="form-control form-control-sm" name="payment_schedules[{{ $index }}][notes]" value="{{ $schedule->notes }}"></td></tr>@endforeach</tbody></table></div></div>
    @include('modules.finance.partials.form-actions', ['mode' => 'edit', 'record' => $record, 'resource' => 'customer_invoices', 'routePrefix' => 'admin.sales.sales-invoices', 'canClone' => false])
</form>
@endsection

@push('scripts')@include('modules.sales.cycle.partials.scripts')@endpush
