@extends('layouts.app')

@inject('numbers', 'Modules\Core\Services\NumericFormatService')

@section('title', __('hr_payroll.title'))

@section('content')
    <div class="container-fluid px-0 px-sm-3 hr-cycle-shell" id="payroll-workspace">
        <x-admin.report.page :title="__('hr_payroll.title')" :description="__('hr_payroll.description')">
            <div class="card mb-3"><div class="card-body py-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div><h5 class="mb-1">{{ __('hr_payroll.workspace.title') }}</h5><div class="small text-muted">{{ __('hr_payroll.workspace.description') }}</div></div>
                <div class="d-flex flex-wrap gap-2"><a class="btn btn-sm btn-falcon-default" href="{{ route('admin.hr.employees.index') }}">{{ __('hr_payroll.workspace.employee_salaries') }}</a><a class="btn btn-sm btn-falcon-default" href="{{ route('admin.hr.employee-attendance.import.index') }}">{{ __('hr_payroll.workspace.import_attendance') }}</a><a class="btn btn-sm btn-falcon-default" href="{{ route('admin.hr.payroll-attendance-policies.index') }}">{{ __('hr_payroll.workspace.attendance_policy') }}</a></div>
            </div></div>

            <section class="card hr-section-card mb-3">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div><div class="hr-section-eyebrow">{{ __('hr_payroll.workspace.before_calculation') }}</div><h5 class="mb-0">{{ __('hr_payroll.workspace.readiness_title') }}</h5></div>
                    <span class="hr-status-chip"><span class="fas fa-shield-alt"></span>{{ __('hr_payroll.workspace.scope_note') }}</span>
                </div>
                <div class="card-body">
                    <div class="hr-readiness-list">
                        @foreach (['basic_item', 'salary_expense', 'payroll_payable', 'open_period', 'attendance_policy', 'cost_allocation', 'payment_source'] as $check)
                            <div @class(['hr-readiness-item', 'is-ready' => $payrollReadiness[$check], 'is-missing' => ! $payrollReadiness[$check]])>
                                <span @class(['fas', 'fa-check-circle' => $payrollReadiness[$check], 'fa-exclamation-triangle' => ! $payrollReadiness[$check]])></span>
                                <div><strong>{{ __('hr_payroll.readiness.'.$check.'.title') }}</strong><small>{{ __('hr_payroll.readiness.'.$check.'.'.($payrollReadiness[$check] ? 'ready' : 'missing')) }}</small></div>
                            </div>
                        @endforeach
                    </div>
                    <div class="row g-2 mt-2">
                        <div class="col-sm-6"><div class="hr-stat-card p-3"><span class="text-muted small">{{ __('hr_payroll.readiness.active_employees') }}</span><strong>{{ $payrollReadiness['active_employees'] }}</strong></div></div>
                        <div class="col-sm-6"><div class="hr-stat-card p-3"><span class="text-muted small">{{ __('hr_payroll.readiness.employees_with_salary') }}</span><strong>{{ $payrollReadiness['employees_with_salary'] }}</strong></div></div>
                    </div>
                </div>
            </section>

            <div id="payroll-feedback" class="hr-inline-feedback" role="alert" aria-live="assertive"></div>
            @can('hr.payroll_preparation.calculate')
                <div class="card hr-section-card mb-3">
                    <div class="card-header py-3"><div class="hr-section-eyebrow">{{ __('hr_payroll.workspace.step_one') }}</div><h5 class="mb-0">{{ __('hr_payroll.actions.calculate') }}</h5><div class="small text-muted mt-1">{{ __('hr_payroll.workspace.calculate_help') }}</div></div>
                    <div class="card-body">
                        <form id="payroll-calculation-form" class="row g-2 align-items-end" data-url="{{ route('admin.hr.payroll-runs.calculate') }}">
                            @csrf
                            <div class="col-12 col-md-3">
                                <x-forms.label for="payroll_period_start" :label="__('hr_payroll.labels.period_start')" :required="true" />
                                <x-forms.date-input id="payroll_period_start" name="period_start" :value="now()->startOfMonth()->toDateString()" required />
                            </div>
                            <div class="col-12 col-md-3">
                                <x-forms.label for="payroll_period_end" :label="__('hr_payroll.labels.period_end')" :required="true" />
                                <x-forms.date-input id="payroll_period_end" name="period_end" :value="now()->endOfMonth()->toDateString()" required />
                            </div>
                            <div class="col-12 col-md-4">
                                <x-forms.label for="payroll_branch" :label="__('hr_payroll.labels.branch')" />
                                <x-forms.select class="form-select" id="payroll_branch" name="branch_doc_num">
                                    <option value="">{{ __('hr_payroll.labels.all_branches') }}</option>
                                    @foreach ($branches as $branch)
                                        <option value="{{ $branch->doc_num }}">{{ $branch->name }} / {{ $branch->doc_num }}</option>
                                    @endforeach
                                </x-forms.select>
                            </div>
                            <div class="col-12 col-md-2">
                                <button class="btn btn-primary w-100" type="submit" @disabled(! $payrollReadiness['can_calculate'])>
                                    <span class="fas fa-calculator me-1"></span>{{ __('hr_payroll.actions.calculate') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            @endcan

                <div class="card hr-section-card mb-3">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('hr_payroll.labels.period') }}</th>
                                <th>{{ __('hr_payroll.labels.branch') }}</th>
                                <th class="text-end">{{ __('hr_payroll.labels.employees') }}</th>
                                <th class="text-end">{{ __('hr_payroll.labels.gross') }}</th>
                                <th class="text-end">{{ __('hr_payroll.labels.deductions') }}</th>
                                <th class="text-end">{{ __('hr_payroll.labels.net') }}</th>
                                <th>{{ __('hr_payroll.labels.status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($runs as $run)
                                <tr @class(['table-active' => (int) request('run') === (int) $run->id])>
                                    <td dir="ltr">{{ $run->period_start }} — {{ $run->period_end }}</td>
                                    <td>{{ $run->branch_name ?: __('hr_payroll.labels.all_branches') }}</td>
                                    <td class="text-end" dir="ltr">{{ $run->employee_count }}</td>
                                    <td class="text-end" dir="ltr">{{ $numbers->format($run->gross_amount) }}</td>
                                    <td class="text-end" dir="ltr">{{ $numbers->format($run->deduction_amount) }}</td>
                                    <td class="text-end fw-semibold" dir="ltr">{{ $numbers->format($run->net_amount) }}</td>
                                    <td><span class="badge bg-secondary-subtle text-secondary-emphasis">{{ __('hr_payroll.status.'.$run->status) }}</span></td>
                                    <td class="text-nowrap text-end">
                                        @can('hr.payroll_reconciliation.view')
                                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.hr.payroll-preparation.index', ['run' => $run->id, 'as_of' => now()->toDateString()]) }}">
                                                <span class="fas fa-balance-scale"></span>
                                            </a>
                                        @endcan
                                        <a class="btn btn-sm btn-outline-info" href="{{ route('admin.hr.payroll-runs.cost-preview', $run->id) }}" title="{{ __('hr_payroll.actions.cost_preview') }}">
                                            <span class="fas fa-project-diagram"></span>
                                        </a>
                                        @if ($run->status === 'calculated')
                                            @can('hr.payroll_approval.review')
                                                <button class="btn btn-sm btn-outline-primary js-payroll-action" type="button" data-confirm="review" data-url="{{ route('admin.hr.payroll-runs.review', $run->id) }}">{{ __('hr_payroll.actions.submit_review') }}</button>
                                            @endcan
                                        @endif
                                        @if ($run->status === 'under_review')
                                            @can('hr.payroll_approval.approve')
                                                <button class="btn btn-sm btn-success js-payroll-action" type="button" data-confirm="approve" data-url="{{ route('admin.hr.payroll-runs.approve', $run->id) }}">{{ __('hr_payroll.actions.approve_post') }}</button>
                                            @endcan
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td class="text-center text-muted py-4" colspan="8">{{ __('hr_payroll.labels.no_runs') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($runs->hasPages())
                    <div class="card-footer">{{ $runs->links() }}</div>
                @endif
            </div>

            @if ($selected)
                @php($summary = $selected['summary'])
                @can('hr.payroll_reconciliation.view')
                    <div class="row g-2 mb-3" data-payroll-reconciliation>
                        @foreach (['approved_payroll', 'payable', 'paid', 'remaining', 'gl_difference', 'cash_bank_difference'] as $key)
                            <div class="col-6 col-lg-2">
                                <div class="card h-100">
                                    <div class="card-body py-3 text-center">
                                        <div class="small text-muted">{{ __('hr_payroll.labels.'.($key === 'cash_bank_difference' ? 'cash_difference' : $key)) }}</div>
                                        <strong dir="ltr">{{ $numbers->format($summary[$key]) }}</strong>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="card mb-3">
                        <div class="card-header d-flex justify-content-between align-items-center py-2">
                            <h6 class="mb-0">{{ __('hr_payroll.labels.reconciliation_status') }}</h6>
                            <span @class(['badge', 'bg-success' => $selected['status'] === 'matched', 'bg-warning text-dark' => $selected['status'] !== 'matched'])>
                                {{ __('hr_payroll.reconciliation.'.$selected['status']) }}
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <div class="text-muted small">{{ __('hr_payroll.labels.journal') }}</div>
                                    <div dir="ltr">{{ $selected['journal']?->doc_num ?: '—' }}</div>
                                </div>
                                <div class="col-md-4">
                                    <div class="text-muted small">{{ __('hr_payroll.labels.as_of') }}</div>
                                    <div dir="ltr">{{ request('as_of', now()->toDateString()) }}</div>
                                </div>
                                <div class="col-md-4">
                                    <div class="text-muted small">{{ __('hr_payroll.labels.status') }}</div>
                                    <div>{{ __('hr_payroll.status.'.$selected['run']->status) }}</div>
                                </div>
                            </div>
                            <div class="alert alert-info mt-3 mb-0">{{ __('hr_payroll.reconciliation.draft_notice') }}</div>
                        </div>
                    </div>
                @endcan

                <div class="card mb-3">
                    <div class="card-header py-2"><h6 class="mb-0">{{ __('hr_payroll.labels.employee_payments') }}</h6><div class="small text-muted">{{ __('hr_payroll.workspace.payment_help') }}</div></div>
                    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                        <thead><tr><th>{{ __('hr_payroll.labels.employee') }}</th><th>{{ __('hr_payroll.labels.branch') }}</th><th class="text-end">{{ __('hr_payroll.labels.gross') }}</th><th class="text-end">{{ __('hr_payroll.labels.deductions') }}</th><th class="text-end">{{ __('hr_payroll.labels.net') }}</th><th class="text-end">{{ __('hr_payroll.labels.paid') }}</th><th class="text-end">{{ __('hr_payroll.labels.remaining') }}</th><th></th></tr></thead>
                        <tbody>@forelse($payrollEmployees as $employeePayroll)<tr><td><a href="{{ route('admin.hr.payslips.show', $employeePayroll->id) }}">{{ $employeePayroll->employee_name }}</a><div class="small text-muted" dir="ltr">{{ $employeePayroll->employee_doc_num }}</div></td><td>{{ $employeePayroll->branch_name ?: '—' }}</td><td class="text-end" dir="ltr">{{ $numbers->format($employeePayroll->gross_amount) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($employeePayroll->deduction_amount) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($employeePayroll->net_amount) }}</td><td class="text-end" dir="ltr">{{ $employeePayroll->covered_by_legacy_payment ? '—' : $numbers->format($employeePayroll->paid_amount) }}</td><td class="text-end fw-semibold" dir="ltr">{{ $employeePayroll->covered_by_legacy_payment ? '—' : $numbers->format($employeePayroll->remaining_amount) }}</td><td class="text-end">
                            @if ($employeePayroll->covered_by_legacy_payment)
                                <span class="badge badge-subtle-warning">{{ __('hr_payroll.labels.legacy_payment_covered') }}</span>
                            @elseif ($selected['run']->status === 'posted' && bccomp((string) $employeePayroll->remaining_amount, '0.0000', 4) > 0 && bccomp((string) $summary['remaining'], '0.0000', 4) > 0)
                                @can('hr.payroll_payment.create') @can('cash_payment_vouchers.create')<button class="btn btn-sm btn-primary js-open-payroll-payment" type="button" data-bs-toggle="modal" data-bs-target="#payroll-payment-modal" data-payslip-id="{{ $employeePayroll->id }}" data-employee="{{ $employeePayroll->employee_name }}" data-branch-id="{{ $employeePayroll->branch_id }}" data-remaining="{{ $employeePayroll->remaining_amount }}">{{ __('hr_payroll.actions.pay_employee') }}</button>@endcan @endcan
                            @else <span class="badge badge-subtle-success">{{ __('hr_payroll.labels.settled') }}</span> @endif
                        </td></tr>@empty<tr><td colspan="8" class="text-center text-muted py-3">{{ __('hr_payroll.labels.no_employees') }}</td></tr>@endforelse</tbody>
                    </table></div>
                </div>

                <div class="modal fade" id="payroll-payment-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">{{ __('hr_payroll.actions.pay_employee') }}: <span class="js-payment-employee"></span></h5><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div><form id="payroll-payment-form" data-url="{{ route('admin.hr.payroll-runs.payments.store', $selected['run']->id) }}"><div class="modal-body"><x-forms.input type="hidden" name="payslip_id" id="payroll_payslip_id" /><x-forms.input type="hidden" name="idempotency_key" value="{{ $paymentIdempotencyKey }}" />
                    <div class="mb-3"><x-forms.label for="payroll_cashbox" :label="__('hr_payroll.labels.cashbox')" :required="true" /><x-forms.select class="form-select" id="payroll_cashbox" name="cashbox_doc_num" required><option value="">{{ __('common.placeholders.select') }}</option>@foreach ($payrollPaymentSources as $source)<option value="{{ $source['doc_num'] }}" data-branch-id="{{ $source['branch_id'] }}">{{ $source['branch_name'] }} — {{ $source['name'] }}</option>@endforeach</x-forms.select></div>
                    <div class="row g-2"><div class="col-6"><x-forms.label for="payroll_payment_amount" :label="__('hr_payroll.labels.amount')" :required="true" /><x-forms.numeric-input id="payroll_payment_amount" name="amount" :scale="4" step="0.0001" min="0.0001" required /></div><div class="col-6"><x-forms.label for="payroll_payment_date" :label="__('hr_payroll.labels.payment_date')" :required="true" /><x-forms.date-input id="payroll_payment_date" name="payment_date" :value="now()->toDateString()" required /></div></div>
                    <div class="mt-3"><x-forms.label for="payroll_payment_reference" :label="__('hr_payroll.labels.reference')" /><x-forms.input id="payroll_payment_reference" name="reference" /></div>
                </div><div class="modal-footer"><button class="btn btn-falcon-default" type="button" data-bs-dismiss="modal">{{ __('common.actions.cancel') }}</button><button class="btn btn-primary" type="submit">{{ __('hr_payroll.actions.create_payment') }}</button></div></form></div></div></div>

                @canany(['hr.payroll_payment.create', 'hr.payroll_reconciliation.view'])
                    <div class="card">
                        <div class="card-header py-2"><h6 class="mb-0">{{ __('hr_payroll.labels.payments') }}</h6></div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>{{ __('hr_payroll.labels.employee') }}</th><th>{{ __('hr_payroll.labels.voucher') }}</th><th>{{ __('hr_payroll.labels.payment_date') }}</th><th class="text-end">{{ __('hr_payroll.labels.amount') }}</th><th>{{ __('hr_payroll.labels.status') }}</th><th>{{ __('hr_payroll.labels.journal') }}</th></tr></thead>
                            <tbody>
                                @forelse ($selected['payments'] as $payment)
                                    <tr>
                                        <td>{{ $payment->employee_name ?: __('hr_payroll.labels.legacy_branch_payment') }}</td>
                                        <td><a href="{{ route('admin.finance.cash-payment-vouchers.show', $payment->voucher_doc_num) }}">{{ $payment->voucher_doc_num }}</a></td>
                                        <td dir="ltr">{{ $payment->voucher_date }}</td>
                                        <td class="text-end" dir="ltr">{{ $numbers->format($payment->amount) }}</td>
                                        <td>{{ __('hr_payroll.status.'.$payment->status) }}</td>
                                        <td dir="ltr">{{ $payment->journal_doc_num ?: ($payment->reversal_journal_doc_num ?: '—') }}</td>
                                    </tr>
                                @empty
                                    <tr><td class="text-center text-muted py-3" colspan="6">{{ __('hr_payroll.labels.no_payments') }}</td></tr>
                                @endforelse
                            </tbody>
                            </table>
                        </div>
                    </div>
                @endcanany
            @endif
        </x-admin.report.page>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const token = document.querySelector('meta[name="csrf-token"]')?.content;
            const feedback = document.getElementById('payroll-feedback');
            const messages = @json(__('hr_payroll.javascript'));

            const showFeedback = (message, type = 'danger') => {
                if (!feedback) return;
                feedback.className = `hr-inline-feedback alert alert-${type}`;
                feedback.textContent = message;
                feedback.scrollIntoView({behavior: 'smooth', block: 'center'});
            };

            const confirmAction = async key => {
                if (!window.Swal) return true;
                const result = await window.Swal.fire({
                    icon: key === 'approve' ? 'warning' : 'question',
                    title: messages.confirm[key].title,
                    text: messages.confirm[key].text,
                    showCancelButton: true,
                    confirmButtonText: messages.confirm_action,
                    cancelButtonText: messages.cancel,
                    reverseButtons: document.documentElement.dir === 'rtl',
                    heightAuto: false,
                });
                return result.isConfirmed;
            };

            const success = message => window.Swal
                ? window.Swal.fire({icon: 'success', text: message, timer: 1600, showConfirmButton: false, heightAuto: false})
                : showFeedback(message, 'success');

            const submitJson = async (url, payload) => {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token},
                    body: JSON.stringify(payload),
                });
                const body = await response.json();
                if (!response.ok) {
                    const errors = body.errors ? Object.values(body.errors).flat().join('\n') : body.message;
                    throw new Error(errors || response.statusText);
                }
                return body;
            };

            document.getElementById('payroll-calculation-form')?.addEventListener('submit', async event => {
                event.preventDefault();
                const form = event.currentTarget;
                const data = Object.fromEntries(new FormData(form).entries());
                delete data._token;
                try {
                    const result = await submitJson(form.dataset.url, data);
                    await success(result.message);
                    window.location.assign(`{{ route('admin.hr.payroll-preparation.index') }}?run=${result.data.run_id}&as_of={{ now()->toDateString() }}`);
                } catch (error) {
                    showFeedback(error.message);
                }
            });

            document.querySelectorAll('.js-payroll-action').forEach(button => button.addEventListener('click', async () => {
                if (!await confirmAction(button.dataset.confirm)) return;
                button.disabled = true;
                try {
                    const result = await submitJson(button.dataset.url, {});
                    await success(result.message);
                    window.location.reload();
                } catch (error) {
                    button.disabled = false;
                    showFeedback(error.message);
                }
            }));

            document.getElementById('payroll-payment-form')?.addEventListener('submit', async event => {
                event.preventDefault();
                const form = event.currentTarget;
                const data = Object.fromEntries(new FormData(form).entries());
                delete data._token;
                if (!await confirmAction('payment')) return;
                try {
                    const result = await submitJson(form.dataset.url, data);
                    window.location.assign(result.data.voucher_url);
                } catch (error) {
                    showFeedback(error.message);
                }
            });

            document.querySelectorAll('.js-open-payroll-payment').forEach(button => button.addEventListener('click', () => {
                document.getElementById('payroll_payslip_id').value = button.dataset.payslipId;
                document.getElementById('payroll_payment_amount').value = button.dataset.remaining;
                document.querySelector('.js-payment-employee').textContent = button.dataset.employee;
                const cashbox = document.getElementById('payroll_cashbox');
                [...cashbox.options].forEach(option => {
                    option.hidden = option.value !== '' && option.dataset.branchId !== button.dataset.branchId;
                });
                cashbox.value = '';
            }));
        })();
    </script>
@endpush

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/modules/HR/hr-cycle.css') }}?v=20260922">
@endpush
