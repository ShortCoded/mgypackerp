<div class="row g-3 mb-3">
    @foreach (['period' => $payslip->period_start.' — '.$payslip->period_end, 'branch' => $payslip->branch_name ?: '—', 'gross' => number_format((float) $payslip->gross_amount, 2), 'deductions' => number_format((float) $payslip->deduction_amount, 2), 'net' => number_format((float) $payslip->net_amount, 2), 'status' => __('hr_payroll.status.'.$payslip->status)] as $key => $value)
        <div class="col-6 col-lg-2"><div class="card h-100"><div class="card-body py-3"><div class="small text-muted">{{ __('hr_payroll_reports.columns.'.$key) }}</div><strong dir="{{ in_array($key, ['gross', 'deductions', 'net'], true) ? 'ltr' : 'auto' }}">{{ $value }}</strong></div></div></div>
    @endforeach
</div>
<div class="card mb-3"><div class="card-header"><h5 class="mb-0">{{ __('hr_payroll_reports.payslip.items') }}</h5></div><div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>{{ __('hr_payroll_reports.columns.item') }}</th><th>{{ __('hr_payroll_reports.columns.direction') }}</th><th>{{ __('hr_payroll_reports.columns.source') }}</th><th class="text-end">{{ __('hr_payroll_reports.columns.amount') }}</th></tr></thead>
    <tbody>@foreach($items as $item)<tr><td><span dir="ltr">{{ $item->code }}</span>@if($item->name)<span class="d-block small text-muted">{{ $item->name }}</span>@endif</td><td>{{ __('hr_payroll_reports.directions.'.$item->direction) }}</td><td>{{ $item->source_type ?: '—' }}@if($item->source_snapshot !== [])<span class="d-block small text-muted" dir="ltr">{{ collect($item->source_snapshot)->map(fn ($value, $key) => $key.': '.(is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value))->implode(' | ') }}</span>@endif</td><td class="text-end" dir="ltr">{{ number_format((float) $item->amount, 2) }}</td></tr>@endforeach</tbody>
</table></div></div>
<div class="row g-3">
    <div class="col-lg-4"><div class="card h-100"><div class="card-header"><h5 class="mb-0">{{ __('hr_payroll_reports.payslip.payroll_snapshot') }}</h5></div><div class="card-body"><dl class="row mb-0">
        <dt class="col-7">{{ __('hr_payroll_reports.payslip.salary_source') }}</dt><dd class="col-5 text-end" dir="ltr">{{ data_get($input, 'salary_source.type', '—') }}@if(data_get($input, 'salary_source.id')) #{{ data_get($input, 'salary_source.id') }}@endif</dd>
        <dt class="col-7">{{ __('hr_payroll_reports.payslip.approved_requests') }}</dt><dd class="col-5 text-end" dir="ltr">{{ count(data_get($input, 'approved_request_ids', [])) }}</dd>
        <dt class="col-7">{{ __('hr_payroll_reports.columns.gross') }}</dt><dd class="col-5 text-end" dir="ltr">{{ number_format((float) data_get($input, 'gross', 0), 2) }}</dd>
        <dt class="col-7">{{ __('hr_payroll_reports.columns.deductions') }}</dt><dd class="col-5 text-end" dir="ltr">{{ number_format((float) data_get($input, 'deductions', 0), 2) }}</dd>
        <dt class="col-7">{{ __('hr_payroll_reports.columns.net') }}</dt><dd class="col-5 text-end" dir="ltr">{{ number_format((float) data_get($input, 'net', 0), 2) }}</dd>
    </dl></div></div></div>
    <div class="col-lg-4"><div class="card h-100"><div class="card-header"><h5 class="mb-0">{{ __('hr_payroll_reports.payslip.attendance_snapshot') }}</h5></div><div class="card-body"><dl class="row mb-0">@foreach($attendance as $key => $value)<dt class="col-7">{{ str($key)->replace('_', ' ')->title() }}</dt><dd class="col-5 text-end" dir="ltr">{{ is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value }}</dd>@endforeach</dl></div></div></div>
    @if ($showRunPayments)
        <div class="col-lg-4"><div class="card h-100"><div class="card-header"><h5 class="mb-0">{{ __('hr_payroll_reports.payslip.payment_references') }}</h5></div><div class="card-body"><ul class="mb-0">@forelse($payments as $payment)<li><span dir="ltr">{{ $payment->voucher_doc_num }}</span> — {{ number_format((float) $payment->amount, 2) }} {{ $payment->currency_code }} — {{ __('hr_payroll.status.'.$payment->status) }}</li>@empty<li>{{ __('hr_payroll.labels.no_payments') }}</li>@endforelse</ul></div></div></div>
    @endif
</div>
