<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\JournalEntry;
use Modules\Auth\Services\DefaultLoginContextService;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\HR\Services\PayrollCalculationService;
use Modules\HR\Services\PayrollCorrectionService;
use Modules\HR\Services\PayrollLifecycleService;
use Modules\HR\Services\PayrollReconciliationService;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/PayrollFinancialSupport.php';
require_once __DIR__.'/ClosurePostgresRaceSupport.php';

beforeEach(function (): void {
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBe('mgypack_acceptance_closure_20261003')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
});

test('prepare labelled payroll correction acceptance data for real browser and simultaneous posting paths', function (): void {
    if (getenv('MGYPACK_PAYROLL_CORRECTION_RUNTIME_CREATE') !== '1') {
        $this->markTestSkipped('Explicit isolated synthetic preparation only.');
    }
    $path = '/tmp/mgypack-payroll-correction-runtime-20261003.json';
    if (file_exists($path)) {
        $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        expect($manifest['synthetic'])->toBeTrue()->and(Company::findOrFail($manifest['company_id'])->name)->toContain('SYNTHETIC');

        return;
    }
    $manifest = DB::transaction(function (): array {
        expect(Company::withTrashed()->where('doc_num', 'PAY-COMP-07001')->exists())->toBeFalse();
        $fixture = payrollFinancialFixture(true);
        $fixture['company']->update(['name' => 'SYNTHETIC payroll closure company', 'legal_name' => 'SYNTHETIC payroll closure company']);
        $fixture['employee']->update(['full_name' => 'SYNTHETIC payroll closure employee', 'name' => 'SYNTHETIC payroll closure employee']);
        $fixture['period']->update(['to_date' => '2026-09-30']);
        $later = FinancialPeriod::query()->create(['company_id' => $fixture['company']->id, 'doc_number' => 7002, 'doc_num' => 'PAY-FP-07002',
            'name' => 'SYNTHETIC October correction period', 'from_date' => '2026-10-01', 'to_date' => '2026-12-31', 'is_closed' => false]);
        $permissions = ['hr.payroll_preparation.view', 'hr.payroll_preparation.calculate', 'hr.payroll_approval.review', 'hr.payroll_approval.approve',
            'hr.payroll_approval.correct', 'hr.payroll_approval.correct_approve', 'hr.payroll_approval.correct_later_period',
            'hr.payroll_payment.create', 'hr.payroll_reports.view', 'hr.payroll_reports.export', 'hr.payslips.view',
            'cash_payment_vouchers.create', 'cash_payment_vouchers.approve', 'cash_payment_vouchers.delete', 'cash_payment_vouchers.cancel'];
        $preparer = payrollFinancialActor($permissions);
        $approver = payrollFinancialActor($permissions);
        $secondApprover = payrollFinancialActor($permissions);
        auth()->login($preparer);
        request()->setUserResolver(fn (): User => $preparer);
        request()->setLaravelSession(app('session.store'));
        session(payrollFinancialContext($fixture));
        Carbon::setTestNow('2026-10-03 12:00:00');
        $browserRunId = payrollCorrectionPostedRun($fixture);
        $raceRunId = app(PayrollCalculationService::class)->calculate($fixture['company']->id, [
            'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'branch_doc_num' => $fixture['branch']->doc_num,
        ])['run_id'];
        app(PayrollLifecycleService::class)->submitForReview($raceRunId, $fixture['company']->id);
        app(PayrollLifecycleService::class)->approve($raceRunId, $fixture['company']->id);
        $fixture['period']->update(['is_closed' => true]);
        foreach ([$browserRunId, $raceRunId] as $id) {
            DB::table('hr_payroll_periods')->where('id', DB::table('hr_payroll_runs')->where('id', $id)->value('payroll_period_id'))->update(['status' => 'closed']);
        }
        $fixture['period'] = $later;
        session(payrollFinancialContext($fixture));
        foreach ([$preparer, $approver, $secondApprover] as $actor) {
            app(DefaultLoginContextService::class)->update($actor, ['company_doc_num' => $fixture['company']->doc_num,
                'branch_doc_num' => $fixture['branch']->doc_num, 'financial_period_doc_num' => $later->doc_num]);
        }
        $service = app(PayrollCorrectionService::class);
        $raceProposal = $service->propose($raceRunId, '2026-10-03', 'SYNTHETIC concurrent payroll correction', $service->preview($raceRunId)['fingerprint'], 'later_period');

        return ['synthetic' => true, 'database' => 'mgypack_acceptance_closure_20261003', 'company_id' => $fixture['company']->id,
            'branch_id' => $fixture['branch']->id, 'later_period_id' => $later->id, 'cashbox_id' => $fixture['cashbox']->id,
            'cashbox_doc_num' => $fixture['cashbox']->doc_num, 'browser_run_id' => $browserRunId, 'race_run_id' => $raceRunId,
            'race_proposal_id' => $raceProposal->id, 'preparer_id' => $preparer->id, 'preparer' => $preparer->username,
            'approver_id' => $approver->id, 'approver' => $approver->username, 'second_approver_id' => $secondApprover->id,
            'context' => payrollFinancialContext($fixture), 'advance_id' => $fixture['advance_id']];
    });
    file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    chmod($path, 0600);
});

test('actual concurrent payroll payment and correction cannot leave a paid reversed run and duplicate approvals post once', function (): void {
    if (getenv('MGYPACK_PAYROLL_CORRECTION_RUNTIME_RACE') !== '1') {
        $this->markTestSkipped('Explicit isolated simultaneous verification only.');
    }
    $manifest = json_decode(file_get_contents('/tmp/mgypack-payroll-correction-runtime-20261003.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['synthetic'])->toBeTrue()->and(Company::findOrFail($manifest['company_id'])->name)->toContain('SYNTHETIC');
    session($manifest['context']);
    if ($manifest['race_verified'] ?? false) {
        $proposalIds = collect($manifest['race_replay_results'])->pluck('id')->unique();
        expect($proposalIds)->toHaveCount(1)
            ->and(collect($manifest['race_replay_results'])->pluck('root_transaction_attempts')->all())->toBe([1, 1])
            ->and(collect($manifest['race_results'])->pluck('result')->sort()->values()->all())->toBe(['applied', 'blocked']);
        $proposal = DB::table('hr_payroll_corrections')->where('id', $proposalIds->sole())
            ->where('company_id', $manifest['company_id'])->where('payroll_run_id', $manifest['race_run_id'])->first();
        expect($proposal)->not->toBeNull()->and($proposal->status)->toBe('approved')
            ->and(DB::table('hr_payroll_runs')->where('id', $manifest['race_run_id'])->value('status'))->toBe('reversed')
            ->and(JournalEntry::query()->where('company_id', $manifest['company_id'])->where('source_type', 'hr_payroll_run_reversal')
                ->where('source_id', $proposal->id)->where('status', JournalEntry::StatusPosted)->count())->toBe(1)
            ->and(DB::table('hr_payroll_payments')->where('payroll_run_id', $manifest['race_run_id'])->where('status', '!=', 'cancelled')->count())->toBe(0);
        $reconciliation = app(PayrollReconciliationService::class)->forRun($manifest['race_run_id'], $manifest['company_id'], '2026-10-03');
        expect($reconciliation['status'])->toBe('matched')->and($reconciliation['summary']['gl_ending'])->toBe('0.0000')
            ->and($reconciliation['summary']['cash_bank_difference'])->toBe('0.0000');

        return;
    }
    $permissions = User::findOrFail($manifest['preparer_id'])->getAllPermissions()->pluck('name')->all();
    $racePreparer = payrollFinancialActor($permissions);
    $raceApprover = payrollFinancialActor($permissions);
    $raceSecondApprover = payrollFinancialActor($permissions);
    $manifest['race_actor_ids'] = [$racePreparer->id, $raceApprover->id, $raceSecondApprover->id];
    $runId = $manifest['race_run_id'];
    $proposalId = $manifest['race_proposal_id'];
    if (getenv('MGYPACK_PAYROLL_CORRECTION_RUNTIME_RECOVER') === '1' && ! ($manifest['race_verified'] ?? false)) {
        Carbon::setTestNow('2026-10-03 12:00:00');
        $response = $this->actingAs(User::findOrFail($racePreparer->id))->withSession($manifest['context'])
            ->get(route('admin.hr.payroll-runs.corrections.index', $runId));
        expect($response->status())->toBe(200, (string) $response->headers->get('Location'));
        $service = app(PayrollCorrectionService::class);
        if (DB::table('hr_payroll_runs')->where('id', $runId)->value('status') === 'posted') {
            foreach (DB::table('hr_payroll_payments')->where('payroll_run_id', $runId)->where('status', 'draft')->get() as $draft) {
                $voucherNumber = DB::table('cash_vouchers')->where('id', $draft->cash_voucher_id)->value('doc_num');
                $this->deleteJson(route('admin.finance.cash-payment-vouchers.destroy', $voucherNumber))->assertOk();
            }
            $this->actingAs(User::findOrFail($raceApprover->id))->withSession($manifest['context']);
            if (DB::table('hr_payroll_corrections')->where('id', $proposalId)->value('status') === 'prepared') {
                $this->postJson(route('admin.hr.payroll-runs.corrections.reject', [$runId, $proposalId]))->assertOk();
            }
            $this->actingAs(User::findOrFail($racePreparer->id))->withSession($manifest['context']);
            $this->get(route('admin.hr.payroll-runs.corrections.index', $runId))->assertOk();
            $proposalId = $service->propose($runId, '2026-10-03', 'SYNTHETIC preserve failed-race history and recover through actual correction', $service->preview($runId)['fingerprint'], 'later_period')->id;
            $this->actingAs(User::findOrFail($raceApprover->id))->withSession($manifest['context']);
            $service->approve($runId, $proposalId);
        }
        expect(DB::table('hr_payroll_runs')->where('id', $runId)->value('status'))->toBe('reversed');
        $this->actingAs(User::findOrFail($racePreparer->id))->withSession($manifest['context']);
        $replacement = app(PayrollCalculationService::class)->calculate($manifest['company_id'], [
            'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'branch_doc_num' => $manifest['context']['current_branch_doc_num'],
        ])['run_id'];
        app(PayrollLifecycleService::class)->submitForReview($replacement, $manifest['company_id']);
        app(PayrollLifecycleService::class)->approve($replacement, $manifest['company_id']);
        $proposalId = $service->propose($replacement, '2026-10-03', 'SYNTHETIC actual retry after canonical replacement', $service->preview($replacement)['fingerprint'], 'original_period')->id;
        $manifest['race_previous_runs'][] = $runId;
        $manifest['race_retry_original_run_id'] ??= $runId;
        $manifest['race_run_id'] = $runId = $replacement;
        $manifest['race_proposal_id'] = $proposalId;
        $manifest['race_correction_mode'] = 'original_period';
        $manifest['race_baseline_reversals'] = JournalEntry::query()->where('company_id', $manifest['company_id'])->where('source_type', 'hr_payroll_run_reversal')->count();
        $manifest['race_baseline_advance_balance'] = bcadd((string) DB::table('hr_salary_advances')->where('id', $manifest['advance_id'])->value('balance'), '0', 4);
        file_put_contents('/tmp/mgypack-payroll-correction-runtime-20261003.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
    expect(DB::table('hr_payroll_runs')->where('id', $runId)->value('status'))->toBe('posted');
    $result = closurePostgresRace([
        ['operation' => 'payroll-correction-approve', 'run' => $runId, 'correction' => $proposalId, 'user' => $raceApprover->id],
        ['operation' => 'payroll-payment-create', 'run' => $runId, 'company' => $manifest['company_id'], 'user' => $racePreparer->id,
            'data' => ['cashbox_doc_num' => $manifest['cashbox_doc_num'], 'amount' => '100.0000', 'payment_date' => '2026-10-03', 'idempotency_key' => (string) Str::uuid()]],
    ]);
    expect(collect($result)->pluck('result')->sort()->values()->all())->toBe(['applied', 'blocked'])
        ->and(collect($result)->pluck('root_transaction_attempts')->all())->toBe([1, 1]);
    $payment = DB::table('hr_payroll_payments')->where('payroll_run_id', $runId)->first();
    if ($payment !== null) {
        $this->actingAs(User::findOrFail($racePreparer->id))->withSession($manifest['context']);
        $voucherNumber = DB::table('cash_vouchers')->where('id', $payment->cash_voucher_id)->value('doc_num');
        $this->deleteJson(route('admin.finance.cash-payment-vouchers.destroy', $voucherNumber))->assertOk();
        $this->actingAs(User::findOrFail($raceApprover->id))->withSession($manifest['context']);
        $this->postJson(route('admin.hr.payroll-runs.corrections.reject', [$runId, $proposalId]))->assertOk();
        $this->actingAs(User::findOrFail($racePreparer->id))->withSession($manifest['context']);
        $this->get(route('admin.hr.payroll-runs.corrections.index', $runId))->assertOk();
        $service = app(PayrollCorrectionService::class);
        $proposalId = $service->propose($runId, '2026-10-03', 'SYNTHETIC correction after draft void', $service->preview($runId)['fingerprint'], $manifest['race_correction_mode'] ?? 'later_period')->id;
    }
    $replay = closurePostgresRace([
        ['operation' => 'payroll-correction-approve', 'run' => $runId, 'correction' => $proposalId, 'user' => $raceApprover->id],
        ['operation' => 'payroll-correction-approve', 'run' => $runId, 'correction' => $proposalId, 'user' => $raceSecondApprover->id],
    ]);
    expect(collect($replay)->pluck('result')->all())->toBe(['applied', 'applied'])
        ->and(collect($replay)->pluck('id')->unique()->count())->toBe(1)->and(collect($replay)->pluck('root_transaction_attempts')->all())->toBe([1, 1])
        ->and(DB::table('hr_payroll_runs')->where('id', $runId)->value('status'))->toBe('reversed')
        ->and(JournalEntry::query()->where('company_id', $manifest['company_id'])->where('source_type', 'hr_payroll_run_reversal')->count())->toBe(($manifest['race_baseline_reversals'] ?? 0) + 1)
        ->and(DB::table('hr_payroll_payments')->where('payroll_run_id', $runId)->where('status', '!=', 'cancelled')->count())->toBe(0);
    $this->actingAs(User::findOrFail($raceApprover->id))->withSession($manifest['context'])->get(route('admin.hr.payroll-runs.corrections.index', $runId))->assertOk();
    $reconciliation = app(PayrollReconciliationService::class)->forRun($runId, $manifest['company_id'], '2026-10-03');
    expect($reconciliation['status'])->toBe('matched', json_encode($reconciliation['summary'], JSON_THROW_ON_ERROR))->and($reconciliation['summary']['gl_ending'])->toBe('0.0000')
        ->and($reconciliation['summary']['cash_bank_difference'])->toBe('0.0000')
        ->and(bcadd((string) DB::table('hr_salary_advances')->where('id', $manifest['advance_id'])->value('balance'), '0', 4))->toBe($manifest['race_baseline_advance_balance'] ?? '200.0000');
    $manifest['race_results'] = $result;
    $manifest['race_replay_results'] = $replay;
    $manifest['race_verified'] = true;
    $manifest['race_proposal_id'] = $proposalId;
    file_put_contents('/tmp/mgypack-payroll-correction-runtime-20261003.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
});

test('persisted browser replacement preserves closed-period payroll and exact partial Finance payments', function (): void {
    if (getenv('MGYPACK_PAYROLL_BROWSER_VERIFY') !== '1') {
        $this->markTestSkipped('Explicit verification of the labelled browser acceptance company only.');
    }
    $path = '/tmp/mgypack-payroll-correction-runtime-20261003.json';
    $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['synthetic'])->toBeTrue()->and(Company::findOrFail($manifest['company_id'])->name)->toContain('SYNTHETIC');
    $companyPeriodIds = DB::table('hr_payroll_periods')->where('company_id', $manifest['company_id'])->pluck('id');
    $original = DB::table('hr_payroll_runs')->whereIn('payroll_period_id', $companyPeriodIds)->where('id', $manifest['browser_run_id'])->sole();
    $replacement = DB::table('hr_payroll_runs')->whereIn('payroll_period_id', $companyPeriodIds)->where('correction_of_run_id', $original->id)->sole();
    $proposal = DB::table('hr_payroll_corrections')->where('payroll_run_id', $original->id)->where('status', 'approved')->sole();
    $originalJournal = JournalEntry::findOrFail(DB::table('hr_payroll_postings')->where('payroll_run_id', $original->id)->value('journal_entry_id'));
    $replacementJournal = JournalEntry::findOrFail(DB::table('hr_payroll_postings')->where('payroll_run_id', $replacement->id)->value('journal_entry_id'));
    expect($original->status)->toBe('reversed')->and($replacement->status)->toBe('posted')
        ->and((int) $proposal->prepared_by)->not->toBe((int) $proposal->approved_by)
        ->and((int) $replacementJournal->financial_period_id)->toBe($manifest['later_period_id'])
        ->and($replacement->posting_date)->toBe('2026-10-03')
        ->and(FinancialPeriod::findOrFail($originalJournal->financial_period_id)->is_closed)->toBeTrue();
    $originalSlip = DB::table('hr_payslips')->where('payroll_run_id', $original->id)->sole();
    $replacementSlip = DB::table('hr_payslips')->where('payroll_run_id', $replacement->id)->sole();
    expect(bcadd((string) $originalSlip->gross_amount, '0', 4))->toBe('10200.0000')
        ->and(bcadd((string) $originalSlip->deduction_amount, '0', 4))->toBe('300.0000')
        ->and(bcadd((string) $originalSlip->net_amount, '0', 4))->toBe('9900.0000')
        ->and(bcadd((string) $replacementSlip->net_amount, '0', 4))->toBe('10200.0000');
    $payments = DB::table('hr_payroll_payments')->where('payroll_run_id', $replacement->id)->orderBy('id')->get();
    expect($payments)->toHaveCount(2)->and($payments->pluck('amount')->map(fn ($amount): string => bcadd((string) $amount, '0', 4))->all())->toBe(['100.0000', '100.0000']);
    expect($payments->pluck('status')->all())->toBe(['approved', 'approved']);
    foreach ($payments as $payment) {
        $voucher = DB::table('cash_vouchers')->where('company_id', $manifest['company_id'])->where('id', $payment->cash_voucher_id)->sole();
        expect($voucher->voucher_type)->toBe('payment')->and($voucher->status)->toBe($payment->status)
            ->and(bcadd((string) $voucher->amount_base, '0', 4))->toBe('100.0000');
    }
    session($manifest['context']);
    $reconciliation = app(PayrollReconciliationService::class)->forRun($replacement->id, $manifest['company_id'], '2026-10-03');
    $paid = $payments->where('status', 'approved')->count() * 100;
    expect($reconciliation['status'])->toBe('matched')
        ->and($reconciliation['summary']['gl_ending'])->toBe('10000.0000')
        ->and($reconciliation['summary']['gl_ending'])->toBe(bcsub('10200', (string) $paid, 4))
        ->and($reconciliation['summary']['cash_bank_difference'])->toBe('0.0000');
    if (! isset($manifest['browser_finance_actor_id'])) {
        $actor = payrollFinancialActor(['cash_payment_vouchers.view', 'cash_payment_vouchers.approve', 'hr.payroll_preparation.view', 'hr.payroll_reports.view']);
        app(DefaultLoginContextService::class)->update($actor, [
            'company_doc_num' => $manifest['context']['current_company_doc_num'],
            'branch_doc_num' => $manifest['context']['current_branch_doc_num'],
            'financial_period_doc_num' => $manifest['context']['current_financial_period_doc_num'],
        ]);
        $manifest['browser_finance_actor_id'] = $actor->id;
        $manifest['browser_finance_actor'] = $actor->username;
    }
    $manifest['browser_replacement_run_id'] = $replacement->id;
    $manifest['browser_extra_unposted_run_id'] = 150;
    $manifest['browser_payment_ids'] = $payments->pluck('id')->all();
    $manifest['browser_voucher_numbers'] = DB::table('cash_vouchers')->where('company_id', $manifest['company_id'])
        ->whereIn('id', $payments->pluck('cash_voucher_id'))->orderBy('id')->pluck('doc_num')->all();
    $manifest['browser_payments_approved'] = $payments->every(fn ($payment): bool => $payment->status === 'approved');
    $manifest['browser_replacement_verified'] = true;
    file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    if (getenv('MGYPACK_PAYROLL_FINANCE_ROUTE_VERIFY') === '1') {
        $this->withoutExceptionHandling()->actingAs(User::findOrFail($manifest['browser_finance_actor_id']))->withSession($manifest['context']);
        foreach ($manifest['browser_voucher_numbers'] as $number) {
            $this->get(route('admin.finance.cash-payment-vouchers.show', $number))->assertOk()->assertSee($number);
        }
        $this->post(route('logout'))->assertRedirect();
    }
});
