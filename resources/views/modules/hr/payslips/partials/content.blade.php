@inject('numbers', 'Modules\Core\Services\NumericFormatService')

<div class="card mb-3"><div class="card-body"><div class="row g-3">
    @foreach (['period' => $payslip->period_start.' — '.$payslip->period_end, 'branch' => $payslip->branch_name ?: '—', 'status' => __('hr_payroll.status.'.$payslip->status)] as $key => $value)
        <div class="col-md-4"><div class="small text-muted">{{ __('hr_payroll_reports.columns.'.$key) }}</div><div class="fw-semibold">{{ $value }}</div></div>
    @endforeach
</div></div></div>

<div class="row g-3 mb-3">
    @foreach (['gross' => $payslip->gross_amount, 'deductions' => $payslip->deduction_amount, 'net' => $payslip->net_amount] as $key => $value)
        <div class="col-md-4"><div class="card h-100"><div class="card-body text-center"><div class="small text-muted">{{ __('hr_payroll_reports.columns.'.$key) }}</div><div class="fs-3 fw-semibold" dir="ltr">{{ $numbers->format($value) }} <small class="fs-6">{{ $payslip->currency_code }}</small></div></div></div></div>
    @endforeach
</div>

<div class="card mb-3"><div class="card-header"><h5 class="mb-0">{{ __('hr_payroll_reports.payslip.items') }}</h5></div><div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>{{ __('hr_payroll_reports.columns.item') }}</th><th>{{ __('hr_payroll_reports.columns.direction') }}</th><th class="text-end">{{ __('hr_payroll_reports.columns.amount') }}</th></tr></thead>
    <tbody>@forelse($items as $item)<tr><td>{{ $item->display_name }}</td><td>{{ __('hr_payroll_reports.directions.'.$item->direction) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($item->amount) }}</td></tr>@empty<tr><td colspan="3" class="text-center text-muted py-3">{{ __('hr_payroll_reports.payslip.no_items') }}</td></tr>@endforelse</tbody>
</table></div></div>

<div class="row g-3">
    <div class="col-lg-6"><div class="card h-100"><div class="card-header"><h5 class="mb-0">{{ __('hr_payroll_reports.payslip.attendance_summary') }}</h5></div><div class="card-body"><dl class="row mb-0">
        @foreach (['finalized_days', 'worked_minutes', 'late_minutes', 'early_leave_minutes', 'recorded_overtime_minutes'] as $key)
            <dt class="col-8">{{ __('hr_payroll_reports.attendance.'.$key) }}</dt><dd class="col-4 text-end" dir="ltr">{{ (int) data_get($attendance, $key, 0) }}</dd>
        @endforeach
    </dl></div></div></div>
    @if ($showRunPayments)
        <div class="col-lg-6"><div class="card h-100"><div class="card-header"><h5 class="mb-0">{{ __('hr_payroll_reports.payslip.payment_references') }}</h5></div><div class="card-body"><ul class="mb-0">@forelse($payments as $payment)<li><a href="{{ route('admin.finance.cash-payment-vouchers.show', $payment->voucher_doc_num) }}" dir="ltr">{{ $payment->voucher_doc_num }}</a> — {{ $numbers->format($payment->amount) }} {{ $payment->currency_code }} — {{ __('hr_payroll.status.'.$payment->status) }}</li>@empty<li class="text-muted">{{ __('hr_payroll.labels.no_payments') }}</li>@endforelse</ul></div></div></div>
    @endif
</div>
