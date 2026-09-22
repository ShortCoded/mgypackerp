<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>{{ __('hr_payroll_reports.payslip.title') }} - {{ $payslip->employee_name }}</title>
    <style>
        body{font-family:"DejaVu Sans",Tahoma,sans-serif;color:#24354b;margin:24px;font-size:13px}header{display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #2c7be5;padding-bottom:12px;margin-bottom:18px}h1{font-size:20px;margin:0}.meta,.totals{width:100%;border-collapse:collapse;margin-bottom:18px}.meta td,.totals td,th{border:1px solid #d8e2ef;padding:8px}.items{width:100%;border-collapse:collapse}.items th,.items td{border:1px solid #d8e2ef;padding:8px}.num{text-align:left;direction:ltr}.actions{margin-bottom:16px}@media print{.actions{display:none}body{margin:0}}
    </style>
</head>
<body onload="window.print()">
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
    <div class="actions"><button type="button" onclick="window.print()">{{ __('hr_payroll_reports.actions.print') }}</button></div>
    <header><div><h1>{{ __('hr_payroll_reports.payslip.title') }}</h1><div>{{ $payslip->employee_name }} — <span dir="ltr">{{ $payslip->employee_doc_num }}</span></div></div><strong>{{ data_get($companyPrintIdentity, 'name', '') }}</strong></header>
    <table class="meta"><tr><td>{{ __('hr_payroll_reports.columns.period') }}<br><strong dir="ltr">{{ $payslip->period_start }} — {{ $payslip->period_end }}</strong></td><td>{{ __('hr_payroll_reports.columns.branch') }}<br><strong>{{ $payslip->branch_name ?: '—' }}</strong></td><td>{{ __('hr_payroll_reports.columns.status') }}<br><strong>{{ __('hr_payroll.status.'.$payslip->status) }}</strong></td></tr></table>
    <table class="totals"><tr><td>{{ __('hr_payroll_reports.columns.gross') }}<br><strong class="num">{{ $numbers->format($payslip->gross_amount) }}</strong></td><td>{{ __('hr_payroll_reports.columns.deductions') }}<br><strong class="num">{{ $numbers->format($payslip->deduction_amount) }}</strong></td><td>{{ __('hr_payroll_reports.columns.net') }}<br><strong class="num">{{ $numbers->format($payslip->net_amount) }} {{ $payslip->currency_code }}</strong></td></tr></table>
    <table class="items"><thead><tr><th>{{ __('hr_payroll_reports.columns.item') }}</th><th>{{ __('hr_payroll_reports.columns.direction') }}</th><th>{{ __('hr_payroll_reports.columns.amount') }}</th></tr></thead><tbody>@forelse($items as $item)<tr><td>{{ $item->name ?: __('hr_payroll_reports.payslip.default_item') }}</td><td>{{ __('hr_payroll_reports.directions.'.$item->direction) }}</td><td class="num">{{ $numbers->format($item->amount) }}</td></tr>@empty<tr><td colspan="3">{{ __('hr_payroll_reports.payslip.no_items') }}</td></tr>@endforelse</tbody></table>
</body>
</html>
