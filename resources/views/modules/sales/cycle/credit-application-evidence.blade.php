@extends('layouts.app')
@section('title', __('credit_application_evidence.title'))
@section('content')
@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
@php($dates = app(\Modules\Core\Services\DateFormatService::class))
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between"><h5>{{ __('credit_application_evidence.title') }} — {{ $credit->doc_num }}</h5><a href="{{ route('admin.sales.sales-invoices.show', $credit) }}" class="btn btn-falcon-default btn-sm">{{ __('Back') }}</a></div>
    <div class="card-body">
        <p>{{ __('credit_application_evidence.help') }}</p>
        <p>{{ __('Original Invoice') }}: <a href="{{ route('admin.sales.sales-invoices.show', $invoice) }}">{{ $invoice->doc_num }}</a></p>
        <p>{{ __('credit_application_evidence.applied') }}: {{ $numbers->format($applied) }}</p>
        @if($errors->any())<div class="alert alert-danger"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        @if($credit->posting_status === 'posted' && $credit->credit_application_snapshot === null)
        @can('customer_credits.prepare_application_evidence')
        <form method="POST" action="{{ route('admin.sales.sales-invoices.application-evidence.store', $credit) }}" novalidate>@csrf
            <x-forms.input type="hidden" name="source_fingerprint" :value="$fingerprint" />
            <div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('credit_application_evidence.schedule') }}</th><th>{{ __('Date') }}</th><th>{{ __('credit_application_evidence.credited') }}</th><th>{{ __('credit_application_evidence.amount') }}</th></tr></thead><tbody>
            @foreach($schedules as $index => $schedule)
            <tr><td>{{ $schedule->sequence }}<x-forms.input type="hidden" :name="'schedules['.$index.'][schedule_id]'" :value="$schedule->id" /></td><td>{{ $dates->formatDate($schedule->due_date) }}</td><td>{{ $numbers->format($schedule->credited_amount) }}<div class="small text-muted">{{ __('credit_application_evidence.unassigned') }}: {{ $numbers->format(bcsub($schedule->credited_amount, $reserved[$schedule->id] ?? '0', 4)) }}</div></td><td><x-forms.label :for="'schedule_amount_'.$schedule->id" :label="__('credit_application_evidence.amount').' #'.$schedule->sequence" class="visually-hidden" /><x-forms.numeric-input :id="'schedule_amount_'.$schedule->id" :name="'schedules['.$index.'][amount]'" :scale="4" :value="old('schedules.'.$index.'.amount', '0')" /></td></tr>
            @endforeach
            </tbody></table></div>
            <div class="row g-3"><div class="col-md-6"><x-forms.label for="source_reference" :label="__('credit_application_evidence.reference')" required /><x-forms.input id="source_reference" name="source_reference" :value="old('source_reference')" /></div><div class="col-md-6"><x-forms.label for="evidence_reason" :label="__('credit_application_evidence.reason')" required /><x-forms.textarea id="evidence_reason" name="reason">{{ old('reason') }}</x-forms.textarea></div><div class="col-12"><button class="btn btn-primary">{{ __('credit_application_evidence.prepare') }}</button></div></div>
        </form>
        @endcan
        @endif
    </div>
</div>
<div class="card"><div class="card-header">{{ __('credit_application_evidence.history') }}</div><div class="card-body">
@foreach($history as $evidence)
<div class="border rounded p-3 mb-3"><h6>#{{ $evidence->id }} — {{ __('credit_application_evidence.'.$evidence->status) }}</h6><p>{{ $evidence->source_reference }} — {{ $evidence->reason }}</p><p>{{ __('credit_application_evidence.prepared_by') }}: {{ $evidence->preparer?->name }} — {{ $dates->formatDateTime($evidence->created_at) }}</p>
<div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('credit_application_evidence.schedule') }}</th><th>{{ __('credit_application_evidence.amount') }}</th></tr></thead><tbody>@foreach($evidence->application_snapshot['schedules'] as $row)<tr><td>{{ $schedules->firstWhere('id', $row['schedule_id'])?->sequence ?? '—' }}</td><td>{{ $numbers->format($row['amount']) }}</td></tr>@endforeach</tbody></table></div>
@if($evidence->status === 'pending' && (int) $evidence->prepared_by !== (int) auth()->id() && $credit->posting_status === 'posted' && $credit->credit_application_snapshot === null)
@can('customer_credits.approve_application_evidence')
<form method="POST" action="{{ route('admin.sales.sales-invoices.application-evidence.approve', [$credit, $evidence->id]) }}" novalidate>@csrf<x-forms.label :for="'approval_reason_'.$evidence->id" :label="__('credit_application_evidence.approval_reason')" required /><x-forms.textarea :id="'approval_reason_'.$evidence->id" name="approval_reason" class="mb-2"></x-forms.textarea><button class="btn btn-success">{{ __('credit_application_evidence.approve') }}</button></form>
@endcan
@elseif($evidence->status === 'approved')<p>{{ __('credit_application_evidence.approved_by') }}: {{ $evidence->approver?->name }} — {{ $dates->formatDateTime($evidence->approved_at) }} — {{ $evidence->approval_reason }}</p>@endif
</div>
@endforeach
{{ $history->links() }}
</div></div>
@endsection
