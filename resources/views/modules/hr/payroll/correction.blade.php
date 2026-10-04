@extends('layouts.app')
@section('title', __('hr_payroll_correction.title'))
@section('content')
@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
@endphp
<div class="container-fluid px-0 px-sm-3">
    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
            <h5 class="mb-0">{{ __('hr_payroll_correction.title') }} #{{ $run->id }}</h5>
            @can('hr.payroll_preparation.view')<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.hr.payroll-preparation.index', ['run' => $run->id]) }}">{{ __('common.actions.back') }}</a>@endcan
        </div>
        <div class="card-body">
            @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
            @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <p>{{ __('hr_payroll_correction.intro') }}</p>
            @can('hr.payroll_approval.correct_later_period')<p>{{ __('hr_payroll_correction.later_period_intro') }}</p>@endcan
            <div class="row g-3 mb-3">
                <div class="col-md-4"><strong>{{ __('hr_payroll.labels.period') }}:</strong> <span dir="ltr">{{ $dates->formatDate($run->period_start) }} — {{ $dates->formatDate($run->period_end) }}</span></div>
                <div class="col-md-4"><strong>{{ __('hr_payroll.labels.status') }}:</strong> {{ __('hr_payroll.status.'.$run->status) }}</div>
                <div class="col-md-4"><strong>{{ __('hr_payroll_correction.original_journal') }}:</strong> <span dir="ltr">{{ $snapshot['journal']?->doc_num ?? '—' }}</span></div>
            </div>
            @can('hr.payroll_approval.correct')
                @if($run->status === 'posted')
                    <form method="POST" action="{{ route('admin.hr.payroll-runs.corrections.store', $run->id) }}" class="row g-3" novalidate>
                        @csrf
                        <x-forms.input type="hidden" name="fingerprint" :value="$fingerprint" />
                        @can('hr.payroll_approval.correct_later_period')
                            <div class="col-md-12"><x-forms.label for="correction_mode" :label="__('hr_payroll_correction.mode')" required /><x-forms.select id="correction_mode" name="correction_mode" variant="local">
                                @foreach(__('hr_payroll_correction.modes') as $mode => $label)
                                    <option value="{{ $mode }}" @selected(old('correction_mode', ($snapshot['source_period']?->is_closed ?? false) ? 'later_period' : 'original_period') === $mode)>{{ $label }}</option>
                                @endforeach
                            </x-forms.select></div>
                        @endcan
                        <div class="col-md-3"><x-forms.label for="reversal_date" :label="__('hr_payroll_correction.date')" required /><x-forms.date-input id="reversal_date" name="reversal_date" :value="old('reversal_date', max(now()->toDateString(), $run->period_end))" /></div>
                        <div class="col-md-7"><x-forms.label for="reason" :label="__('hr_payroll_correction.reason')" required /><x-forms.textarea id="reason" name="reason" rows="2">{{ old('reason') }}</x-forms.textarea></div>
                        <div class="col-md-2 align-self-end"><button class="btn btn-primary" type="submit">{{ __('hr_payroll_correction.propose') }}</button></div>
                    </form>
                @else
                    <a class="btn btn-outline-primary" href="{{ route('admin.hr.payroll-preparation.index') }}">{{ __('hr_payroll_correction.recalculate') }}</a>
                @endif
            @endcan
        </div>
    </div>
    <div class="card mb-3"><div class="card-header">{{ __('hr_payroll_correction.frozen_slips') }}</div><div class="table-responsive">
        <table class="table table-hover align-middle mb-0"><thead><tr><th>{{ __('hr_payroll.labels.employees') }}</th><th>{{ __('hr_payroll.labels.gross') }}</th><th>{{ __('hr_payroll.labels.deductions') }}</th><th>{{ __('hr_payroll.labels.net') }}</th></tr></thead><tbody>
        @foreach($snapshot['slips'] as $slip)<tr><td>{{ $slip->employee_name }} <small dir="ltr">{{ $slip->employee_doc_num }}</small></td><td dir="ltr">{{ $numbers->format($slip->gross_amount) }}</td><td dir="ltr">{{ $numbers->format($slip->deduction_amount) }}</td><td dir="ltr">{{ $numbers->format($slip->net_amount) }}</td></tr>@endforeach
        </tbody></table></div></div>
    @if($snapshot['payments']->isNotEmpty())
        <div class="card mb-3"><div class="card-header">{{ __('hr_payroll_correction.payments') }}</div><div class="card-body"><p>{{ __('hr_payroll_correction.cancel_payments_first') }}</p>
        @foreach($snapshot['payments'] as $payment)
            <div class="mb-2"><span dir="ltr">{{ $payment->voucher_doc_num }}</span> — {{ __('hr_payroll.status.'.$payment->status) }} — <span dir="ltr">{{ $numbers->format($payment->amount) }}</span>
            @can('cash_payment_vouchers.view')<a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.finance.cash-payment-vouchers.show', $payment->voucher_doc_num) }}">{{ __('hr_payroll_correction.open_voucher') }}</a>@endcan</div>
        @endforeach
        </div></div>
    @endif
    <div class="card"><div class="card-header">{{ __('hr_payroll_correction.history') }}</div><div class="table-responsive"><table class="table mb-0 align-middle"><thead><tr><th>#</th><th>{{ __('hr_payroll_correction.mode') }}</th><th>{{ __('hr_payroll_correction.date') }}</th><th>{{ __('hr_payroll_correction.reason') }}</th><th>{{ __('hr_payroll.labels.status') }}</th><th>{{ __('hr_payroll_correction.reversal_journal') }}</th><th></th></tr></thead><tbody>
    @forelse($corrections as $proposal)
        <tr><td>{{ $proposal->id }}</td><td>{{ __('hr_payroll_correction.modes.'.($proposal->correction_mode ?? 'original_period')) }}</td><td dir="ltr">{{ $dates->formatDate($proposal->reversal_date) }}</td><td>{{ $proposal->reason }}</td><td>{{ __('hr_payroll_correction.states.'.$proposal->status) }}</td><td dir="ltr">{{ $proposal->reversal_journal_doc_num ?? '—' }}</td><td>
        @if($proposal->status === 'prepared')
            @can('hr.payroll_approval.correct_approve')
                @if((int)$proposal->prepared_by !== (int)auth()->id())<form method="POST" class="d-inline" action="{{ route('admin.hr.payroll-runs.corrections.approve', [$run->id, $proposal->id]) }}" novalidate>@csrf<button class="btn btn-sm btn-success" type="submit">{{ __('hr_payroll_correction.approve') }}</button></form>@endif
                <form method="POST" class="d-inline" action="{{ route('admin.hr.payroll-runs.corrections.reject', [$run->id, $proposal->id]) }}" novalidate>@csrf<button class="btn btn-sm btn-outline-danger" type="submit">{{ __('hr_payroll_correction.reject') }}</button></form>
            @endcan
        @endif
        </td></tr>
    @empty<tr><td colspan="7" class="text-muted text-center">{{ __('hr_payroll_correction.empty') }}</td></tr>@endforelse
    </tbody></table></div></div>
</div>
@endsection
