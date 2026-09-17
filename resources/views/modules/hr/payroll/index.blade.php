@extends('layouts.app')

@section('title', __('hr_payroll.title'))

@section('content')
    <div class="container-fluid px-0 px-sm-3" id="payroll-workspace">
        <x-admin.report.page :title="__('hr_payroll.title')" :description="__('hr_payroll.description')">
            @can('hr.payroll_preparation.calculate')
                <div class="card mb-3">
                    <div class="card-header py-2"><h6 class="mb-0">{{ __('hr_payroll.actions.calculate') }}</h6></div>
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
                                <button class="btn btn-primary w-100" type="submit">
                                    <span class="fas fa-calculator me-1"></span>{{ __('hr_payroll.actions.calculate') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            @endcan

            <div class="card mb-3">
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
                                    <td class="text-end" dir="ltr">{{ number_format((float) $run->gross_amount, 2) }}</td>
                                    <td class="text-end" dir="ltr">{{ number_format((float) $run->deduction_amount, 2) }}</td>
                                    <td class="text-end fw-semibold" dir="ltr">{{ number_format((float) $run->net_amount, 2) }}</td>
                                    <td><span class="badge bg-secondary-subtle text-secondary-emphasis">{{ __('hr_payroll.status.'.$run->status) }}</span></td>
                                    <td class="text-nowrap text-end">
                                        @can('hr.payroll_reconciliation.view')
                                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.hr.payroll-preparation.index', ['run' => $run->id, 'as_of' => now()->toDateString()]) }}">
                                                <span class="fas fa-balance-scale"></span>
                                            </a>
                                        @endcan
                                        @if ($run->status === 'calculated')
                                            @can('hr.payroll_approval.review')
                                                <button class="btn btn-sm btn-outline-primary js-payroll-action" type="button" data-url="{{ route('admin.hr.payroll-runs.review', $run->id) }}">{{ __('hr_payroll.actions.submit_review') }}</button>
                                            @endcan
                                        @endif
                                        @if ($run->status === 'under_review')
                                            @can('hr.payroll_approval.approve')
                                                <button class="btn btn-sm btn-success js-payroll-action" type="button" data-url="{{ route('admin.hr.payroll-runs.approve', $run->id) }}">{{ __('hr_payroll.actions.approve_post') }}</button>
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
                                        <strong dir="ltr">{{ number_format((float) $summary[$key], 2) }}</strong>
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

                @if ($selected['run']->status === 'posted')
                    @can('hr.payroll_payment.create')
                        @can('cash_payment_vouchers.create')
                            <div class="card mb-3">
                                <div class="card-header py-2"><h6 class="mb-0">{{ __('hr_payroll.actions.create_payment') }}</h6></div>
                                <div class="card-body">
                                    <form id="payroll-payment-form" class="row g-2 align-items-end" data-url="{{ route('admin.hr.payroll-runs.payments.store', $selected['run']->id) }}">
                                        @csrf
                                        <input type="hidden" name="idempotency_key" value="{{ $paymentIdempotencyKey }}">
                                        <div class="col-12 col-md-4">
                                            <x-forms.label for="payroll_cashbox" :label="__('hr_payroll.labels.cashbox')" :required="true" />
                                            <x-forms.select class="form-select" id="payroll_cashbox" name="cashbox_doc_num" required>
                                                <option value="">{{ __('common.placeholders.select') }}</option>
                                                @foreach ($cashboxes as $cashbox)
                                                    <option value="{{ $cashbox->doc_num }}">{{ $cashbox->name }} / {{ $cashbox->doc_num }}</option>
                                                @endforeach
                                            </x-forms.select>
                                        </div>
                                        <div class="col-6 col-md-2">
                                            <x-forms.label for="payroll_payment_amount" :label="__('hr_payroll.labels.amount')" :required="true" />
                                            <x-forms.input id="payroll_payment_amount" name="amount" type="number" step="0.0001" min="0.0001" :value="$summary['remaining']" required />
                                        </div>
                                        <div class="col-6 col-md-2">
                                            <x-forms.label for="payroll_payment_date" :label="__('hr_payroll.labels.payment_date')" :required="true" />
                                            <x-forms.date-input id="payroll_payment_date" name="payment_date" :value="now()->toDateString()" required />
                                        </div>
                                        <div class="col-12 col-md-2">
                                            <x-forms.label for="payroll_payment_reference" :label="__('hr_payroll.labels.reference')" />
                                            <x-forms.input id="payroll_payment_reference" name="reference" />
                                        </div>
                                        <div class="col-12 col-md-2">
                                            <button class="btn btn-primary w-100" type="submit">{{ __('hr_payroll.actions.create_payment') }}</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        @endcan
                    @endcan
                @endif

                @canany(['hr.payroll_payment.create', 'hr.payroll_reconciliation.view'])
                    <div class="card">
                        <div class="card-header py-2"><h6 class="mb-0">{{ __('hr_payroll.labels.payments') }}</h6></div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>{{ __('hr_payroll.labels.voucher') }}</th><th>{{ __('hr_payroll.labels.payment_date') }}</th><th class="text-end">{{ __('hr_payroll.labels.amount') }}</th><th>{{ __('hr_payroll.labels.status') }}</th><th>{{ __('hr_payroll.labels.journal') }}</th></tr></thead>
                            <tbody>
                                @forelse ($selected['payments'] as $payment)
                                    <tr>
                                        <td><a href="{{ route('admin.finance.cash-payment-vouchers.show', $payment->voucher_doc_num) }}">{{ $payment->voucher_doc_num }}</a></td>
                                        <td dir="ltr">{{ $payment->voucher_date }}</td>
                                        <td class="text-end" dir="ltr">{{ number_format((float) $payment->amount, 2) }}</td>
                                        <td>{{ __('hr_payroll.status.'.$payment->status) }}</td>
                                        <td dir="ltr">{{ $payment->journal_doc_num ?: ($payment->reversal_journal_doc_num ?: '—') }}</td>
                                    </tr>
                                @empty
                                    <tr><td class="text-center text-muted py-3" colspan="5">{{ __('hr_payroll.labels.no_payments') }}</td></tr>
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
                    window.location.assign(`{{ route('admin.hr.payroll-preparation.index') }}?run=${result.data.run_id}&as_of={{ now()->toDateString() }}`);
                } catch (error) {
                    window.alert(error.message);
                }
            });

            document.querySelectorAll('.js-payroll-action').forEach(button => button.addEventListener('click', async () => {
                button.disabled = true;
                try {
                    await submitJson(button.dataset.url, {});
                    window.location.reload();
                } catch (error) {
                    button.disabled = false;
                    window.alert(error.message);
                }
            }));

            document.getElementById('payroll-payment-form')?.addEventListener('submit', async event => {
                event.preventDefault();
                const form = event.currentTarget;
                const data = Object.fromEntries(new FormData(form).entries());
                delete data._token;
                try {
                    const result = await submitJson(form.dataset.url, data);
                    window.location.assign(result.data.voucher_url);
                } catch (error) {
                    window.alert(error.message);
                }
            });
        })();
    </script>
@endpush
