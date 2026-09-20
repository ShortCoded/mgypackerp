@extends('reports.layouts.pdf')

@section('report')
    <table class="document-meta-table">
        <tr><td colspan="4"><strong>{{ $payslip->employee_name }}</strong> / <span dir="ltr">{{ $payslip->employee_doc_num }}</span></td></tr>
        <tr><td><strong>{{ __('hr_payroll_reports.columns.period') }}</strong><br><span dir="ltr">{{ $payslip->period_start }} — {{ $payslip->period_end }}</span></td><td><strong>{{ __('hr_payroll_reports.columns.branch') }}</strong><br>{{ $payslip->branch_name ?: '—' }}</td><td><strong>{{ __('hr_payroll_reports.columns.status') }}</strong><br>{{ __('hr_payroll.status.'.$payslip->status) }}</td><td><strong>{{ __('hr_payroll_reports.columns.currency') }}</strong><br><span dir="ltr">{{ $payslip->currency_code }}</span></td></tr>
        <tr><td><strong>{{ __('hr_payroll_reports.columns.gross') }}</strong><br>{{ number_format((float) $payslip->gross_amount, 2) }}</td><td><strong>{{ __('hr_payroll_reports.columns.deductions') }}</strong><br>{{ number_format((float) $payslip->deduction_amount, 2) }}</td><td colspan="2"><strong>{{ __('hr_payroll_reports.columns.net') }}</strong><br>{{ number_format((float) $payslip->net_amount, 2) }}</td></tr>
    </table>

    <h3>{{ __('hr_payroll_reports.payslip.items') }}</h3>
    <table class="report-table">
        <thead><tr><th>{{ __('hr_payroll_reports.columns.item') }}</th><th>{{ __('hr_payroll_reports.columns.direction') }}</th><th>{{ __('hr_payroll_reports.columns.source') }}</th><th>{{ __('hr_payroll_reports.columns.amount') }}</th></tr></thead>
        <tbody>@foreach($items as $item)<tr><td><span dir="ltr">{{ $item->code }}</span>@if($item->name)<br>{{ $item->name }}@endif</td><td>{{ __('hr_payroll_reports.directions.'.$item->direction) }}</td><td>{{ $item->source_type ?: '—' }}@if($item->source_snapshot !== [])<br><span dir="ltr">{{ collect($item->source_snapshot)->map(fn ($value, $key) => $key.': '.(is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value))->implode(' | ') }}</span>@endif</td><td class="text-end">{{ number_format((float) $item->amount, 2) }}</td></tr>@endforeach</tbody>
    </table>

    <h3>{{ __('hr_payroll_reports.payslip.payroll_snapshot') }}</h3>
    <table class="document-meta-table"><tr><td><strong>{{ __('hr_payroll_reports.payslip.salary_source') }}</strong><br><span dir="ltr">{{ data_get($input, 'salary_source.type', '—') }}@if(data_get($input, 'salary_source.id')) #{{ data_get($input, 'salary_source.id') }}@endif</span></td><td><strong>{{ __('hr_payroll_reports.payslip.approved_requests') }}</strong><br>{{ count(data_get($input, 'approved_request_ids', [])) }}</td><td><strong>{{ __('hr_payroll_reports.columns.net') }}</strong><br>{{ number_format((float) data_get($input, 'net', 0), 2) }}</td></tr></table>

    <h3>{{ __('hr_payroll_reports.payslip.attendance_snapshot') }}</h3>
    <table class="report-table"><tbody>@foreach($attendance as $key => $value)<tr><th>{{ str($key)->replace('_', ' ')->title() }}</th><td dir="ltr">{{ is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value }}</td></tr>@endforeach</tbody></table>

    @if ($showRunPayments)
        <h3>{{ __('hr_payroll_reports.payslip.payment_references') }}</h3>
        <table class="report-table"><thead><tr><th>{{ __('hr_payroll_reports.columns.voucher') }}</th><th>{{ __('hr_payroll_reports.columns.payment_date') }}</th><th>{{ __('hr_payroll_reports.columns.currency') }}</th><th>{{ __('hr_payroll_reports.columns.amount') }}</th><th>{{ __('hr_payroll_reports.columns.status') }}</th><th>{{ __('hr_payroll_reports.columns.journal') }}</th></tr></thead><tbody>@forelse($payments as $payment)<tr><td dir="ltr">{{ $payment->voucher_doc_num }}</td><td dir="ltr">{{ $payment->voucher_date }}</td><td dir="ltr">{{ $payment->currency_code }}</td><td>{{ number_format((float) $payment->amount, 2) }}</td><td>{{ __('hr_payroll.status.'.$payment->status) }}</td><td dir="ltr">{{ $payment->journal_doc_num ?: '—' }}</td></tr>@empty<tr><td colspan="6">{{ __('hr_payroll.labels.no_payments') }}</td></tr>@endforelse</tbody></table>
    @endif
@endsection
