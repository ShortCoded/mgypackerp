@extends('layouts.app')
@section('title', __('invoice_correction.title'))
@section('content')
@php($dates = app(\Modules\Core\Services\DateFormatService::class))
@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between flex-wrap gap-2"><h5 class="mb-0">{{ __('invoice_correction.title') }} — {{ $invoice->doc_num }}</h5>@can('customer_invoices.view')<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.sales-invoices.show', $invoice) }}">{{ __('Back') }}</a>@endcan</div>
    <div class="card-body">
        <p>{{ __('invoice_correction.help') }}</p>
        @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        <p>{{ __('invoice_correction.target') }}: {{ $target?->name ?? '—' }}</p>
        @foreach(['invoices' => 'invoice_date', 'documents' => 'document_date'] as $group => $dateField)
        <h6>{{ __('invoice_correction.'.($group === 'documents' ? 'deliveries' : $group)) }}</h6>
        <div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('Document') }}</th><th>{{ __('Date') }}</th><th>{{ __('Status') }}</th>@if($group === 'invoices')<th>{{ __('Total') }}</th><th>{{ __('Currency') }}</th>@endif</tr></thead><tbody>@foreach($snapshot[$group] as $row)<tr><td>{{ $row['doc_num'] }}</td><td>{{ $dates->formatDate($row[$dateField]) }}</td><td>{{ __(str($row['status'])->title()->toString()) }}</td>@if($group === 'invoices')<td class="text-nowrap">{{ $numbers->format($row['total_amount']) }}</td><td>{{ \Modules\Core\Models\Currency::find($row['currency_id'])?->code }}</td>@endif</tr>@endforeach</tbody></table></div>
        @endforeach
        @if($dependencies !== [])
        <h6>{{ __('invoice_correction.dependencies') }}</h6><ol>@foreach($dependencies as $step)<li>{{ $step['action'] }} — @can($step['permission'])<a href="{{ $step['url'] }}">{{ $step['document'] }}</a>@else{{ $step['document'] }}@endcan</li>@endforeach</ol>
        @elseif(!$invoice->hasApprovedCorrection())
        @can('customer_invoices.correct_prepare')
        <form method="POST" action="{{ route('admin.sales.sales-invoices.corrections.store', $invoice) }}" novalidate>@csrf
            <x-forms.input type="hidden" name="source_fingerprint" :value="$fingerprint" />
            <div class="row g-3"><div class="col-md-4"><x-forms.label for="invoice_correction_date" :label="__('sales_return_plan.posting_date')" required /><x-forms.date-input id="invoice_correction_date" name="posting_date" :value="old('posting_date', now()->toDateString())" /></div><div class="col-md-8"><x-forms.label for="invoice_recovery_reference" :label="__('invoice_correction.recovery_reference')" required /><x-forms.input id="invoice_recovery_reference" name="recovery_reference" :value="old('recovery_reference')" /></div><div class="col-12"><x-forms.label for="invoice_correction_reason" :label="__('sales_return_plan.reason')" required /><x-forms.textarea id="invoice_correction_reason" name="reason">{{ old('reason') }}</x-forms.textarea></div><div class="col-12"><button class="btn btn-primary">{{ __('invoice_correction.prepare') }}</button></div></div>
        </form>@endcan
        @endif
    </div>
</div>
<div class="card"><div class="card-header">{{ __('invoice_correction.history') }}</div><div class="card-body">
@foreach($history as $proposal)
<div class="border rounded p-3 mb-3"><h6>#{{ $proposal->id }} — {{ __('invoice_correction.'.$proposal->status) }}</h6><p>{{ $dates->formatDate($proposal->posting_date) }} — {{ $proposal->reason }} — {{ $proposal->recovery_reference }}</p><p>{{ __('sales_return_plan.preparer') }}: {{ $proposal->preparer?->name }} — {{ $dates->formatDateTime($proposal->created_at) }}</p>
@if($proposal->status === 'prepared')
@can('customer_invoices.correct_approve')
@if((int) $proposal->prepared_by !== (int) auth()->id())<form class="mb-2" method="POST" action="{{ route('admin.sales.sales-invoices.corrections.approve', [$invoice, $proposal->id]) }}" novalidate>@csrf<x-forms.label :for="'invoice_approval_reason_'.$proposal->id" :label="__('sales_return_plan.approval_reason')" required /><x-forms.textarea :id="'invoice_approval_reason_'.$proposal->id" name="approval_reason" class="mb-2"></x-forms.textarea><button class="btn btn-success">{{ __('invoice_correction.approve') }}</button></form>@endif
<form method="POST" action="{{ route('admin.sales.sales-invoices.corrections.reject', [$invoice, $proposal->id]) }}" novalidate>@csrf<button class="btn btn-outline-danger">{{ __('invoice_correction.reject') }}</button></form>@endcan
@elseif($proposal->status === 'approved')<p>{{ __('sales_return_plan.approver') }}: {{ $proposal->approver?->name }} — {{ $dates->formatDateTime($proposal->approved_at) }} — {{ $proposal->approval_reason }}</p><ul>@foreach($proposal->execution_snapshot['credits'] as $credit)<li>@can('customer_invoices.view')<a href="{{ route('admin.sales.sales-invoices.show', $credit['doc_num']) }}">{{ $credit['doc_num'] }}</a>@else{{ $credit['doc_num'] }}@endcan — {{ $numbers->format($credit['total_amount']) }}</li>@endforeach</ul>
@endif
</div>
@endforeach
{{ $history->links() }}
</div></div>
@endsection
