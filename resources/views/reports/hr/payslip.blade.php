@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
    <table class="document-meta-table">
        <tr><td colspan="3"><strong>{{ __('hr_payroll_reports.columns.employee') }}:</strong> {{ $payslip->employee_name }} <span dir="ltr">({{ $payslip->employee_doc_num }})</span></td></tr>
        <tr><td><strong>{{ __('hr_payroll_reports.columns.period') }}</strong><br><span dir="ltr">{{ $payslip->period_start }} — {{ $payslip->period_end }}</span></td><td><strong>{{ __('hr_payroll_reports.columns.branch') }}</strong><br>{{ $payslip->branch_name ?: '—' }}</td><td><strong>{{ __('hr_payroll_reports.columns.status') }}</strong><br>{{ __('hr_payroll.status.'.$payslip->status) }}</td></tr>
        <tr><td><strong>{{ __('hr_payroll_reports.columns.gross') }}</strong><br>{{ $numbers->format($payslip->gross_amount) }}</td><td><strong>{{ __('hr_payroll_reports.columns.deductions') }}</strong><br>{{ $numbers->format($payslip->deduction_amount) }}</td><td><strong>{{ __('hr_payroll_reports.columns.net') }}</strong><br>{{ $numbers->format($payslip->net_amount) }} {{ $payslip->currency_code }}</td></tr>
    </table>

    <h3>{{ __('hr_payroll_reports.payslip.items') }}</h3>
    <table class="report-table"><thead><tr><th>{{ __('hr_payroll_reports.columns.item') }}</th><th>{{ __('hr_payroll_reports.columns.direction') }}</th><th>{{ __('hr_payroll_reports.columns.amount') }}</th></tr></thead><tbody>@forelse($items as $item)<tr><td>{{ $item->display_name }}</td><td>{{ __('hr_payroll_reports.directions.'.$item->direction) }}</td><td>{{ $numbers->format($item->amount) }}</td></tr>@empty<tr><td colspan="3">{{ __('hr_payroll_reports.payslip.no_items') }}</td></tr>@endforelse</tbody></table>

    <h3>{{ __('hr_payroll_reports.payslip.attendance_summary') }}</h3>
    <table class="document-meta-table"><tr>@foreach (['finalized_days', 'worked_minutes', 'late_minutes', 'early_leave_minutes', 'recorded_overtime_minutes'] as $key)<td><strong>{{ __('hr_payroll_reports.attendance.'.$key) }}</strong><br>{{ (int) data_get($attendance, $key, 0) }}</td>@endforeach</tr></table>

    @if ($showRunPayments)
        <h3>{{ __('hr_payroll_reports.payslip.payment_references') }}</h3>
        <table class="report-table"><thead><tr><th>{{ __('hr_payroll_reports.columns.voucher') }}</th><th>{{ __('hr_payroll_reports.columns.payment_date') }}</th><th>{{ __('hr_payroll_reports.columns.amount') }}</th><th>{{ __('hr_payroll_reports.columns.status') }}</th></tr></thead><tbody>@forelse($payments as $payment)<tr><td dir="ltr">{{ $payment->voucher_doc_num }}</td><td dir="ltr">{{ $payment->voucher_date }}</td><td>{{ $numbers->format($payment->amount) }} {{ $payment->currency_code }}</td><td>{{ __('hr_payroll.status.'.$payment->status) }}</td></tr>@empty<tr><td colspan="4">{{ __('hr_payroll.labels.no_payments') }}</td></tr>@endforelse</tbody></table>
    @endif
@endsection
