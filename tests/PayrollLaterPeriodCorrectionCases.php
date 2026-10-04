<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\NumericFormatService;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashboxCurrency;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Services\CashVoucherService;
use Modules\HR\Models\HrEmployeeServiceRequest;
use Modules\HR\Models\HrLeaveType;
use Modules\HR\Models\HrPayrollAttendancePolicy;
use Modules\HR\Services\PayrollCorrectionService;
use Modules\HR\Services\PayrollCostAllocationService;
use Modules\HR\Services\PayrollPaymentService;
use Modules\HR\Services\PayrollReconciliationService;
use Modules\HR\Services\PayrollReportService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;

require_once __DIR__.'/PayrollFinancialSupport.php';

test('legacy unposted fractional allocations are reviewably repaired at stored percentages while posted history is locked', function (): void {
    $f = payrollLaterPeriodFixture();
    $actor = payrollFinancialActor(['hr.payroll_preparation.view', 'hr.payroll_preparation.calculate', 'hr.payroll_approval.review', 'hr.payroll_approval.approve']);
    $this->actingAs($actor)->withSession(payrollFinancialContext($f));
    HrPayrollAttendancePolicy::query()->create(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'branch_scope_key' => 'branch:'.$f['branch']->id, 'effective_from' => '2026-01-01', 'monthly_partial_method' => HrPayrollAttendancePolicy::MonthlyCalendarDays,
        'salary_day_divisor' => 30, 'standard_day_minutes' => 480, 'status' => 'active']);
    $calculation = ['period_start' => '2026-09-01', 'period_end' => '2026-09-10', 'branch_doc_num' => $f['branch']->doc_num];
    $run = $this->postJson(route('admin.hr.payroll-runs.calculate'), $calculation)->assertOk()->json('data.run_id');
    $itemId = (int) DB::table('hr_payslip_items')->where('source_type', 'salary_assignment')->whereIn('payslip_id', DB::table('hr_payslips')->where('payroll_run_id', $run)->select('id'))->value('id');
    expect(bcadd((string) DB::table('hr_payslip_items')->where('id', $itemId)->value('amount'), '0', 4))->toBe('3333.3333');
    $centers = [CostCenter::query()->where('company_id', $f['company']->id)->where('cost_center_code', '11')->sole()];
    foreach ([12, 13] as $number) {
        $numbering = max($number, (int) CostCenter::withTrashed()->max('doc_number') + 1);
        $centers[] = CostCenter::query()->create(['company_id' => $f['company']->id, 'doc_number' => $numbering,
            'doc_num' => 'SYNTHETIC-PAY-ALLOCATION-'.$numbering, 'cost_center_code' => (string) $number, 'name' => 'SYNTHETIC center '.$number,
            'is_group' => false, 'status' => 'active']);
    }
    $service = app(PayrollCostAllocationService::class);
    $service->syncAllocations($itemId, array_map(fn ($center, $percentage): array => ['cost_center_doc_num' => $center->doc_num, 'percentage' => $percentage, 'allocation_type' => 'direct'],
        $centers, ['33.33339', '33.33339', '33.33349']));
    $rows = DB::table('hr_payroll_cost_allocations')->where('payslip_item_id', $itemId)->orderBy('id')->get();
    expect($rows->map(fn ($row) => bcadd((string) $row->percentage, '0', 4))->all())->toBe(['33.3333', '33.3333', '33.3334'])
        ->and($rows->map(fn ($row) => bcadd((string) $row->amount, '0', 4))->all())->toBe(['1111.1099', '1111.1099', '1111.1135']);
    foreach ($rows as $row) {
        DB::table('hr_payroll_cost_allocations')->where('id', $row->id)->update(['amount' => bcadd((string) $row->amount, '0', 2)]);
    }
    expect($service->previewRun($run)['errors'])->toContain(__('hr_payroll.messages.cost_allocation_amount_mismatch', ['item' => 'Basic Salary']));
    $this->get(route('admin.hr.payroll-runs.cost-preview', $run))->assertOk()->assertSee(route('admin.hr.payroll-runs.cost-allocations.recalculate', $run));
    $this->post(route('admin.hr.payroll-runs.cost-allocations.recalculate', $run))->assertRedirect(route('admin.hr.payroll-runs.cost-preview', $run));
    expect($service->previewRun($run)['errors'])->toBe([])
        ->and(DB::table('hr_payroll_cost_allocations')->where('payslip_item_id', $itemId)->orderBy('id')->get()->map(fn ($row) => bcadd((string) $row->amount, '0', 4))->all())
        ->toBe(['1111.1099', '1111.1099', '1111.1135']);
    $this->postJson(route('admin.hr.payroll-runs.review', $run))->assertOk();
    $this->postJson(route('admin.hr.payroll-runs.cost-allocations.recalculate', $run))->assertUnprocessable();
    $approvedAllocations = DB::table('hr_payroll_cost_allocations')->where('payslip_item_id', $itemId)->orderBy('id')->get()->all();
    DB::table('hr_payroll_runs')->where('id', $run)->update(['status' => 'approved']);
    expect(fn () => $service->syncAllocations($itemId, [['cost_center_doc_num' => $centers[0]->doc_num, 'percentage' => '100', 'allocation_type' => 'direct']]))
        ->toThrow(DomainException::class, __('hr_payroll.messages.cost_allocation_locked'));
    expect(DB::table('hr_payroll_cost_allocations')->where('payslip_item_id', $itemId)->orderBy('id')->get()->all())->toEqual($approvedAllocations);
    $this->postJson(route('admin.hr.payroll-runs.cost-allocations.recalculate', $run))->assertUnprocessable();
    DB::table('hr_payroll_runs')->where('id', $run)->update(['status' => 'under_review']);
    $this->postJson(route('admin.hr.payroll-runs.return-for-recalculation', $run))->assertOk();
    $this->postJson(route('admin.hr.payroll-runs.cost-allocations.recalculate', $run))->assertRedirect(route('admin.hr.payroll-runs.cost-preview', $run));
    $this->postJson(route('admin.hr.payroll-runs.review', $run))->assertOk();
    $journalId = $this->postJson(route('admin.hr.payroll-runs.approve', $run))->assertOk()->json('data.journal_entry_id');
    $frozen = DB::table('hr_payroll_cost_allocations')->whereIn('payslip_item_id', DB::table('hr_payslip_items')->whereIn('payslip_id', DB::table('hr_payslips')->where('payroll_run_id', $run)->select('id'))->select('id'))->orderBy('id')->get()->all();
    $posting = DB::table('hr_payroll_postings')->where('payroll_run_id', $run)->first();
    $lines = JournalEntry::findOrFail($journalId)->lines()->orderBy('id')->get()->map->getAttributes()->all();
    expect(fn () => $service->syncAllocations($itemId, [['cost_center_doc_num' => $centers[0]->doc_num, 'percentage' => '100', 'allocation_type' => 'direct']]))
        ->toThrow(DomainException::class, __('hr_payroll.messages.cost_allocation_locked'));
    $this->postJson(route('admin.hr.payroll-runs.cost-allocations.recalculate', $run))->assertUnprocessable();
    expect(DB::table('hr_payroll_cost_allocations')->whereIn('payslip_item_id', DB::table('hr_payslip_items')->whereIn('payslip_id', DB::table('hr_payslips')->where('payroll_run_id', $run)->select('id'))->select('id'))->orderBy('id')->get()->all())->toEqual($frozen)
        ->and(DB::table('hr_payroll_postings')->where('payroll_run_id', $run)->first())->toEqual($posting)
        ->and(JournalEntry::findOrFail($journalId)->lines()->orderBy('id')->get()->map->getAttributes()->all())->toBe($lines);
    $actor->revokePermissionTo('hr.payroll_preparation.calculate');
    $this->post(route('admin.hr.payroll-runs.cost-allocations.recalculate', $run))->assertForbidden();
});

test('later company payroll correction preserves both transfer slips restores the one advance and repays each branch', function (): void {
    $f = payrollLaterPeriodFixture();
    $permissions = ['hr.payroll_preparation.view', 'hr.payroll_preparation.calculate', 'hr.payroll_approval.review', 'hr.payroll_approval.approve',
        'hr.payroll_approval.correct', 'hr.payroll_approval.correct_approve', 'hr.payroll_approval.correct_later_period',
        'hr.payroll_payment.create', 'cash_payment_vouchers.create', 'cash_payment_vouchers.approve', 'hr.payroll_reports.view'];
    $maker = payrollFinancialActor($permissions);
    $reviewer = payrollFinancialActor($permissions);
    $this->actingAs($maker)->withSession(payrollFinancialContext($f));
    $destination = Branch::query()->create(['company_id' => $f['company']->id, 'doc_number' => 7002,
        'doc_num' => 'SYNTHETIC-PAY-TRANSFER-7002', 'name' => 'SYNTHETIC correction destination branch', 'type' => 'factory', 'status' => 'active']);
    $f['employee']->update(['branch_id' => $destination->id]);
    foreach ([[$f['branch']->id, '2026-01-01', '2026-09-10', 'initial_verified'], [$destination->id, '2026-09-11', null, 'transfer']] as [$branch, $from, $to, $source]) {
        DB::table('hr_employee_organization_assignments')->insert(['company_id' => $f['company']->id, 'employee_id' => $f['employee']->id,
            'branch_id' => $branch, 'department_id' => $f['employee']->department_id, 'effective_from' => $from, 'effective_to' => $to,
            'source_type' => $source, 'created_at' => now(), 'updated_at' => now()]);
        HrPayrollAttendancePolicy::query()->create(['company_id' => $f['company']->id, 'branch_id' => $branch,
            'branch_scope_key' => 'branch:'.$branch, 'effective_from' => '2026-01-01', 'monthly_partial_method' => HrPayrollAttendancePolicy::MonthlyCalendarDays,
            'salary_day_divisor' => 30, 'standard_day_minutes' => 480, 'status' => 'active']);
    }
    $cash = payrollFinancialAccount($f['company'], 'cash_in_transit', 'SYNTHETIC-PAY-TRANSFER-CASH', 1112);
    $cashbox = Cashbox::query()->create(['doc_number' => 7002, 'doc_num' => 'SYNTHETIC-PAY-TRANSFER-CASH-7002',
        'company_id' => $f['company']->id, 'branch_id' => $destination->id, 'account_id' => $cash->id, 'name' => 'SYNTHETIC destination cashbox', 'status' => 'active']);
    CashboxCurrency::query()->create(['cashbox_id' => $cashbox->id, 'currency_id' => $f['currency']->id, 'status' => 'active']);
    $calculation = ['period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'adjustments' => [['employee_doc_num' => $f['employee']->doc_num,
        'advance_applications' => [['salary_advance_id' => $f['advance_id'], 'payroll_item_code' => 'SALARY-ADVANCE', 'amount' => '300.0000']]]]];
    $run = $this->postJson(route('admin.hr.payroll-runs.calculate'), $calculation)->assertOk()->json('data.run_id');
    $this->postJson(route('admin.hr.payroll-runs.review', $run))->assertOk();
    $journalId = $this->postJson(route('admin.hr.payroll-runs.approve', $run))->assertOk()->json('data.journal_entry_id');
    $originalJournal = JournalEntry::findOrFail($journalId);
    $originalLines = $originalJournal->lines()->orderBy('id')->get()->map->getAttributes()->all();
    $slips = DB::table('hr_payslips')->where('payroll_run_id', $run)->orderBy('branch_id')->get();
    expect($slips)->toHaveCount(2)
        ->and($slips->map(fn ($slip) => bcadd((string) $slip->gross_amount, '0', 4))->all())->toBe(['3533.3333', '6666.6667'])
        ->and($slips->map(fn ($slip) => bcadd((string) $slip->deduction_amount, '0', 4))->all())->toBe(['103.9216', '196.0784'])
        ->and($slips->map(fn ($slip) => bcadd((string) $slip->net_amount, '0', 4))->all())->toBe(['3429.4117', '6470.5883'])
        ->and(DB::table('hr_payroll_advance_applications')->where('payroll_run_id', $run)->count())->toBe(2);
    $f['period']->update(['is_closed' => true]);
    DB::table('hr_payroll_periods')->where('id', DB::table('hr_payroll_runs')->where('id', $run)->value('payroll_period_id'))->update(['status' => 'closed']);
    $this->withSession(payrollLaterPeriodContext($f));
    $before = app(PayrollCorrectionService::class)->preview($run);
    $frozen = json_encode([$before['snapshot']['slips'], $before['snapshot']['items']], JSON_THROW_ON_ERROR);
    $applications = collect($before['snapshot']['applications'])->map(fn ($row) => collect($row)->except(['reversed_at', 'reversed_by', 'updated_at'])->all())->all();
    $proposal = $this->postJson(route('admin.hr.payroll-runs.corrections.store', $run), ['fingerprint' => $before['fingerprint'],
        'correction_mode' => 'later_period', 'reversal_date' => '2026-10-01', 'reason' => 'SYNTHETIC transferred employee split-slip recovery'])->assertOk()->json('data.correction_id');
    $this->actingAs($reviewer)->withSession(payrollLaterPeriodContext($f));
    $inverseId = $this->postJson(route('admin.hr.payroll-runs.corrections.approve', [$run, $proposal]))->assertOk()->json('data.journal_entry_id');
    $this->postJson(route('admin.hr.payroll-runs.corrections.approve', [$run, $proposal]))->assertOk()->assertJsonPath('data.journal_entry_id', $inverseId);
    $inverse = JournalEntry::findOrFail($inverseId);
    expect($inverse->financial_period_id)->toBe($f['later_period']->id)->and($originalJournal->fresh()->entry_date->toDateString())->toBe('2026-09-30')
        ->and($originalJournal->lines()->orderBy('id')->get()->map->getAttributes()->all())->toBe($originalLines)
        ->and(bcadd((string) DB::table('hr_salary_advances')->where('id', $f['advance_id'])->value('balance'), '0', 4))->toBe('500.0000')
        ->and(DB::table('hr_payroll_advance_applications')->where('payroll_run_id', $run)->whereNotNull('reversed_at')->count())->toBe(2);
    $after = app(PayrollCorrectionService::class)->preview($run);
    expect(json_encode([$after['snapshot']['slips'], $after['snapshot']['items']], JSON_THROW_ON_ERROR))->toBe($frozen)
        ->and(collect($after['snapshot']['applications'])->map(fn ($row) => collect($row)->except(['reversed_at', 'reversed_by', 'updated_at'])->all())->all())->toBe($applications);
    foreach ($slips as $slip) {
        $net = bcadd((string) $slip->net_amount, '0', 4);
        $original = $originalJournal->lines->first(fn ($line) => (int) $line->branch_id === (int) $slip->branch_id && (int) $line->account_id === (int) $f['payable_account']->id);
        $reversed = $inverse->lines->first(fn ($line) => (int) $line->branch_id === (int) $slip->branch_id && (int) $line->account_id === (int) $f['payable_account']->id);
        expect($original->credit_amount)->toBe($net)->and($reversed->debit_amount)->toBe($net);
        $history = app(PayrollReconciliationService::class)->forScope($f['company']->id, $slip->branch_id, '2026-08-31', '2026-09-30');
        $current = app(PayrollReconciliationService::class)->forScope($f['company']->id, $slip->branch_id, '2026-08-31', '2026-10-01');
        expect($history['payable_ending'])->toBe($net)->and($history['gl_ending'])->toBe($net)
            ->and($current['payable_ending'])->toBe('0.0000')->and($current['gl_ending'])->toBe('0.0000');
    }
    $replacement = $this->postJson(route('admin.hr.payroll-runs.calculate'), $calculation)->assertOk()->json('data.run_id');
    $this->postJson(route('admin.hr.payroll-runs.review', $replacement))->assertOk();
    $this->postJson(route('admin.hr.payroll-runs.approve', $replacement))->assertOk();
    foreach (DB::table('hr_payslips')->where('payroll_run_id', $replacement)->orderBy('branch_id')->get() as $slip) {
        $branch = (int) $slip->branch_id === (int) $f['branch']->id ? $f['branch'] : $destination;
        $branchCashbox = (int) $branch->id === (int) $f['branch']->id ? $f['cashbox'] : $cashbox;
        $this->withSession(payrollFinancialContext([...$f, 'branch' => $branch, 'period' => $f['later_period']]));
        $voucherNumber = $this->postJson(route('admin.hr.payroll-runs.payments.store', $replacement), ['payslip_id' => $slip->id,
            'cashbox_doc_num' => $branchCashbox->doc_num, 'amount' => $slip->net_amount, 'payment_date' => '2026-10-01', 'idempotency_key' => (string) Str::uuid()])
            ->assertOk()->json('data.voucher_doc_num');
        app(CashVoucherService::class)->approve(CashVoucher::TypePayment, CashVoucher::query()->where('company_id', $f['company']->id)->where('doc_num', $voucherNumber)->sole(), $f['company']->id);
        $balance = app(PayrollReconciliationService::class)->forScope($f['company']->id, $slip->branch_id, '2026-08-31', '2026-10-01');
        expect($balance['payable_ending'])->toBe('0.0000')->and($balance['gl_ending'])->toBe('0.0000');
    }
    $reconciliation = app(PayrollReconciliationService::class)->forRun($replacement, $f['company']->id, '2026-10-01');
    expect($reconciliation['status'])->toBe('matched')->and($reconciliation['summary']['gl_difference'])->toBe('0.0000')
        ->and($reconciliation['summary']['cash_bank_difference'])->toBe('0.0000')->and($f['period']->fresh()->is_closed)->toBeTrue()
        ->and(bcadd((string) DB::table('hr_salary_advances')->where('id', $f['advance_id'])->value('balance'), '0', 4))->toBe('200.0000');
});

/** @return array<string, mixed> */
function payrollLaterPeriodFixture(): array
{
    $fixture = payrollFinancialFixture(true);
    $fixture['period']->update(['to_date' => '2026-09-30']);
    $fixture['later_period'] = FinancialPeriod::query()->create([
        'company_id' => $fixture['company']->id, 'doc_number' => 7002, 'doc_num' => 'PAY-FP-07002',
        'name' => 'SYNTHETIC later posting period', 'from_date' => '2026-10-01', 'to_date' => '2026-12-31',
        'is_closed' => false, 'allows_opening_entries' => false,
    ]);
    Carbon::setTestNow('2026-10-01 12:00:00');

    return $fixture;
}

/** @return array<string, mixed> */
function payrollLaterPeriodContext(array $fixture): array
{
    $fixture['period'] = $fixture['later_period'];

    return payrollFinancialContext($fixture);
}

test('later payroll correction keeps the closed original period and frozen slips then posts and pays its linked replacement', function (): void {
    $fixture = payrollLaterPeriodFixture();
    $permissions = ['hr.payroll_preparation.view', 'hr.payroll_preparation.calculate', 'hr.payroll_approval.review', 'hr.payroll_approval.approve',
        'hr.payroll_approval.correct', 'hr.payroll_approval.correct_approve', 'hr.payroll_approval.correct_later_period',
        'hr.payroll_payment.create', 'cash_payment_vouchers.create', 'cash_payment_vouchers.approve', 'hr.payroll_reports.view'];
    $preparer = payrollFinancialActor($permissions);
    $approver = payrollFinancialActor($permissions);
    $this->actingAs($preparer)->withSession(payrollFinancialContext($fixture));
    $runId = payrollCorrectionPostedRun($fixture);
    $fixture['period']->update(['is_closed' => true]);
    $payrollPeriodId = DB::table('hr_payroll_runs')->where('id', $runId)->value('payroll_period_id');
    DB::table('hr_payroll_periods')->where('id', $payrollPeriodId)->update(['status' => 'closed']);
    $this->withSession(payrollLaterPeriodContext($fixture));
    $this->withoutExceptionHandling()->get(route('admin.hr.payroll-runs.corrections.index', $runId))->assertOk()->assertSee('later_period');
    $this->withExceptionHandling();
    $before = app(PayrollCorrectionService::class)->preview($runId);
    $history = json_encode([$before['snapshot']['slips'], $before['snapshot']['items'], $before['snapshot']['inputs'], $before['snapshot']['attendance_inputs']], JSON_THROW_ON_ERROR);
    $payload = ['fingerprint' => $before['fingerprint'], 'reversal_date' => '2026-10-01', 'reason' => 'SYNTHETIC closed-period correction'];
    $this->postJson(route('admin.hr.payroll-runs.corrections.store', $runId), $payload)->assertUnprocessable();
    $payload['correction_mode'] = PayrollCorrectionService::ModeLaterPeriod;
    $proposalId = $this->postJson(route('admin.hr.payroll-runs.corrections.store', $runId), $payload)->assertOk()->json('data.correction_id');
    $this->postJson(route('admin.hr.payroll-runs.corrections.store', $runId), $payload)->assertOk()->assertJsonPath('data.correction_id', $proposalId);
    $this->postJson(route('admin.hr.payroll-runs.corrections.approve', [$runId, $proposalId]))->assertUnprocessable();
    $this->actingAs($approver)->withSession(payrollLaterPeriodContext($fixture));
    $reversalId = $this->postJson(route('admin.hr.payroll-runs.corrections.approve', [$runId, $proposalId]))->assertOk()->json('data.journal_entry_id');
    $this->postJson(route('admin.hr.payroll-runs.corrections.approve', [$runId, $proposalId]))->assertOk()->assertJsonPath('data.journal_entry_id', $reversalId);
    $reversal = JournalEntry::query()->findOrFail($reversalId);
    $this->get(route('admin.hr.payroll-runs.corrections.index', $runId))->assertOk()->assertSee($reversal->doc_num);
    $after = app(PayrollCorrectionService::class)->preview($runId);
    expect($reversal->financial_period_id)->toBe($fixture['later_period']->id)
        ->and($reversal->entry_date->toDateString())->toBe('2026-10-01')
        ->and(json_encode([$after['snapshot']['slips'], $after['snapshot']['items'], $after['snapshot']['inputs'], $after['snapshot']['attendance_inputs']], JSON_THROW_ON_ERROR))->toBe($history)
        ->and($fixture['period']->fresh()->is_closed)->toBeTrue()
        ->and(DB::table('hr_payroll_periods')->where('id', $payrollPeriodId)->value('status'))->toBe('closed')
        ->and(bcadd((string) DB::table('hr_salary_advances')->where('id', $fixture['advance_id'])->value('balance'), '0', 4))->toBe('500.0000')
        ->and(JournalEntry::query()->where('company_id', $fixture['company']->id)->where('source_type', 'hr_payroll_run_reversal')->count())->toBe(1);
    $historical = app(PayrollReconciliationService::class)->forRun($runId, $fixture['company']->id, '2026-09-30');
    $reversed = app(PayrollReconciliationService::class)->forRun($runId, $fixture['company']->id, '2026-10-01');
    expect($historical['status'])->toBe('matched')->and($historical['summary']['ending_payable'])->toBe('9900.0000')
        ->and($reversed['status'])->toBe('matched')->and($reversed['summary']['ending_payable'])->toBe('0.0000');
    $replacement = $this->postJson(route('admin.hr.payroll-runs.calculate'), [
        'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'branch_doc_num' => $fixture['branch']->doc_num,
        'adjustments' => [['employee_doc_num' => $fixture['employee']->doc_num,
            'deductions' => [['payroll_item_code' => 'PAYROLL-TAX', 'amount' => '250.0000', 'reference' => 'SYNTHETIC correction']],
            'advance_applications' => [['salary_advance_id' => $fixture['advance_id'], 'payroll_item_code' => 'SALARY-ADVANCE', 'amount' => '300.0000']],
        ]],
    ])->assertOk()->json('data.run_id');
    $newRun = DB::table('hr_payroll_runs')->where('id', $replacement)->first();
    expect($replacement)->not->toBe($runId)->and((int) $newRun->correction_of_run_id)->toBe($runId)
        ->and((int) $newRun->posting_financial_period_id)->toBe($fixture['later_period']->id)->and($newRun->posting_date)->toBe('2026-10-01');
    $this->postJson(route('admin.hr.payroll-runs.review', $replacement))->assertOk();
    $newJournalId = $this->postJson(route('admin.hr.payroll-runs.approve', $replacement))->assertOk()->json('data.journal_entry_id');
    expect(JournalEntry::query()->findOrFail($newJournalId)->financial_period_id)->toBe($fixture['later_period']->id);
    $slipId = (int) DB::table('hr_payslips')->where('payroll_run_id', $replacement)->value('id');
    $voucherNumber = $this->postJson(route('admin.hr.payroll-runs.payments.store', $replacement), [
        'payslip_id' => $slipId, 'cashbox_doc_num' => $fixture['cashbox']->doc_num, 'amount' => '9650.0000',
        'payment_date' => '2026-10-01', 'idempotency_key' => (string) Str::uuid(),
    ])->assertOk()->json('data.voucher_doc_num');
    app(CashVoucherService::class)->approve(CashVoucher::TypePayment, CashVoucher::query()->where('company_id', $fixture['company']->id)->where('doc_num', $voucherNumber)->firstOrFail(), $fixture['company']->id);
    $settled = app(PayrollReconciliationService::class)->forRun($replacement, $fixture['company']->id, '2026-10-01');
    expect($settled['status'])->toBe('matched')->and($settled['summary']['remaining'])->toBe('0.0000')
        ->and($settled['summary']['gl_difference'])->toBe('0.0000')->and($settled['summary']['cash_bank_difference'])->toBe('0.0000')
        ->and($fixture['period']->fresh()->is_closed)->toBeTrue();
    $report = app(PayrollReportService::class)->payroll($fixture['company']->id, $approver, []);
    expect($report['rows']->total())->toBe(1)->and($report['totals'][0]['net'])->toBe('9650.0000');
});

test('later payroll correction requires its extra permission and exact later open target on both prepare and approval', function (): void {
    $fixture = payrollLaterPeriodFixture();
    $preparer = payrollFinancialActor(['hr.payroll_approval.correct']);
    $approver = payrollFinancialActor(['hr.payroll_approval.correct_approve']);
    $this->actingAs($preparer)->withSession(payrollFinancialContext($fixture));
    $runId = payrollCorrectionPostedRun($fixture);
    $fixture['period']->update(['is_closed' => true]);
    $this->withSession(payrollLaterPeriodContext($fixture));
    $this->get(route('admin.hr.payroll-runs.corrections.index', $runId))->assertOk();
    $plan = app(PayrollCorrectionService::class)->preview($runId);
    $payload = ['fingerprint' => $plan['fingerprint'], 'reversal_date' => '2026-10-01', 'reason' => 'SYNTHETIC permission check', 'correction_mode' => 'later_period'];
    $this->postJson(route('admin.hr.payroll-runs.corrections.store', $runId), $payload)->assertForbidden();
    Permission::findOrCreate('hr.payroll_approval.correct_later_period', 'web');
    $preparer->givePermissionTo('hr.payroll_approval.correct_later_period');
    $fixture['later_period']->update(['is_closed' => true]);
    $this->postJson(route('admin.hr.payroll-runs.corrections.store', $runId), $payload)->assertUnprocessable();
    $fixture['later_period']->update(['is_closed' => false]);
    $proposalId = $this->postJson(route('admin.hr.payroll-runs.corrections.store', $runId), $payload)->assertOk()->json('data.correction_id');
    $this->actingAs($approver)->withSession(payrollLaterPeriodContext($fixture));
    $this->postJson(route('admin.hr.payroll-runs.corrections.approve', [$runId, $proposalId]))->assertForbidden();
    $approver->givePermissionTo('hr.payroll_approval.correct_later_period');
    $fixture['later_period']->update(['is_closed' => true]);
    $this->postJson(route('admin.hr.payroll-runs.corrections.approve', [$runId, $proposalId]))->assertUnprocessable();
    expect(DB::table('hr_payroll_runs')->where('id', $runId)->value('status'))->toBe('posted')
        ->and(JournalEntry::query()->where('company_id', $fixture['company']->id)->where('source_type', 'hr_payroll_run_reversal')->exists())->toBeFalse()
        ->and(bcadd((string) DB::table('hr_salary_advances')->where('id', $fixture['advance_id'])->value('balance'), '0', 4))->toBe('200.0000');
    $fixture['later_period']->update(['is_closed' => false]);
    $this->withSession(payrollFinancialContext($fixture));
    $this->postJson(route('admin.hr.payroll-runs.corrections.approve', [$runId, $proposalId]))->assertUnprocessable();
    $this->withSession(payrollLaterPeriodContext($fixture));
    $this->postJson(route('admin.hr.payroll-runs.corrections.approve', [$runId, $proposalId]))->assertOk();
});

test('original-period payroll correction cannot use a later financial period without the separate permission', function (): void {
    $fixture = payrollLaterPeriodFixture();
    $preparer = payrollFinancialActor(['hr.payroll_approval.correct']);
    $this->actingAs($preparer)->withSession(payrollFinancialContext($fixture));
    $runId = payrollCorrectionPostedRun($fixture);
    $this->withSession(payrollLaterPeriodContext($fixture));
    $this->get(route('admin.hr.payroll-runs.corrections.index', $runId))->assertOk();
    $plan = app(PayrollCorrectionService::class)->preview($runId);
    $this->postJson(route('admin.hr.payroll-runs.corrections.store', $runId), [
        'fingerprint' => $plan['fingerprint'], 'reversal_date' => '2026-10-01', 'reason' => 'SYNTHETIC mode bypass', 'correction_mode' => 'original_period',
    ])->assertUnprocessable();
    expect(DB::table('hr_payroll_corrections')->where('payroll_run_id', $runId)->count())->toBe(0)
        ->and(DB::table('hr_payroll_runs')->where('id', $runId)->value('status'))->toBe('posted');
});

test('payroll correction rejects a cancelled flag backed by an unrelated journal and accepts an actual Finance reversal', function (): void {
    $fixture = payrollLaterPeriodFixture();
    $preparer = payrollFinancialActor(['hr.payroll_approval.correct', 'hr.payroll_approval.correct_later_period', 'cash_payment_vouchers.cancel', 'cash_payment_vouchers.approve']);
    $approver = payrollFinancialActor(['hr.payroll_approval.correct_approve', 'hr.payroll_approval.correct_later_period']);
    $this->actingAs($preparer)->withSession(payrollFinancialContext($fixture));
    $runId = payrollCorrectionPostedRun($fixture);
    $payment = app(PayrollPaymentService::class)->createCashPayment($runId, $fixture['company']->id, [
        'cashbox_doc_num' => $fixture['cashbox']->doc_num, 'amount' => '100.0000', 'payment_date' => '2026-09-30', 'idempotency_key' => (string) Str::uuid(),
    ]);
    $voucher = app(CashVoucherService::class)->approve(CashVoucher::TypePayment, $payment['voucher'], $fixture['company']->id);
    $paymentId = $payment['payment']->id;
    $paymentJournalId = DB::table('hr_payroll_payments')->where('id', $paymentId)->value('journal_entry_id');
    $unrelatedJournalId = DB::table('hr_payroll_postings')->where('payroll_run_id', $runId)->value('journal_entry_id');
    $fixture['period']->update(['is_closed' => true]);
    $this->withSession(payrollLaterPeriodContext($fixture));
    $this->get(route('admin.hr.payroll-runs.corrections.index', $runId))->assertOk();
    DB::table('hr_payroll_payments')->where('id', $paymentId)->update(['status' => 'cancelled', 'reversal_journal_entry_id' => $unrelatedJournalId]);
    DB::table('cash_vouchers')->where('id', $voucher->id)->update(['status' => 'cancelled']);
    $plan = app(PayrollCorrectionService::class)->preview($runId);
    $payload = ['fingerprint' => $plan['fingerprint'], 'reversal_date' => '2026-10-01', 'reason' => 'SYNTHETIC payment lineage', 'correction_mode' => 'later_period'];
    $this->postJson(route('admin.hr.payroll-runs.corrections.store', $runId), $payload)->assertUnprocessable();
    expect(JournalEntry::query()->findOrFail($paymentJournalId)->reversed_entry_id)->toBeNull()
        ->and(DB::table('hr_payroll_runs')->where('id', $runId)->value('status'))->toBe('posted');
    DB::table('hr_payroll_payments')->where('id', $paymentId)->update(['status' => 'approved', 'reversal_journal_entry_id' => null]);
    DB::table('cash_vouchers')->where('id', $voucher->id)->update(['status' => 'approved']);
    $this->postJson(route('admin.finance.cash-payment-vouchers.cancel', $voucher->doc_num), ['cancel_reason' => 'SYNTHETIC actual cash recovery'])->assertOk();
    $canonicalPayment = DB::table('hr_payroll_payments')->where('id', $paymentId)->first();
    $canonicalReversal = JournalEntry::query()->findOrFail($canonicalPayment->reversal_journal_entry_id);
    expect($canonicalReversal->financial_period_id)->toBe($fixture['later_period']->id)
        ->and($canonicalReversal->source_type)->toBe('hr_payroll_payment_reversal')->and((int) $canonicalReversal->source_id)->toBe($paymentId)
        ->and(JournalEntry::query()->findOrFail($paymentJournalId)->reversed_entry_id)->toBe($canonicalReversal->id);
    $plan = app(PayrollCorrectionService::class)->preview($runId);
    $payload['fingerprint'] = $plan['fingerprint'];
    $proposalId = $this->postJson(route('admin.hr.payroll-runs.corrections.store', $runId), $payload)->assertOk()->json('data.correction_id');
    $this->actingAs($approver)->withSession(payrollLaterPeriodContext($fixture));
    $this->postJson(route('admin.hr.payroll-runs.corrections.approve', [$runId, $proposalId]))->assertOk();
    $reconciliation = app(PayrollReconciliationService::class)->forRun($runId, $fixture['company']->id, '2026-10-01');
    expect($reconciliation['status'])->toBe('matched')->and($reconciliation['summary']['gl_ending'])->toBe('0.0000')
        ->and($reconciliation['summary']['cash_bank_difference'])->toBe('0.0000')->and($fixture['period']->fresh()->is_closed)->toBeTrue();
});

test('payroll advance correction cannot restore another company employee balance from a malformed historical application', function (): void {
    $fixture = payrollLaterPeriodFixture();
    $permissions = ['hr.payroll_approval.correct', 'hr.payroll_approval.correct_approve', 'hr.payroll_approval.correct_later_period'];
    $preparer = payrollFinancialActor($permissions);
    $approver = payrollFinancialActor($permissions);
    $this->actingAs($preparer)->withSession(payrollFinancialContext($fixture));
    $runId = payrollCorrectionPostedRun($fixture);
    $otherCompany = Company::query()->create(['doc_number' => 9019, 'doc_num' => 'SYNTHETIC-PAY-FOREIGN-9019', 'name' => 'SYNTHETIC foreign company', 'legal_name' => 'SYNTHETIC foreign company', 'status' => 'active', 'is_main' => false, 'country' => 'Egypt']);
    $otherEmployee = $fixture['employee']->replicate(['public_uuid', 'doc_number', 'doc_num']);
    $otherEmployee->forceFill(['company_id' => $otherCompany->id, 'branch_id' => null, 'employee_code' => 'SYNTHETIC-FOREIGN-9019', 'doc_number' => 9019, 'doc_num' => 'SYNTHETIC-PAY-FOREIGN-EMP-9019'])->save();
    $foreignAdvanceId = DB::table('hr_salary_advances')->insertGetId(['employee_id' => $otherEmployee->id, 'principal' => '500.00', 'balance' => '200.00', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('hr_payroll_advance_applications')->where('payroll_run_id', $runId)->update(['salary_advance_id' => $foreignAdvanceId]);
    $fixture['period']->update(['is_closed' => true]);
    $this->withSession(payrollLaterPeriodContext($fixture));
    $this->get(route('admin.hr.payroll-runs.corrections.index', $runId))->assertOk();
    $plan = app(PayrollCorrectionService::class)->preview($runId);
    $proposalId = $this->postJson(route('admin.hr.payroll-runs.corrections.store', $runId), [
        'fingerprint' => $plan['fingerprint'], 'reversal_date' => '2026-10-01', 'reason' => 'SYNTHETIC malformed advance', 'correction_mode' => 'later_period',
    ])->assertOk()->json('data.correction_id');
    $this->actingAs($approver)->withSession(payrollLaterPeriodContext($fixture));
    $this->postJson(route('admin.hr.payroll-runs.corrections.approve', [$runId, $proposalId]))->assertUnprocessable();
    expect(bcadd((string) DB::table('hr_salary_advances')->where('id', $foreignAdvanceId)->value('balance'), '0', 4))->toBe('200.0000')
        ->and(DB::table('hr_payroll_runs')->where('id', $runId)->value('status'))->toBe('posted')
        ->and(DB::table('hr_payroll_corrections')->where('id', $proposalId)->value('status'))->toBe('prepared')
        ->and(JournalEntry::query()->where('company_id', $fixture['company']->id)->where('source_type', 'hr_payroll_run_reversal')->exists())->toBeFalse();
});

test('closed accrual period replacement requires approval of its exact latest source and supports another correction within its open posting period', function (): void {
    $fixture = payrollLaterPeriodFixture();
    $permissions = ['hr.payroll_preparation.calculate', 'hr.payroll_approval.review', 'hr.payroll_approval.approve',
        'hr.payroll_approval.correct', 'hr.payroll_approval.correct_approve', 'hr.payroll_approval.correct_later_period'];
    $preparer = payrollFinancialActor($permissions);
    $approver = payrollFinancialActor($permissions);
    $this->actingAs($preparer)->withSession(payrollFinancialContext($fixture));
    $originalId = payrollCorrectionPostedRun($fixture);
    $fixture['period']->update(['is_closed' => true]);
    $periodId = DB::table('hr_payroll_runs')->where('id', $originalId)->value('payroll_period_id');
    DB::table('hr_payroll_periods')->where('id', $periodId)->update(['status' => 'closed']);
    $this->withSession(payrollLaterPeriodContext($fixture))->get(route('admin.hr.payroll-runs.corrections.index', $originalId))->assertOk();
    $plan = app(PayrollCorrectionService::class)->preview($originalId);
    $proposalId = $this->postJson(route('admin.hr.payroll-runs.corrections.store', $originalId), ['fingerprint' => $plan['fingerprint'],
        'reversal_date' => '2026-10-01', 'reason' => 'SYNTHETIC first correction', 'correction_mode' => 'later_period'])->assertOk()->json('data.correction_id');
    $this->actingAs($approver)->withSession(payrollLaterPeriodContext($fixture));
    $this->postJson(route('admin.hr.payroll-runs.corrections.approve', [$originalId, $proposalId]))->assertOk();
    $calculation = ['period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'branch_doc_num' => $fixture['branch']->doc_num];
    $secondId = $this->postJson(route('admin.hr.payroll-runs.calculate'), $calculation)->assertOk()->json('data.run_id');
    $this->postJson(route('admin.hr.payroll-runs.review', $secondId))->assertOk();
    $secondJournalId = $this->postJson(route('admin.hr.payroll-runs.approve', $secondId))->assertOk()->json('data.journal_entry_id');
    DB::table('hr_payroll_runs')->where('id', $secondId)->update(['status' => 'reversed']);
    $this->postJson(route('admin.hr.payroll-runs.calculate'), $calculation)->assertUnprocessable();
    expect(DB::table('hr_payroll_runs')->where('payroll_period_id', $periodId)->count())->toBe(2)
        ->and(JournalEntry::query()->findOrFail($secondJournalId)->reversed_entry_id)->toBeNull();
    DB::table('hr_payroll_runs')->where('id', $secondId)->update(['status' => 'posted']);
    $preparer->revokePermissionTo('hr.payroll_approval.correct_later_period');
    $approver->revokePermissionTo('hr.payroll_approval.correct_later_period');
    $this->actingAs($preparer)->withSession(payrollLaterPeriodContext($fixture))->get(route('admin.hr.payroll-runs.corrections.index', $secondId))->assertOk();
    $plan = app(PayrollCorrectionService::class)->preview($secondId);
    $proposalId = $this->postJson(route('admin.hr.payroll-runs.corrections.store', $secondId), ['fingerprint' => $plan['fingerprint'],
        'reversal_date' => '2026-10-01', 'reason' => 'SYNTHETIC same open posting-period correction', 'correction_mode' => 'original_period'])->assertOk()->json('data.correction_id');
    $this->actingAs($approver)->withSession(payrollLaterPeriodContext($fixture));
    $this->postJson(route('admin.hr.payroll-runs.corrections.approve', [$secondId, $proposalId]))->assertOk();
    $thirdId = $this->postJson(route('admin.hr.payroll-runs.calculate'), $calculation)->assertOk()->json('data.run_id');
    expect((int) DB::table('hr_payroll_runs')->where('id', $thirdId)->value('correction_of_run_id'))->toBe($secondId)
        ->and((int) DB::table('hr_payroll_runs')->where('id', $thirdId)->value('posting_financial_period_id'))->toBe($fixture['later_period']->id)
        ->and(DB::table('hr_payroll_periods')->where('id', $periodId)->value('status'))->toBe('closed');
    $fixture['later_period']->update(['is_closed' => true]);
    $frozenDraft = DB::table('hr_payslips')->where('payroll_run_id', $thirdId)->get()->toJson();
    $this->postJson(route('admin.hr.payroll-runs.calculate'), $calculation)->assertUnprocessable();
    expect(DB::table('hr_payslips')->where('payroll_run_id', $thirdId)->get()->toJson())->toBe($frozenDraft);
});

test('retrospective leave uses the approved payroll correction then recalculates its real policy deduction and all report outputs', function (): void {
    $fixture = payrollLaterPeriodFixture();
    $permissions = ['hr.payroll_preparation.calculate', 'hr.payroll_approval.review', 'hr.payroll_approval.approve',
        'hr.payroll_approval.correct', 'hr.payroll_approval.correct_approve', 'hr.payroll_approval.correct_later_period',
        'hr.hr_requests.manage', 'hr.payroll_reports.view', 'hr.payroll_reports.export', 'hr.payroll_payment.create',
        'cash_payment_vouchers.create', 'cash_payment_vouchers.approve'];
    $preparer = payrollFinancialActor($permissions);
    $approver = payrollFinancialActor($permissions);
    $fixture['employee']->update(['user_id' => $preparer->id]);
    HrPayrollAttendancePolicy::query()->create([
        'company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id, 'branch_scope_key' => 'branch:'.$fixture['branch']->id,
        'effective_from' => '2026-09-01', 'deduct_absence' => false, 'deduct_late' => false, 'deduct_early_leave' => false,
        'deduct_unpaid_leave' => true, 'salary_day_divisor' => 30, 'standard_day_minutes' => 480,
        'deduction_payroll_item_code' => 'PAYROLL-TAX', 'deduction_rules' => ['unpaid_leave' => ['method' => 'fixed', 'value' => '100.0000']], 'status' => 'active',
    ]);
    $type = HrLeaveType::query()->create(['code' => 'SYNTHETIC-LATE-UNPAID', 'name' => 'SYNTHETIC unpaid leave', 'status' => 'active',
        'metadata' => ['requires_balance' => false, 'payment_status' => 'unpaid']]);
    $this->actingAs($preparer)->withSession(payrollFinancialContext($fixture));
    $originalId = payrollCorrectionPostedRun($fixture);
    $frozenInput = DB::table('hr_payroll_attendance_inputs')->where('payroll_run_id', $originalId)->value('payload');
    $this->post(route('employee.hr.requests.store'), ['request_type' => 'leave', 'subject' => 'SYNTHETIC retrospective leave', 'details' => 'SYNTHETIC leave evidence',
        'requested_from' => '2026-09-10', 'requested_to' => '2026-09-10', 'payload' => ['leave_type' => $type->code]])->assertRedirect();
    $leave = HrEmployeeServiceRequest::query()->where('employee_id', $fixture['employee']->id)->where('request_type', 'leave')->sole();
    $this->actingAs($approver)->withSession(payrollFinancialContext($fixture));
    $this->patch(route('admin.hr.hr-requests.review', $leave), ['decision' => 'approved', 'resolution_notes' => 'SYNTHETIC late approval'])
        ->assertRedirect()->assertSessionHasErrors('request');
    expect($leave->fresh()->status)->toBe('submitted');
    $fixture['period']->update(['is_closed' => true]);
    $periodId = DB::table('hr_payroll_runs')->where('id', $originalId)->value('payroll_period_id');
    DB::table('hr_payroll_periods')->where('id', $periodId)->update(['status' => 'closed']);
    $this->actingAs($preparer)->withSession(payrollLaterPeriodContext($fixture))->get(route('admin.hr.payroll-runs.corrections.index', $originalId))->assertOk();
    $plan = app(PayrollCorrectionService::class)->preview($originalId);
    $proposalId = $this->postJson(route('admin.hr.payroll-runs.corrections.store', $originalId), ['fingerprint' => $plan['fingerprint'],
        'reversal_date' => '2026-10-01', 'reason' => 'SYNTHETIC retrospective leave correction', 'correction_mode' => 'later_period'])->assertOk()->json('data.correction_id');
    $this->actingAs($approver)->withSession(payrollLaterPeriodContext($fixture));
    $this->postJson(route('admin.hr.payroll-runs.corrections.approve', [$originalId, $proposalId]))->assertOk();
    $this->patch(route('admin.hr.hr-requests.review', $leave), ['decision' => 'approved', 'resolution_notes' => 'SYNTHETIC approved after correction'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $canonicalLeaveId = (int) $leave->fresh()->payload['canonical_leave_request_id'];
    expect(DB::table('hr_leave_request_days')->where('leave_request_id', $canonicalLeaveId)->value('leave_date'))->toBe('2026-09-10')
        ->and(DB::table('hr_payroll_attendance_inputs')->where('payroll_run_id', $originalId)->value('payload'))->toBe($frozenInput);
    $replacementId = $this->postJson(route('admin.hr.payroll-runs.calculate'), [
        'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'branch_doc_num' => $fixture['branch']->doc_num,
        'adjustments' => [['employee_doc_num' => $fixture['employee']->doc_num,
            'advance_applications' => [['salary_advance_id' => $fixture['advance_id'], 'payroll_item_code' => 'SALARY-ADVANCE', 'amount' => '300.0000']]]],
    ])->assertOk()->assertJsonPath('data.payable', '9800.0000')->json('data.run_id');
    $input = json_decode(DB::table('hr_payroll_attendance_inputs')->where('payroll_run_id', $replacementId)->value('payload'), true, flags: JSON_THROW_ON_ERROR);
    expect($input['canonical_leave_request_ids'])->toContain($canonicalLeaveId);
    $this->postJson(route('admin.hr.payroll-runs.review', $replacementId))->assertOk();
    $this->postJson(route('admin.hr.payroll-runs.approve', $replacementId))->assertOk();
    $this->get(route('admin.hr.reports.payroll', ['run_id' => $replacementId]))->assertOk()
        ->assertSee(app(NumericFormatService::class)->format('9800.0000'));
    $csv = $this->get(route('admin.hr.reports.payroll.export', ['format' => 'csv', 'run_id' => $replacementId]))->assertOk();
    expect(file_get_contents($csv->baseResponse->getFile()->getPathname()))->toContain('9800.0000', '400.0000');
    $xlsx = $this->get(route('admin.hr.reports.payroll.export', ['format' => 'xlsx', 'run_id' => $replacementId]))->assertOk();
    $rows = IOFactory::load($xlsx->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray();
    expect(bccomp((string) $rows[1][8], '9800.0000', 4))->toBe(0)->and(bccomp((string) $rows[1][7], '400.0000', 4))->toBe(0);
    foreach (['en', 'ar'] as $locale) {
        $approver->update(['locale' => $locale]);
        $pdf = $this->get(route('admin.hr.reports.payroll.export', ['format' => 'pdf', 'run_id' => $replacementId]))->assertOk()->assertHeader('content-type', 'application/pdf');
        if (getenv('MGYPACK_PAYROLL_LATER_VISUAL') === '1') {
            file_put_contents('/tmp/mgypack-payroll-later-leave-'.$locale.'-20261003.pdf', $pdf->getContent());
        }
        $extract = new Process(['pdftotext', '-layout', '-', '-']);
        $extract->setInput($pdf->getContent())->mustRun();
        expect($extract->getOutput())->toContain(app(NumericFormatService::class)->format('9800.0000'), $fixture['company']->legal_name);
    }
    $slipId = DB::table('hr_payslips')->where('payroll_run_id', $replacementId)->value('id');
    $voucherNumber = $this->postJson(route('admin.hr.payroll-runs.payments.store', $replacementId), [
        'payslip_id' => $slipId, 'cashbox_doc_num' => $fixture['cashbox']->doc_num, 'amount' => '9800.0000',
        'payment_date' => '2026-10-01', 'idempotency_key' => (string) Str::uuid(),
    ])->assertOk()->json('data.voucher_doc_num');
    app(CashVoucherService::class)->approve(CashVoucher::TypePayment, CashVoucher::query()->where('company_id', $fixture['company']->id)->where('doc_num', $voucherNumber)->firstOrFail(), $fixture['company']->id);
    $settled = app(PayrollReconciliationService::class)->forRun($replacementId, $fixture['company']->id, '2026-10-01');
    expect($settled['status'])->toBe('matched')->and($settled['summary']['remaining'])->toBe('0.0000')->and($settled['summary']['gl_difference'])->toBe('0.0000');
});

test('payroll correction validates advance application amount item lineage and original balance arithmetic', function (string $corruption): void {
    $fixture = payrollLaterPeriodFixture();
    $permissions = ['hr.payroll_approval.correct', 'hr.payroll_approval.correct_approve', 'hr.payroll_approval.correct_later_period'];
    $preparer = payrollFinancialActor($permissions);
    $approver = payrollFinancialActor($permissions);
    $this->actingAs($preparer)->withSession(payrollFinancialContext($fixture));
    $runId = payrollCorrectionPostedRun($fixture);
    $application = DB::table('hr_payroll_advance_applications')->where('payroll_run_id', $runId)->sole();
    if ($corruption === 'amount') {
        DB::table('hr_payroll_advance_applications')->where('id', $application->id)->update(['amount' => '200.0000']);
    } elseif ($corruption === 'balance') {
        DB::table('hr_payroll_advance_applications')->where('id', $application->id)->update(['balance_after' => '199.0000']);
    } elseif ($corruption === 'item') {
        $otherItem = DB::table('hr_payslip_items')->where('payslip_id', $application->payslip_id)->where('source_type', '!=', 'salary_advance')->first();
        DB::table('hr_payroll_advance_applications')->where('id', $application->id)->update(['payslip_item_id' => $otherItem->id]);
    } else {
        DB::table('hr_payslip_items')->where('id', $application->payslip_item_id)->update(['source_id' => $application->salary_advance_id + 90000]);
    }
    $fixture['period']->update(['is_closed' => true]);
    $this->withSession(payrollLaterPeriodContext($fixture))->get(route('admin.hr.payroll-runs.corrections.index', $runId))->assertOk();
    $service = app(PayrollCorrectionService::class);
    $proposalId = $this->postJson(route('admin.hr.payroll-runs.corrections.store', $runId), [
        'fingerprint' => $service->preview($runId)['fingerprint'], 'reversal_date' => '2026-10-01',
        'reason' => 'SYNTHETIC malformed application '.$corruption, 'correction_mode' => 'later_period',
    ])->assertOk()->json('data.correction_id');
    $this->actingAs($approver)->withSession(payrollLaterPeriodContext($fixture));
    $this->postJson(route('admin.hr.payroll-runs.corrections.approve', [$runId, $proposalId]))->assertUnprocessable();
    expect(bcadd((string) DB::table('hr_salary_advances')->where('id', $fixture['advance_id'])->value('balance'), '0', 4))->toBe('200.0000')
        ->and(DB::table('hr_payroll_runs')->where('id', $runId)->value('status'))->toBe('posted')
        ->and(DB::table('hr_payroll_corrections')->where('id', $proposalId)->value('status'))->toBe('prepared')
        ->and(JournalEntry::query()->where('company_id', $fixture['company']->id)->where('source_type', 'hr_payroll_run_reversal')->exists())->toBeFalse();
})->with(['amount', 'balance', 'item', 'source']);
