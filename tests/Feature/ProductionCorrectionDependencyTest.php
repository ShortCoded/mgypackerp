<?php

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Models\OverheadAllocationRule;
use Modules\Accounting\Models\OverheadAllocationRun;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Accounting\Services\OverheadAllocationService;
use Modules\Auth\Models\Role;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Services\CashVoucherService;
use Modules\HR\Services\PayrollCorrectionService;
use Modules\HR\Services\PayrollPaymentService;
use Modules\Production\Services\ProductionCorrectionDependencyService;
use Modules\Production\Services\ProductionRunCorrectionService;
use Modules\Sales\Services\CustomerInvoiceCorrectionService;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\SalesFulfillmentService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../ManufacturingInventorySupport.php';
require_once __DIR__.'/../PayrollFinancialSupport.php';

/** @param array<string, mixed> $fixture */
function productionDependencyActor(array $fixture, User $user): void
{
    $session = manufacturingIntegritySession($fixture);
    test()->actingAs($user)->withSession($session);
    request()->setLaravelSession(app('session.store'));
    request()->session()->put($session);
    request()->setUserResolver(fn (): User => $user);
}

/** @param array<string, mixed> $fixture */
function productionDependencyPayroll(array $fixture, ?int $branchId, string $status, array $evidence): array
{
    $periodId = DB::table('hr_payroll_periods')->where('company_id', $fixture['company']->id)->value('id');
    if ($periodId === null) {
        $periodId = DB::table('hr_payroll_periods')->insertGetId([
            'company_id' => $fixture['company']->id,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    $runId = DB::table('hr_payroll_runs')->insertGetId([
        'payroll_period_id' => $periodId,
        'branch_id' => $branchId,
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $slipId = DB::table('hr_payslips')->insertGetId([
        'payroll_run_id' => $runId,
        'employee_id' => $fixture['employee']->id,
        'company_id' => $fixture['company']->id,
        'branch_id' => $fixture['branch']->id,
        'status' => $status,
        'gross_amount' => '100.0000',
        'deduction_amount' => '0.0000',
        'net_amount' => '100.0000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $itemId = DB::table('hr_payslip_items')->insertGetId([
        'payslip_id' => $slipId,
        'amount' => '100.00',
        'direction' => 'earning',
        'source_type' => 'employee_master',
        'source_snapshot' => json_encode(['accrual' => ['evidence' => $evidence]], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['run_id' => $runId, 'slip_id' => $slipId, 'item_id' => $itemId];
}

test('production correction maps an invoiced sales delivery to its canonical invoice correction and clears it after restoration', function (): void {
    $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
    $fixture = productionCorrectionCompletedFixture(suffix: '-SYNTHETIC-PROD-DEPENDENCY', forSales: true, isolatedCompany: true);
    $classification = AccountClassification::query()->where('code', 'accounts_receivable')->firstOrFail();
    $parent = Account::query()->where('company_id', $fixture['company']->id)->where('account_classification_id', $classification->id)
        ->where('is_group', true)->orderByDesc('level')->firstOrFail();
    $accountNumber = max(99501, (int) Account::withTrashed()->max('doc_number') + 1);
    $account = Account::query()->create([
        'company_id' => $fixture['company']->id,
        'doc_number' => $accountNumber,
        'doc_num' => 'SYNTHETIC-PROD-DEPENDENCY-'.$accountNumber,
        'account_code' => '1121'.$accountNumber,
        'name' => 'Synthetic production dependency customer',
        'parent_id' => $parent->id,
        'level' => $parent->level + 1,
        'account_classification_id' => $classification->id,
        'account_type' => Account::TypeAsset,
        'statement_type' => Account::StatementFinancialPosition,
        'normal_balance' => Account::BalanceDebit,
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
    ]);
    $fixture['salesOrder']->customer->update(['account_id' => $account->id]);
    foreach (['customer_invoices.correct_prepare', 'customer_invoices.correct_approve', 'customer_invoices.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
        $fixture['approver']->givePermissionTo($permission);
    }
    productionDependencyActor($fixture, $fixture['user']);
    $delivery = app(SalesFulfillmentService::class)->deliver($fixture['salesOrder']->fresh(), [[
        'sales_order_line_id' => $fixture['salesLine']->id,
        'quantity' => '10',
    ]]);
    $invoices = app(CustomerInvoiceService::class);
    $invoice = $invoices->post($invoices->createFromOrder(
        $fixture['salesOrder']->fresh(),
        [['sales_order_line_id' => $fixture['salesLine']->id, 'delivery_line_id' => $delivery->lines->sole()->id, 'quantity' => '10']],
        [['due_date' => '2026-09-30', 'amount' => '100']],
        $delivery,
    ));

    $production = app(ProductionRunCorrectionService::class);
    $blocked = $production->preview($fixture['run']);
    expect($blocked['correction_steps'])->toHaveCount(1)
        ->and($blocked['correction_steps'][0]['action'])->toBe('correct_customer_invoice')
        ->and($blocked['correction_steps'][0]['doc_num'])->toBe($delivery->doc_num)
        ->and($blocked['correction_steps'][0]['correction_url'])->toBe(route('admin.sales.sales-invoices.corrections.index', $invoice));
    $this->get(route('admin.production.runs.corrections.index', $fixture['run']))
        ->assertOk()->assertSee(__('production_run_correction.dependencies_title'))->assertSee($delivery->doc_num);

    $invoiceCorrections = app(CustomerInvoiceCorrectionService::class);
    $invoicePreview = $invoiceCorrections->preview($invoice);
    $proposal = $invoiceCorrections->prepare($invoice, [
        'source_fingerprint' => $invoicePreview['fingerprint'],
        'posting_date' => '2026-09-30',
        'reason' => 'Synthetic production dependency recovery',
        'recovery_reference' => 'Synthetic returned finished goods evidence',
    ]);
    productionDependencyActor($fixture, $fixture['approver']);
    $invoiceCorrections->approve($invoice, $proposal->id, 'Synthetic independent review');

    productionDependencyActor($fixture, $fixture['user']);
    expect($production->preview($fixture['run'])['correction_steps'])->toBe([]);
});

test('production correction links only payroll evidence for the run and fingerprints linked payroll state', function (): void {
    $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
    $fixture = productionCorrectionCompletedFixture(withPiece: true, suffix: '-SYNTHETIC-PAYROLL-DEPENDENCY', isolatedCompany: true);
    foreach (['hr.payroll_approval.review', 'hr.payroll_preparation.calculate'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
        $fixture['approver']->givePermissionTo($permission);
    }
    productionDependencyActor($fixture, $fixture['user']);
    $approval = DB::table('production_piece_approvals')->where('production_run_id', $fixture['run']->id)->sole();
    $linked = productionDependencyPayroll($fixture, null, 'under_review', [
        'sources' => [['piece_approval_id' => $approval->id, 'production_run_id' => $fixture['run']->id]],
        'production_run_ids' => [$fixture['run']->id],
    ]);
    $unrelated = productionDependencyPayroll($fixture, $fixture['branch']->id, 'under_review', [
        'sources' => [['piece_approval_id' => 999999999, 'production_run_id' => 999999999]],
        'production_run_ids' => [999999999],
    ]);

    $service = app(ProductionRunCorrectionService::class);
    $reviewed = $service->preview($fixture['run']);
    expect(collect($reviewed['correction_steps'])->pluck('action')->all())->toBe(['return_payroll_for_recalculation'])
        ->and($reviewed['correction_steps'][0]['doc_num'])->toContain((string) $linked['run_id'])
        ->and(collect($reviewed['correction_steps'])->pluck('doc_num')->implode(' '))->not->toContain((string) $unrelated['run_id']);

    DB::table('hr_payroll_runs')->where('id', $linked['run_id'])->update(['status' => 'reversed', 'updated_at' => now()]);
    expect(collect($service->preview($fixture['run'])['correction_steps'])->pluck('action')->all())->toBe(['repair_payroll_correction_evidence']);
    DB::table('hr_payroll_runs')->where('id', $linked['run_id'])->update(['status' => 'calculated', 'updated_at' => now()]);
    DB::table('hr_payslips')->where('id', $linked['slip_id'])->update(['status' => 'calculated', 'updated_at' => now()]);
    $calculated = $service->preview($fixture['run']);
    expect($calculated['correction_steps'])->toBe([])->and($calculated['fingerprint'])->not->toBe($reviewed['fingerprint']);
    DB::table('hr_payslip_items')->where('id', $linked['item_id'])->update(['amount' => '101.00', 'updated_at' => now()]);
    $changed = $service->preview($fixture['run']);
    expect($changed['fingerprint'])->not->toBe($calculated['fingerprint']);

    $proposal = $service->propose($fixture['run'], [
        'good_base_quantity' => '9',
        'rejected_base_quantity' => '1',
        'rework_base_quantity' => '0',
        'scrap_base_quantity' => '0',
    ], 'Synthetic exact payroll evidence correction', $changed['fingerprint'], '2026-09-30');
    $roleNumber = (int) Role::withTrashed()->max('doc_number') + 1;
    $restricted = Role::query()->create([
        'name' => 'SYNTHETIC-PRODUCTION-PAYROLL-RESTRICTED-'.$roleNumber,
        'guard_name' => 'web',
        'doc_number' => $roleNumber,
        'doc_num' => 'SYNTHETIC-PRODUCTION-PAYROLL-RESTRICTED-'.$roleNumber,
        'company_access_restricted' => false,
        'branch_access_restricted' => true,
        'financial_period_access_restricted' => false,
    ]);
    $restricted->branchAccessBranches()->sync([$fixture['branch']->id]);
    $fixture['approver']->assignRole($restricted);
    productionDependencyActor($fixture, $fixture['approver']);
    expect(fn () => $service->approve($fixture['run'], $proposal->id))->toThrow(DomainException::class)
        ->and(DB::table('hr_payroll_runs')->where('id', $linked['run_id'])->value('status'))->toBe('calculated')
        ->and(DB::table('production_run_corrections')->where('id', $proposal->id)->value('status'))->toBe('prepared')
        ->and(DB::table('production_piece_approvals')->where('id', $approval->id)->value('revoked_at'))->toBeNull();
    $authorizedApprover = closureSyntheticUser();
    $authorizedApprover->givePermissionTo([
        'production.runs.correct_approve', 'production.runs.view', 'inventory.documents.view', 'hr.payroll_preparation.calculate',
    ]);
    productionDependencyActor($fixture, $authorizedApprover);
    $service->approve($fixture['run'], $proposal->id);

    expect(DB::table('hr_payroll_runs')->where('id', $linked['run_id'])->value('status'))->toBe('draft')
        ->and(DB::table('hr_payroll_runs')->where('id', $unrelated['run_id'])->value('status'))->toBe('under_review')
        ->and(DB::table('production_piece_approvals')->where('id', $approval->id)->value('production_run_correction_id'))->toBe($proposal->id);
});

test('canonical payroll payment overhead and payroll reversals clear dependencies while altered inverse evidence blocks', function (): void {
    $fixture = payrollFinancialFixture(isolated: true);
    Carbon::setTestNow('2026-09-30 18:00:00');
    $actor = payrollFinancialActor([
        'hr.payroll_approval.correct', 'hr.payroll_approval.correct_approve', 'hr.payroll_preparation.calculate',
        'cash_payment_vouchers.approve', 'cash_payment_vouchers.cancel', 'costing.overhead_allocation_run.reverse',
    ]);
    $this->actingAs($actor)->withSession(payrollFinancialContext($fixture));
    request()->setUserResolver(fn (): User => $actor);
    request()->setLaravelSession(app('session.store'));
    request()->session()->put(payrollFinancialContext($fixture));
    $unit = ItemUnit::query()->create([
        'company_id' => $fixture['company']->id, 'doc_number' => 99601, 'doc_num' => 'SYNTHETIC-DEPENDENCY-UNIT',
        'name' => 'Synthetic dependency unit', 'status' => 'active',
    ]);
    $product = Product::query()->create([
        'company_id' => $fixture['company']->id, 'doc_number' => 99601, 'doc_num' => 'SYNTHETIC-DEPENDENCY-PRODUCT',
        'name' => 'Synthetic dependency product', 'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $unit->id, 'status' => 'active',
    ]);
    $productionRun = payrollManufacturingRun($fixture, $product, $unit, 91, [[
        'employee_id' => $fixture['employee']->id, 'actual_hours' => '1',
    ]]);
    $productionRun->update(['status' => 'completed', 'actual_end_at' => '2026-09-30 17:00:00']);
    $payrollId = payrollCorrectionPostedRun($fixture);
    $posting = DB::table('hr_payroll_postings')->where('payroll_run_id', $payrollId)->sole();
    $linkedItem = DB::table('hr_payslip_items')->whereIn('payslip_id', DB::table('hr_payslips')->where('payroll_run_id', $payrollId)->select('id'))->first();
    DB::table('hr_payslip_items')->where('id', $linkedItem->id)->update([
        'source_snapshot' => json_encode(['accrual' => ['evidence' => ['production_run_ids' => [$productionRun->id]]]], JSON_THROW_ON_ERROR),
        'updated_at' => now(),
    ]);

    $slip = DB::table('hr_payslips')->where('payroll_run_id', $payrollId)->sole();
    $payment = app(PayrollPaymentService::class)->createCashPayment($payrollId, $fixture['company']->id, [
        'payslip_id' => $slip->id, 'cashbox_doc_num' => $fixture['cashbox']->doc_num, 'amount' => '100.0000',
        'payment_date' => '2026-09-30', 'idempotency_key' => (string) Str::uuid(),
    ]);
    $cash = app(CashVoucherService::class);
    $cash->approve(CashVoucher::TypePayment, $payment['voucher'], $fixture['company']->id);
    $cash->cancel(CashVoucher::TypePayment, $payment['voucher']->fresh(), 'Synthetic payroll dependency cancellation');

    $rule = OverheadAllocationRule::query()->create([
        'company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id, 'doc_number' => 99601,
        'doc_num' => 'SYNTHETIC-DEPENDENCY-RULE', 'name' => 'Synthetic dependency allocation',
        'source_cost_center_id' => $fixture['cost_center']->id, 'source_account_ids' => [$fixture['direct_labor_account']->id],
        'target_cost_center_ids' => [$fixture['cost_center']->id], 'basis' => OverheadAllocationRule::BasisLaborHours,
        'cost_behavior' => 'variable', 'effective_from' => '2026-09-01', 'status' => 'active',
    ]);
    $allocation = OverheadAllocationRun::query()->create([
        'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id, 'branch_id' => $fixture['branch']->id,
        'rule_id' => $rule->id, 'doc_number' => 99601, 'doc_num' => 'SYNTHETIC-DEPENDENCY-ALLOCATION',
        'from_date' => '2026-09-01', 'to_date' => '2026-09-30', 'status' => 'draft', 'basis_used' => OverheadAllocationRule::BasisLaborHours,
        'eligible_cost' => '1.0000', 'allocatable_cost' => '1.0000', 'allocated_cost' => '1.0000', 'unallocated_cost' => '0.0000',
        'input_fingerprint' => hash('sha256', 'synthetic dependency'), 'idempotency_key' => hash('sha256', 'synthetic dependency allocation'),
        'policy_snapshot' => [],
    ]);
    $allocationJournal = app(JournalEntryService::class)->createPostedFromSource([
        'entry_date' => '2026-09-30', 'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id, 'currency_id' => $fixture['currency']->id, 'exchange_rate' => 1,
        'description' => 'Synthetic overhead allocation', 'source_type' => 'overhead_allocation',
        'source_id' => $allocation->id, 'source_doc_num' => $allocation->doc_num,
    ], [[
        'account_id' => $fixture['payable_account']->id, 'debit_amount' => '1.0000', 'credit_amount' => '0.0000',
        'description' => 'Synthetic allocation debit', 'branch_id' => $fixture['branch']->id,
    ], [
        'account_id' => $fixture['direct_labor_account']->id, 'debit_amount' => '0.0000', 'credit_amount' => '1.0000',
        'description' => 'Synthetic allocation credit', 'branch_id' => $fixture['branch']->id,
    ]]);
    $allocation->update(['status' => 'posted', 'journal_entry_id' => $allocationJournal->id, 'posted_at' => now(), 'posted_by' => $actor->id]);
    $payrollSourceLine = DB::table('journal_entry_lines')->where('journal_entry_id', $posting->journal_entry_id)->first();
    DB::table('cost_overhead_allocation_sources')->insert([
        'allocation_run_id' => $allocation->id, 'journal_entry_line_id' => $payrollSourceLine->id,
        'account_id' => $payrollSourceLine->account_id, 'source_amount' => '1.0000', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('cost_overhead_allocation_lines')->insert([
        'allocation_run_id' => $allocation->id, 'production_run_id' => $productionRun->id,
        'cost_center_id' => $fixture['cost_center']->id, 'labor_hours' => '1.00000000', 'direct_material_cost' => '0.0000',
        'basis_value' => '1.00000000', 'allocation_percent' => '100.00000000', 'allocated_amount' => '1.0000',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    app(OverheadAllocationService::class)->reverse($allocation, 'Synthetic canonical dependency reversal');

    $payrollCorrections = app(PayrollCorrectionService::class);
    $preview = $payrollCorrections->preview($payrollId);
    $payrollProposal = $payrollCorrections->propose($payrollId, '2026-09-30', 'Synthetic canonical payroll correction', $preview['fingerprint']);
    $approver = payrollFinancialActor(['hr.payroll_approval.correct_approve', 'hr.payroll_preparation.calculate']);
    $this->actingAs($approver)->withSession(payrollFinancialContext($fixture));
    request()->setUserResolver(fn (): User => $approver);
    request()->session()->put(payrollFinancialContext($fixture));
    $payrollCorrections->approve($payrollId, $payrollProposal->id);

    $dependencies = app(ProductionCorrectionDependencyService::class);
    $snapshot = $dependencies->snapshot($productionRun->fresh(), collect(), collect());
    expect($dependencies->steps($snapshot))->toBe([]);
    $paymentReversal = DB::table('hr_payroll_payments')->where('payroll_run_id', $payrollId)->value('reversal_journal_entry_id');
    $reversalLine = DB::table('journal_entry_lines')->where('journal_entry_id', $paymentReversal)->first();
    DB::table('journal_entry_lines')->where('id', $reversalLine->id)->update(['debit_amount' => '999.0000']);
    $tampered = $dependencies->snapshot($productionRun->fresh(), collect(), collect());
    expect(collect($dependencies->steps($tampered))->pluck('action')->all())->toContain('repair_payroll_payment_reversal_evidence');
});
