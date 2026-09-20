@extends('layouts.app')

@section('title', __('hr_payroll_reports.'.$type.'.title'))

@section('content')
    @php($isPayroll = $type === 'payroll')
    @php($routeName = $isPayroll ? 'admin.hr.reports.payroll' : 'admin.hr.reports.payments')
    @php($exportPermission = $isPayroll ? 'hr.payroll_reports.export' : 'hr.payroll_payment_reports.export')
    @php($exportOptions = collect(['csv' => 'file-csv', 'xlsx' => 'file-excel', 'pdf' => 'file-pdf'])->map(fn ($icon, $format) => ['permission' => $exportPermission, 'url' => route($routeName.'.export', [...$filters, 'format' => $format]), 'label' => strtoupper($format), 'icon' => $icon, 'newTab' => $format === 'pdf'])->values()->all())
    <div class="container-fluid px-0 px-sm-3 admin-report-page">
        <x-admin.report.page :title="__('hr_payroll_reports.'.$type.'.title')" :description="__('hr_payroll_reports.'.$type.'.description')">
            <x-slot:actions>
                <x-admin.report.actions-toolbar
                    filter-target="hr-payroll-report-filters"
                    :refresh-url="request()->fullUrl()"
                    :export-options="$exportOptions" />
            </x-slot:actions>

            <x-admin.report.filter-panel
                id="hr-payroll-report-filters"
                :action="route($routeName)"
                method="GET"
                :expanded="collect($filters)->filter(fn ($value) => $value !== null && $value !== '')->isNotEmpty()"
                :reset-url="route($routeName)">
                <div class="col-6 col-lg-2"><x-forms.label for="period_from" :label="__('hr_payroll_reports.filters.period_from')" /><x-forms.date-input id="period_from" name="period_from" :value="$filters['period_from'] ?? null" /></div>
                <div class="col-6 col-lg-2"><x-forms.label for="period_to" :label="__('hr_payroll_reports.filters.period_to')" /><x-forms.date-input id="period_to" name="period_to" :value="$filters['period_to'] ?? null" /></div>
                <div class="col-12 col-lg-3">
                    <x-forms.label for="branch_doc_num" :label="__('hr_payroll_reports.columns.branch')" />
                    <x-forms.select id="branch_doc_num" name="branch_doc_num" class="form-select">
                        <option value="">{{ __('hr_payroll.labels.all_branches') }}</option>
                        @foreach ($branches as $branch)<option value="{{ $branch->doc_num }}" @selected(($filters['branch_doc_num'] ?? null) === $branch->doc_num)>{{ $branch->name }}</option>@endforeach
                    </x-forms.select>
                </div>
                <div class="col-6 col-lg-2"><x-forms.label for="run_id" :label="__('hr_payroll_reports.columns.run')" /><x-forms.input id="run_id" name="run_id" type="number" min="1" :value="$filters['run_id'] ?? null" /></div>
                <div class="col-6 col-lg-2"><x-forms.label for="status" :label="__('hr_payroll_reports.columns.status')" /><x-forms.input id="status" name="status" :value="$filters['status'] ?? null" /></div>
                @if ($isPayroll)<div class="col-12 col-lg-3"><x-forms.label for="employee" :label="__('hr_payroll_reports.columns.employee')" /><x-forms.input id="employee" name="employee" :value="$filters['employee'] ?? null" /></div>@endif
            </x-admin.report.filter-panel>

            <div class="row g-2 mb-3">
                @if ($isPayroll)
                    @foreach ($report['totals'] as $currencyTotals)
                        @foreach (['gross', 'deductions', 'net'] as $key)
                            <div class="col-6 col-lg-3"><div class="card"><div class="card-body py-3 text-center"><div class="small text-muted">{{ __('hr_payroll_reports.totals.'.$key) }}</div><strong dir="ltr">{{ number_format((float) $currencyTotals[$key], 2) }} {{ $currencyTotals['currency_code'] ?: __('hr_payroll_reports.unknown_currency') }}</strong></div></div></div>
                        @endforeach
                    @endforeach
                @else
                    @foreach ($report['totals'] as $key => $value)
                        <div class="col-6 col-lg-3"><div class="card"><div class="card-body py-3 text-center"><div class="small text-muted">{{ __('hr_payroll_reports.totals.'.$key) }}</div><strong dir="ltr">{{ number_format((float) $value, 2) }} {{ $report['currency_code'] }}</strong></div></div></div>
                    @endforeach
                @endif
            </div>

            <div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
                <thead><tr>
                    @foreach ($isPayroll ? ['run', 'period', 'branch', 'employee', 'currency', 'gross', 'deductions', 'net', 'status', 'payslip'] : ['run', 'period', 'branch', 'voucher', 'payment_date', 'currency', 'amount', 'status', 'journal'] as $column)
                        <th>{{ __('hr_payroll_reports.columns.'.$column) }}</th>
                    @endforeach
                </tr></thead>
                <tbody>
                    @forelse ($report['rows'] as $row)
                        <tr>
                            <td dir="ltr">{{ $row->payroll_run_id }}</td>
                            <td dir="ltr">{{ $row->period_start }} — {{ $row->period_end }}</td>
                            <td>{{ $row->branch_name ?: '—' }}</td>
                            @if ($isPayroll)
                                <td>{{ $row->employee_name }} <span class="text-muted" dir="ltr">{{ $row->employee_doc_num }}</span></td>
                                <td dir="ltr">{{ $row->currency_code ?: __('hr_payroll_reports.unknown_currency') }}</td>
                                <td class="text-end" dir="ltr">{{ number_format((float) $row->gross_amount, 2) }}</td>
                                <td class="text-end" dir="ltr">{{ number_format((float) $row->deduction_amount, 2) }}</td>
                                <td class="text-end fw-semibold" dir="ltr">{{ number_format((float) $row->net_amount, 2) }}</td>
                                <td>{{ __('hr_payroll.status.'.$row->status) }}</td>
                                <td>@can('hr.payslips.view')<a class="btn btn-sm btn-outline-primary" href="{{ route('admin.hr.payslips.show', $row->id) }}">{{ __('hr_payroll_reports.actions.view_payslip') }}</a>@else—@endcan</td>
                            @else
                                <td>@can('cash_payment_vouchers.view')<a href="{{ route('admin.finance.cash-payment-vouchers.show', $row->voucher_doc_num) }}">{{ $row->voucher_doc_num }}</a>@else<span dir="ltr">{{ $row->voucher_doc_num }}</span>@endcan</td>
                                <td dir="ltr">{{ $row->voucher_date }}</td>
                                <td dir="ltr">{{ $row->currency_code }}</td>
                                <td class="text-end" dir="ltr">{{ number_format((float) $row->amount, 2) }}</td>
                                <td>{{ __('hr_payroll.status.'.$row->status) }}</td>
                                <td dir="ltr">{{ $row->journal_doc_num ?: ($row->reversal_journal_doc_num ?: '—') }}</td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $isPayroll ? 10 : 9 }}" class="text-center text-muted py-4">{{ __('hr_payroll_reports.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table></div>@if($report['rows']->hasPages())<div class="card-footer">{{ $report['rows']->links() }}</div>@endif</div>
        </x-admin.report.page>
    </div>
@endsection
