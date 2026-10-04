<?php

use App\Models\User;
use App\Services\PostingAccountResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\OverheadAllocationRule;
use Modules\Accounting\Models\OverheadAllocationRun;
use Modules\Accounting\Services\CostingReportService;
use Modules\Auth\Models\Role;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\DateFormatService;
use Modules\HR\Services\PayrollCalculationService;
use Modules\HR\Services\PayrollCorrectionService;
use Modules\HR\Services\PayrollLifecycleService;
use Modules\Inventory\Exports\InventoryPeriodicCostCloseExport;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Inventory\Services\InventoryStandardCostReport;
use Modules\Inventory\Services\InventoryStandardCostService;
use Modules\Production\Services\ProductionCostService;
use Modules\Production\Services\ProductionCycleService;
use Modules\Production\Services\ProductionExpenseRequestService;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\CustomerCommercialAgreement;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\SalesOrderService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;

require_once __DIR__.'/PayrollFinancialSupport.php';
require_once __DIR__.'/InventoryStandardCostSupport.php';

test('posted direct payroll follows each employee actual run hours into WIP finished goods and sales COGS through authorized routes', function (bool $laterPeriod, bool $boundaryRun, bool $standardCost): void {
    config()->set('erp.phase_mode', 'expanded');
    $fixture = payrollFinancialFixture(isolated: true);
    Carbon::setTestNow('2026-09-30 18:00:00');
    $permissions = ['hr.payroll_approval.correct', 'hr.payroll_approval.correct_approve', 'hr.payroll_approval.correct_later_period', 'costing.overhead_allocation_rules.view',
        'costing.overhead_allocation_rules.create', 'costing.overhead_allocation_run.view', 'costing.overhead_allocation_run.create',
        'costing.overhead_allocation_run.approve', 'costing.overhead_allocation_run.reverse', 'production.runs.view', 'production.runs.labor', 'production.runs.receive',
        'inventory.documents.create', 'inventory.documents.issue', 'inventory.documents.view', 'reports.costing.product_cost.view',
        'reports.costing.product_cost.export', 'reports.costing.product_cost.print'];
    $actor = payrollFinancialActor($permissions);
    $this->actingAs($actor)->withSession(payrollFinancialContext($fixture));
    request()->setUserResolver(fn (): User => $actor);
    request()->setLaravelSession(app('session.store'));
    request()->session()->put(payrollFinancialContext($fixture));
    $postingPeriod = $fixture['period'];
    $postingDate = '2026-09-30';
    if ($laterPeriod) {
        $fixture['period']->update(['to_date' => '2026-09-30']);
        $postingPeriod = FinancialPeriod::query()->create(['company_id' => $fixture['company']->id,
            'doc_number' => 7002, 'doc_num' => 'SYNTHETIC-PAY-FP-OCT', 'name' => 'SYNTHETIC October posting period',
            'from_date' => '2026-10-01', 'to_date' => '2026-12-31', 'is_closed' => false, 'allows_opening_entries' => false]);
        $postingDate = '2026-10-01';
    }
    $fixture['employee']->update(['person_type' => 'regular_labor']);
    $employeeB = $fixture['employee']->replicate(['public_uuid']);
    $employeeB->fill(['doc_number' => 7002, 'doc_num' => 'SYNTHETIC-PAY-EMP-07002', 'employee_code' => 'SYNTHETIC-PAY-E002',
        'full_name' => 'SYNTHETIC second manufacturing worker', 'name' => 'SYNTHETIC second manufacturing worker', 'basic_salary' => '20000'])->save();
    DB::table('hr_employee_salary_assignments')->insert(['employee_id' => $employeeB->id, 'effective_from' => '2026-01-01',
        'basic_salary' => '20000', 'components' => json_encode(['items' => []], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
    $payrollId = payrollCorrectionPostedRun($fixture);
    if ($laterPeriod) {
        $postingContext = payrollFinancialContext([...$fixture, 'period' => $postingPeriod]);
        $this->withSession($postingContext);
        request()->session()->put($postingContext);
        $corrections = app(PayrollCorrectionService::class);
        $proposal = $corrections->propose($payrollId, $postingDate, 'SYNTHETIC later-period replacement', $corrections->preview($payrollId)['fingerprint'], PayrollCorrectionService::ModeLaterPeriod);
        $approver = payrollFinancialActor(['hr.payroll_approval.correct_approve', 'hr.payroll_approval.correct_later_period']);
        $this->actingAs($approver);
        request()->setUserResolver(fn (): User => $approver);
        $corrections->approve($payrollId, $proposal->id);
        $this->actingAs($actor);
        request()->setUserResolver(fn (): User => $actor);
        $payrollId = payrollCorrectionPostedRun($fixture);
        expect(DB::table('hr_payroll_runs')->where('id', $payrollId)->value('posting_financial_period_id'))->toBe($postingPeriod->id);
        Carbon::setTestNow($postingDate.' 18:00:00');
        $this->withSession(payrollFinancialContext($fixture));
        request()->session()->put(payrollFinancialContext($fixture));
    }
    $postedPayroll = DB::table('hr_payroll_postings')->where('payroll_run_id', $payrollId)->first();
    expect(bcadd((string) DB::table('hr_payslips')->where('payroll_run_id', $payrollId)->sum('gross_amount'), '0', 4))->toBe('30200.0000');
    $center = $fixture['cost_center'];
    $center->accounts()->sync([$fixture['direct_labor_account']->id]);
    expect(bcadd((string) JournalEntry::findOrFail($postedPayroll->journal_entry_id)->lines()->where('account_id', $fixture['direct_labor_account']->id)
        ->where('cost_center_id', $center->id)->whereNotNull('employee_id')->sum('debit_amount'), '0', 4))->toBe('30200.0000');
    foreach ([PostingAccountResolver::WorkInProcessInventory, PostingAccountResolver::FinishedGoodsInventory,
        PostingAccountResolver::CostOfGoodsSold, PostingAccountResolver::SalesRevenue] as $index => $classification) {
        payrollFinancialAccount($fixture['company'], $classification, 'SYNTHETIC-PAY-COST-'.(8100 + $index), 8100 + $index);
    }
    $store = BranchStore::query()->create(['branch_id' => $fixture['branch']->id, 'name' => 'SYNTHETIC payroll FG store']);
    $unit = ItemUnit::query()->create(['company_id' => $fixture['company']->id, 'doc_number' => 7701,
        'doc_num' => 'SYNTHETIC-PAY-UNIT', 'name' => 'SYNTHETIC piece', 'status' => 'active']);
    $product = Product::query()->create(['company_id' => $fixture['company']->id, 'doc_number' => 7701,
        'doc_num' => 'SYNTHETIC-PAY-FG', 'name' => 'SYNTHETIC payroll finished product', 'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $unit->id, 'status' => 'active']);
    $runA = payrollManufacturingRun($fixture, $product, $unit, 1, [
        ['employee_id' => $fixture['employee']->id, 'actual_hours' => '2'], ['employee_id' => $employeeB->id, 'actual_hours' => '1']]);
    $runB = payrollManufacturingRun($fixture, $product, $unit, 2, [
        ['employee_id' => $fixture['employee']->id, 'actual_hours' => '3'], ['employee_id' => $employeeB->id, 'actual_hours' => '3']]);
    if ($boundaryRun) {
        $runB->update(['actual_start_at' => '2026-09-30 08:00:00', 'actual_end_at' => '2026-10-01 18:00:00']);
        $runB->progressEntries()->update(['recorded_at' => '2026-10-01 18:00:00']);
        $this->get(route('admin.production.runs.show', $runB))->assertOk()->assertSee(__('production_execution.fields.daily_work_hours'));
        $runB->update(['actual_start_at' => '2026-09-30 23:00:00', 'actual_end_at' => '2026-10-01 01:00:00']);
        $this->postJson(route('admin.production.runs.labor', $runB), [
            '_submission_token' => (string) Str::uuid(), 'actual_labor_count' => 1,
            'labor_details' => [['employee_id' => $employeeB->id, 'actual_hours' => '48', 'work_segments' => [
                ['work_date' => '2026-09-30', 'actual_hours' => '24'], ['work_date' => '2026-10-01', 'actual_hours' => '24'],
            ]]],
        ])->assertUnprocessable()->assertJsonPath('errors.production.0', __('production_execution.messages.labor_day_duration'));
        expect($runB->fresh()->labor_details[0]['actual_hours'])->toBe('3');
        $runB->update(['actual_start_at' => '2026-09-30 08:00:00', 'actual_end_at' => '2026-10-01 18:00:00']);
    }
    foreach ([$runA, $runB] as $run) {
        $this->postJson(route('admin.production.runs.labor', $run), [
            '_submission_token' => (string) Str::uuid(), 'actual_labor_count' => 2,
            'labor_details' => $run->labor_details,
        ])->assertOk();
        expect($run->fresh()->labor_details[0]['recorded_by'])->toBe($actor->id);
    }
    $postingContext = payrollFinancialContext([...$fixture, 'period' => $postingPeriod]);
    $this->withSession($postingContext);
    request()->session()->put($postingContext);
    $windowStart = $laterPeriod ? '2026-10-01' : '2026-09-01';
    $windowEnd = $laterPeriod ? '2026-10-31' : '2026-09-30';
    $rulePayload = ['name' => 'SYNTHETIC direct payroll', 'source_cost_center_id' => $center->id,
        'source_account_ids' => [$fixture['direct_labor_account']->id], 'target_cost_center_ids' => [$center->id],
        'basis' => OverheadAllocationRule::BasisDirectPayrollHours, 'fallback_basis' => null, 'cost_behavior' => 'variable',
        'effective_from' => $windowStart, 'effective_to' => $windowEnd];
    $this->post(route('admin.costing.overhead-allocation-rules.store'), $rulePayload)->assertRedirect()->assertSessionHasNoErrors();
    $rule = OverheadAllocationRule::query()->where('company_id', $fixture['company']->id)->sole();
    if ($laterPeriod) {
        $role = Role::query()->create(['name' => 'SYNTHETIC October-only allocator', 'guard_name' => 'web',
            'doc_number' => (int) Role::withTrashed()->max('doc_number') + 1, 'doc_num' => 'SYNTHETIC-PAY-COST-ROLE',
            'financial_period_access_restricted' => true, 'company_access_restricted' => false, 'branch_access_restricted' => false]);
        $role->financialPeriodAccessPeriods()->sync([$postingPeriod->id]);
        $actor->assignRole($role);
        $this->actingAs($actor->fresh())->post(route('admin.costing.overhead-allocation-run.preview'), [
            'rule_public_id' => $rule->public_id, 'from_date' => $windowStart, 'to_date' => $windowEnd,
        ])->assertForbidden();
        expect(OverheadAllocationRun::query()->where('company_id', $fixture['company']->id)->exists())->toBeFalse();
        $role->financialPeriodAccessPeriods()->sync([$postingPeriod->id, $fixture['period']->id]);
        $this->actingAs($actor->fresh());
    }
    if ($boundaryRun) {
        $this->post(route('admin.costing.overhead-allocation-run.preview'), [
            'rule_public_id' => $rule->public_id, 'from_date' => $windowStart, 'to_date' => $windowEnd,
        ])->assertRedirect()->assertSessionHasErrors('allocation');
        $labor = $runB->labor_details;
        foreach ($labor as &$worker) {
            $worker['actual_hours'] = '5';
            $worker['work_segments'] = [['work_date' => '2026-09-30', 'actual_hours' => '3'], ['work_date' => '2026-10-01', 'actual_hours' => '2']];
        }
        unset($worker);
        $this->withSession(payrollFinancialContext($fixture))->postJson(route('admin.production.runs.labor', $runB), [
            '_submission_token' => (string) Str::uuid(), 'actual_labor_count' => 2, 'labor_details' => $labor,
        ])->assertOk();
        expect($runB->fresh()->labor_details[0]['work_segments'])->toHaveCount(2);
        $this->withSession($postingContext);
    }
    $preview = $this->post(route('admin.costing.overhead-allocation-run.preview'), ['rule_public_id' => $rule->public_id,
        'from_date' => $windowStart, 'to_date' => $windowEnd])->assertRedirect()->assertSessionHasNoErrors();
    $allocation = OverheadAllocationRun::query()->where('company_id', $fixture['company']->id)->sole();
    $this->get($preview->headers->get('Location'))->assertOk()->assertSee(__('overhead_allocations.direct_payroll_details'))
        ->assertSee('9,080')->assertSee('21,120');
    expect($allocation->eligible_cost)->toBe('30200.0000')
        ->and($allocation->lines->firstWhere('production_run_id', $runA->id)->allocated_amount)->toBe('9080.0000')
        ->and($allocation->lines->firstWhere('production_run_id', $runB->id)->allocated_amount)->toBe('21120.0000')
        ->and(collect($allocation->policy_snapshot['direct_payroll_allocations'])->pluck('employee_id')->unique()->count())->toBe(2);
    if ($laterPeriod) {
        $role->financialPeriodAccessPeriods()->sync([$postingPeriod->id]);
        $this->actingAs($actor->fresh())->get($preview->headers->get('Location'))->assertForbidden();
        $this->actingAs($actor->fresh())->post(route('admin.costing.overhead-allocation-run.approve', $allocation->public_id))->assertForbidden();
        expect($allocation->fresh()->status)->toBe('draft')->and($allocation->journal_entry_id)->toBeNull();
        $role->financialPeriodAccessPeriods()->sync([$postingPeriod->id, $fixture['period']->id]);
        $this->actingAs($actor->fresh());
    }
    $originalLabor = [$runA->id => $runA->fresh()->labor_details, $runB->id => $runB->fresh()->labor_details];
    $this->withSession(payrollFinancialContext($fixture));
    $changed = $originalLabor[$runA->id];
    $changed[0]['actual_hours'] = '1';
    $changed[1]['actual_hours'] = '2';
    $this->postJson(route('admin.production.runs.labor', $runA), [
        '_submission_token' => (string) Str::uuid(), 'actual_labor_count' => 2, 'labor_details' => $changed,
    ])->assertOk();
    $this->withSession($postingContext)->post(route('admin.costing.overhead-allocation-run.approve', $allocation->public_id))
        ->assertRedirect()->assertSessionHasErrors('allocation');
    expect($allocation->fresh()->status)->toBe('draft')->and($allocation->journal_entry_id)->toBeNull();
    foreach ([$runA, $runB] as $run) {
        $this->withSession(payrollFinancialContext($fixture))->postJson(route('admin.production.runs.labor', $run), [
            '_submission_token' => (string) Str::uuid(), 'actual_labor_count' => 1,
            'labor_details' => [$originalLabor[$run->id][0]],
        ])->assertOk();
    }
    $this->withSession($postingContext)->post(route('admin.costing.overhead-allocation-run.preview'), [
        'rule_public_id' => $rule->public_id, 'from_date' => $windowStart, 'to_date' => $windowEnd,
    ])->assertRedirect()->assertSessionHasErrors('allocation');
    expect(OverheadAllocationRun::query()->where('company_id', $fixture['company']->id)->count())->toBe(1);
    foreach ([$runA, $runB] as $run) {
        $this->withSession(payrollFinancialContext($fixture))->postJson(route('admin.production.runs.labor', $run), [
            '_submission_token' => (string) Str::uuid(), 'actual_labor_count' => 2, 'labor_details' => $originalLabor[$run->id],
        ])->assertOk();
    }
    $this->withSession($postingContext);
    request()->session()->put($postingContext);
    $this->post(route('admin.costing.overhead-allocation-run.approve', $allocation->public_id))->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('admin.costing.overhead-allocation-run.approve', $allocation->public_id))->assertRedirect()->assertSessionHasNoErrors();
    $costs = app(ProductionCostService::class);
    expect($costs->runPosition($runA)['direct_labor_cost'])->toBe('9080.00000000')
        ->and($costs->runPosition($runA)['allocated_overhead'])->toBe('0.00000000')
        ->and($costs->runPosition($runB)['wip'])->toBe('21120.00000000');
    if ($boundaryRun) {
        expect($costs->runPosition($runB)['labor_valuation_complete'])->toBeFalse();
        $this->withSession(payrollFinancialContext($fixture))->postJson(route('admin.production.runs.receive', $runB), [
            '_submission_token' => (string) Str::uuid(), 'branch_store_id' => $store->id, 'base_quantity' => '100',
        ])->assertUnprocessable()->assertJsonPath('errors.production.0', __('production_execution.messages.unvalued_labor_cost'));
        expect($runB->fresh()->received_base_quantity)->toBe('0.00000000');
    }
    $changed = $originalLabor[$runA->id];
    $changed[0]['actual_hours'] = '3';
    $this->withSession(payrollFinancialContext($fixture))->postJson(route('admin.production.runs.labor', $runA), [
        '_submission_token' => (string) Str::uuid(), 'actual_labor_count' => 2, 'labor_details' => $changed,
    ])->assertUnprocessable()->assertJsonPath('errors.production.0', __('production_execution.messages.reverse_labor_allocation'));
    expect($runA->fresh()->labor_details[0]['actual_hours'])->toBe('2');
    $this->get(route('admin.hr.payroll-runs.corrections.index', $payrollId))->assertOk();
    expect(fn () => app(PayrollCorrectionService::class)->propose($payrollId, $postingDate, 'SYNTHETIC correction',
        app(PayrollCorrectionService::class)->preview($payrollId)['fingerprint']))->toThrow(DomainException::class);
    $this->withSession(payrollFinancialContext($fixture));
    request()->session()->put(payrollFinancialContext($fixture));
    if ($standardCost) {
        foreach ([PostingAccountResolver::RawMaterialInventory, PostingAccountResolver::PackagingMaterialInventory,
            PostingAccountResolver::QuarantineInventory, PostingAccountResolver::ReworkInventory,
            PostingAccountResolver::AbnormalWasteLoss, PostingAccountResolver::InventoryAdjustmentGain,
            PostingAccountResolver::InventoryAdjustmentLoss, PostingAccountResolver::WarehouseDamageLoss] as $index => $classification) {
            payrollFinancialAccount($fixture['company'], $classification, 'SYNTHETIC-PAY-RECON-'.(8200 + $index), 8200 + $index);
        }
        $standardPermissions = array_map(fn (string $action): string => 'inventory.cost_policies.standard.'.$action, ['view', 'prepare', 'settle', 'approve', 'export', 'print']);
        foreach ($standardPermissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $actor->givePermissionTo($standardPermissions);
        $standardFixture = standardCostAccounts([...$fixture, 'finished' => $product, 'preparer' => $actor,
            'approver' => payrollFinancialActor(['inventory.cost_policies.standard.view', 'inventory.cost_policies.standard.approve'])]);
        $standardService = app(InventoryStandardCostService::class);
        $standard = $standardService->prepareVersion($fixture['company']->id, [
            'branch_id' => $fixture['branch']->id, 'product_id' => $product->id, 'effective_from' => $postingDate,
            'effective_to' => $fixture['period']->to_date->toDateString(), 'materials_unit_cost' => '0', 'labor_unit_cost' => '90', 'overhead_unit_cost' => '1',
            'materials_variance_account_id' => $standardFixture['materials_account']->id, 'labor_variance_account_id' => $standardFixture['labor_account']->id,
            'overhead_variance_account_id' => $standardFixture['overhead_account']->id, 'counterpart_account_id' => $standardFixture['clearing_account']->id,
            'source_reference' => 'SYNTHETIC payroll and paid expense standard',
        ], $actor->id);
        standardCostActor($standardFixture['approver']);
        $standardService->approveVersion($standard, $standardFixture['approver']->id, 'SYNTHETIC independent labor and overhead standard');
        standardCostActor($actor);
        $expenses = app(ProductionExpenseRequestService::class);
        $expense = $expenses->approve($expenses->create($runA, ['amount' => '125', 'currency_id' => $fixture['currency']->id,
            'payment_channel' => 'cashbox', 'cashbox_id' => $fixture['cashbox']->id,
            'expense_account_id' => $standardFixture['overhead_account']->id, 'reason' => 'SYNTHETIC actual production expense']));
        $expenses->pay($expense);
    }
    $receiptResponse = $this->postJson(route('admin.production.runs.receive', $runA), [
        '_submission_token' => (string) Str::uuid(), 'branch_store_id' => $store->id, 'base_quantity' => '100',
    ])->assertOk();
    $receipt = InventoryDocument::query()->where('company_id', $fixture['company']->id)
        ->where('doc_num', $receiptResponse->json('data.doc_num'))->sole();
    expect($receipt->financial_period_id)->toBe($postingPeriod->id);
    $this->withSession($postingContext);
    request()->session()->put($postingContext);
    expect($receipt->transactions->sole()->total_cost)->toBe($standardCost ? '9205.00000000' : '9080.00000000')
        ->and($costs->runPosition($runA)['wip'])->toBe('0.00000000');
    if ($standardCost) {
        $runA = app(ProductionCycleService::class)->completeRun($runA->fresh());
        $settlement = $standardService->prepareSettlement($runA, $postingDate, 'SYNTHETIC actual payroll variance', $actor->id);
        expect($settlement->impact_snapshot['components']['labor']['actual_total'])->toBe('9080.00000000')
            ->and($settlement->impact_snapshot['components']['labor']['variance'])->toBe('80.00000000')
            ->and($settlement->impact_snapshot['components']['overhead']['actual_total'])->toBe('125.00000000')
            ->and($settlement->impact_snapshot['components']['overhead']['variance'])->toBe('25.00000000');
        standardCostActor($standardFixture['approver']);
        $standardService->approveSettlement($settlement, $standardFixture['approver']->id, 'SYNTHETIC source-proven payroll variance approval');
        standardCostActor($actor);
        $allocation->refresh();
        $standardSections = app(InventoryStandardCostReport::class)->sections($settlement->fresh());
        $sectionText = json_encode($standardSections, JSON_THROW_ON_ERROR);
        expect($sectionText)->toContain('Frozen payroll and overhead allocations', 'Frozen production expense sources', '9080.0000', '125.0000', $allocation->journalEntry->doc_num);
        $this->get(route('admin.inventory.standard-costs.show', ['kind' => 'settlements', 'uuid' => $settlement->public_uuid]))->assertOk()
            ->assertSee('9080.0000')->assertSee('125.0000')->assertSee($allocation->journalEntry->doc_num);
        foreach (['ar', 'en'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();
            $this->withSession([...$postingContext, 'locale' => $locale]);
            app()->setLocale($locale);
            $sections = app(InventoryStandardCostReport::class)->sections($settlement->fresh());
            $allocationSection = collect($sections)->firstWhere('title', __('inventory_standard_cost.allocation_sources'));
            expect($allocationSection['rows'][0][2])->toBe(app(DateFormatService::class)->formatDate($allocation->from_date))
                ->and($allocationSection['rows'][0][3])->toBe(app(DateFormatService::class)->formatDate($allocation->to_date));
            if ($locale === 'ar') {
                expect(json_encode($sections, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))->not->toContain('From Date', 'To Date');
            }
            $rows = (new InventoryPeriodicCostCloseExport($sections))->array();
            foreach (['xlsx', 'csv'] as $format) {
                $output = $this->get(route('admin.inventory.standard-costs.output', ['kind' => 'settlements', 'uuid' => $settlement->public_uuid, 'format' => $format]))->assertOk();
                if ($format === 'csv') {
                    $handle = fopen($output->baseResponse->getFile()->getPathname(), 'r');
                    try {
                        foreach ($rows as $row) {
                            $actual = fgetcsv($handle, separator: ',', enclosure: '"', escape: '');
                            foreach ($row as $column => $expected) {
                                expect($actual[$column] ?? '')->toBe((string) $expected);
                            }
                        }
                    } finally {
                        fclose($handle);
                    }
                } else {
                    $workbook = IOFactory::load($output->baseResponse->getFile()->getPathname());
                    foreach ($rows as $index => $row) {
                        foreach ($row as $column => $expected) {
                            expect((string) ($workbook->getActiveSheet()->getCell([$column + 1, $index + 1])->getValue() ?? ''))->toBe((string) $expected);
                        }
                    }
                    $workbook->disconnectWorksheets();
                }
            }
            $pdf = $this->get(route('admin.inventory.standard-costs.output', ['kind' => 'settlements', 'uuid' => $settlement->public_uuid, 'format' => 'pdf']))->assertOk();
            file_put_contents('/tmp/mgypack-standard-payroll-'.DB::getDriverName().'-'.$locale.'-20261003.pdf', $pdf->getContent());
            $text = new Process(['pdftotext', '-layout', '-', '-']);
            $text->setInput($pdf->getContent());
            $text->mustRun();
            expect($text->getOutput())->toContain('9080.0000', '125.0000', $allocation->journalEntry->doc_num, $expense->doc_num);
        }
        $actor->forceFill(['locale' => 'en'])->save();
        app()->setLocale('en');
        $this->withSession($postingContext);
        request()->session()->put($postingContext);
        expect($costs->runPosition($runA)['finished_goods'])->toBe('9100.00000000')
            ->and($costs->runPosition($runA)['standard_variance'])->toBe('105.00000000')
            ->and($costs->runPosition($runA)['wip'])->toBe('0.00000000');
    }
    $receivable = payrollFinancialAccount($fixture['company'], 'accounts_receivable', 'SYNTHETIC-PAY-AR', 8190);
    $customer = Customer::query()->create(['company_id' => $fixture['company']->id, 'doc_number' => 7701,
        'doc_num' => 'SYNTHETIC-PAY-CUSTOMER', 'name' => 'SYNTHETIC manufacturing customer', 'account_id' => $receivable->id, 'status' => 'active']);
    CustomerCommercialAgreement::query()->create(['company_id' => $fixture['company']->id, 'customer_id' => $customer->id,
        'currency_id' => $fixture['currency']->id, 'customer_type' => 'credit', 'credit_limit' => '100000', 'include_open_orders' => true,
        'required_advance_percentage' => 0, 'required_advance_minimum' => 0, 'blocking_enabled' => true, 'temporary_override_allowed' => true, 'status' => 'active']);
    $orders = app(SalesOrderService::class);
    $order = $orders->approve($orders->create(['company_id' => $fixture['company']->id, 'financial_period_id' => $postingPeriod->id,
        'branch_id' => $fixture['branch']->id, 'branch_store_id' => $store->id, 'customer_id' => $customer->id,
        'currency_id' => $fixture['currency']->id, 'order_date' => $postingDate, 'expected_delivery_date' => $postingDate,
        'lines' => [['product_id' => $product->id, 'unit_id' => $unit->id, 'description' => 'SYNTHETIC payroll-costed FG', 'quantity' => '40', 'unit_price' => '200']],
        'payment_schedules' => [['title' => 'SYNTHETIC due', 'due_date' => $postingDate, 'amount' => '8000']]]));
    $invoices = app(CustomerInvoiceService::class);
    $invoice = $invoices->post($invoices->createFromOrder($order, [['sales_order_line_id' => $order->lines->sole()->id, 'quantity' => '40']],
        [['due_date' => $postingDate, 'amount' => '8000']]));
    $response = $this->postJson(route('admin.inventory.documents.sales-issue.store'), [
        'sales_issue_order_doc_num' => $invoice->issueOrder->doc_num, 'branch_store_uuid' => $store->public_uuid, 'document_date' => $postingDate])->assertCreated();
    $delivery = InventoryDocument::query()->where('company_id', $fixture['company']->id)->where('doc_num', $response->json('doc_num'))->sole();
    expect($delivery->transactions->sole()->total_cost)->toBe($standardCost ? '3640.00000000' : '3632.00000000')
        ->and($delivery->journalEntry->lines()->whereHas('account.classification', fn ($query) => $query->where('code', PostingAccountResolver::CostOfGoodsSold))->sole()->debit_amount)->toBe($standardCost ? '3640.0000' : '3632.0000');
    $this->post(route('admin.costing.overhead-allocation-run.reverse', $allocation->public_id), ['reason' => 'SYNTHETIC blocked received cost'])
        ->assertRedirect()->assertSessionHasErrors('allocation');
    expect($allocation->fresh()->status)->toBe('posted')->and(JournalEntry::find($postedPayroll->journal_entry_id)->reversed_entry_id)->toBeNull();
    foreach ([PostingAccountResolver::WorkInProcessInventory => '21120.0000',
        PostingAccountResolver::FinishedGoodsInventory => $standardCost ? '5460.0000' : '5448.0000', PostingAccountResolver::CostOfGoodsSold => $standardCost ? '3640.0000' : '3632.0000',
        'direct_labor_cost' => '0.0000'] as $classification => $expected) {
        $accountId = Account::query()->where('company_id', $fixture['company']->id)
            ->whereHas('classification', fn ($query) => $query->where('code', $classification))->sole()->id;
        $net = DB::table('journal_entry_lines as line')->join('journal_entries as entry', 'entry.id', '=', 'line.journal_entry_id')
            ->where('entry.company_id', $fixture['company']->id)->whereNull('entry.deleted_at')
            ->where('entry.status', 'posted')->where('line.account_id', $accountId)->sum(DB::raw('line.debit_amount - line.credit_amount'));
        expect(bcadd((string) $net, '0', 4))->toBe($expected);
    }
    if ($standardCost) {
        $reconciled = collect(app(InventoryGlReconciliationService::class)->reconcile($fixture['company']->id, $postingPeriod->id, $fixture['branch']->id));
        expect($reconciled->every(fn (array $row): bool => bccomp($row['difference'], '0', 4) === 0))->toBeTrue($reconciled->toJson());
    }
    $this->withSession(payrollFinancialContext($fixture));
    request()->session()->put(payrollFinancialContext($fixture));
    $report = $this->get(route('admin.reports.costing.product-cost.index'))->assertOk()->assertSee('Direct payroll labor')->assertSee('30,200');
    $costReport = app(CostingReportService::class)->report(['type' => 'product_cost']);
    expect($costReport['rows']->sole()['received_quantity'])->toBe('100.00000000')
        ->and($costReport['rows']->sole()['unit_cost'])->toBe($standardCost ? '91.00000000' : '90.80000000');
    foreach (['csv', 'excel'] as $format) {
        $response = $this->get(route('admin.accounting.reports.costing.export.'.$format, ['type' => 'product_cost']))->assertOk();
        $rows = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray();
        $directIndex = array_search(__('costing_reports.columns.direct_labor_cost'), $rows[0], true);
        expect($directIndex)->not->toBeFalse();
        expect(bcadd((string) $rows[1][$directIndex], '0', 4))->toBe('30200.0000')
            ->and(bcadd((string) $rows[2][$directIndex], '0', 4))->toBe('30200.0000');
    }
    $pdf = $this->get(route('admin.accounting.reports.costing.export.pdf', ['type' => 'product_cost']))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $extract = new Process(['pdftotext', '-layout', '-', '-']);
    $extract->setInput($pdf->getContent());
    $extract->run();
    if ($standardCost) {
        file_put_contents('/tmp/mgypack-standard-product-cost-'.DB::getDriverName().'-20261003.txt', $extract->getOutput());
    }
    expect($extract->isSuccessful())->toBeTrue()->and($extract->getOutput())->toContain('30,200', '21,120', $standardCost ? '9,100' : '9,080');
    if ($standardCost) {
        expect($extract->getOutput())->toContain('30,325');
    }
    if ($boundaryRun) {
        Carbon::setTestNow('2026-10-31 18:00:00');
        $actor = payrollFinancialActor($permissions);
        $this->flushSession();
        $this->actingAs($actor)->withSession($postingContext);
        request()->setUserResolver(fn (): User => $actor);
        request()->session()->put($postingContext);
        $october = app(PayrollCalculationService::class)->calculate($fixture['company']->id, [
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'branch_doc_num' => $fixture['branch']->doc_num,
        ]);
        $payroll = app(PayrollLifecycleService::class);
        $payroll->submitForReview($october['run_id'], $fixture['company']->id);
        $payroll->approve($october['run_id'], $fixture['company']->id);
        $preview = $this->post(route('admin.costing.overhead-allocation-run.preview'), ['rule_public_id' => $rule->public_id,
            'from_date' => '2026-10-31', 'to_date' => '2026-10-31'])->assertRedirect()->assertSessionHasNoErrors();
        $octoberCost = OverheadAllocationRun::query()->where('company_id', $fixture['company']->id)->latest('id')->firstOrFail();
        expect($preview->headers->get('Location'))->toContain($octoberCost->public_id);
        expect($octoberCost->lines)->toHaveCount(1)->and($octoberCost->lines->sole()->production_run_id)->toBe($runB->id)
            ->and($octoberCost->allocated_cost)->toBe('30000.0000');
        $this->post(route('admin.costing.overhead-allocation-run.approve', $octoberCost->public_id))->assertRedirect()->assertSessionHasNoErrors();
        expect($costs->runPosition($runB->fresh())['labor_valuation_complete'])->toBeTrue();
        $response = $this->withSession(payrollFinancialContext($fixture))->postJson(route('admin.production.runs.receive', $runB), [
            '_submission_token' => (string) Str::uuid(), 'branch_store_id' => $store->id, 'base_quantity' => '100',
        ])->assertOk();
        $finalReceipt = InventoryDocument::query()->where('company_id', $fixture['company']->id)->where('doc_num', $response->json('data.doc_num'))->sole();
        expect($finalReceipt->transactions->sole()->total_cost)->toBe('51120.00000000')
            ->and($costs->runPosition($runB->fresh())['wip'])->toBe('0.00000000');
    }
})->with(['same financial period' => [false, false, false], 'later posting period for September work' => [true, false, false],
    'dated boundary run' => [true, true, false], 'approved standard actual payroll and paid expense' => [false, false, true]]);
