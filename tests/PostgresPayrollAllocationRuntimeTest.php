<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Services\DefaultLoginContextService;
use Modules\Core\Models\Company;
use Modules\HR\Models\HrPayrollAttendancePolicy;
use Modules\HR\Services\PayrollCalculationService;
use Modules\HR\Services\PayrollCostAllocationService;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/PayrollFinancialSupport.php';

beforeEach(function (): void {
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBe('mgypack_acceptance_closure_20261003')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
});

test('prepare explicitly synthetic legacy payroll allocation for the real screen', function (): void {
    if (getenv('MGYPACK_PAYROLL_ALLOCATION_BROWSER_CREATE') !== '1') {
        $this->markTestSkipped('Explicit synthetic browser preparation only.');
    }
    $path = '/tmp/mgypack-payroll-allocation-runtime-20261003.json';
    expect(file_exists($path))->toBeFalse();
    $manifest = DB::transaction(function (): array {
        $f = payrollFinancialFixture(true);
        $f['company']->update(['name' => 'SYNTHETIC fractional payroll '.$f['company']->id, 'legal_name' => 'SYNTHETIC fractional payroll '.$f['company']->id]);
        $f['employee']->update(['name' => 'موظف اختبار اصطناعي', 'full_name' => 'موظف اختبار اصطناعي']);
        $actor = payrollFinancialActor(['hr.payroll_preparation.view', 'hr.payroll_preparation.calculate', 'hr.payroll_approval.review', 'hr.payroll_approval.approve', 'hr.payroll_reconciliation.view']);
        $actor->update(['locale' => 'ar']);
        auth()->login($actor);
        request()->setUserResolver(fn (): User => $actor);
        request()->setLaravelSession(app('session.store'));
        session(payrollFinancialContext($f));
        Carbon::setTestNow('2026-10-03 12:00:00');
        HrPayrollAttendancePolicy::query()->create(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
            'branch_scope_key' => 'branch:'.$f['branch']->id, 'effective_from' => '2026-01-01', 'monthly_partial_method' => HrPayrollAttendancePolicy::MonthlyCalendarDays,
            'salary_day_divisor' => 30, 'standard_day_minutes' => 480, 'status' => 'active']);
        $run = app(PayrollCalculationService::class)->calculate($f['company']->id, ['period_start' => '2026-09-01', 'period_end' => '2026-09-10', 'branch_doc_num' => $f['branch']->doc_num])['run_id'];
        $items = DB::table('hr_payslip_items')->whereIn('payslip_id', DB::table('hr_payslips')->where('payroll_run_id', $run)->select('id'))->orderBy('id')->get();
        $item = $items->where('source_type', 'salary_assignment')->sole();
        $center = DB::table('hr_department_cost_center_defaults')->where('company_id', $f['company']->id)->where('department_id', $f['employee']->department_id)->value('cost_center_id');
        $centerNumber = DB::table('cost_centers')->where('id', $center)->value('doc_num');
        app(PayrollCostAllocationService::class)->syncAllocations($item->id, [['cost_center_doc_num' => $centerNumber, 'percentage' => '100', 'allocation_type' => 'direct']]);
        DB::table('hr_payroll_cost_allocations')->where('payslip_item_id', $item->id)->update(['amount' => '3333.3300']);
        app(DefaultLoginContextService::class)->update($actor, ['company_doc_num' => $f['company']->doc_num, 'branch_doc_num' => $f['branch']->doc_num, 'financial_period_doc_num' => $f['period']->doc_num]);

        return ['synthetic' => true, 'company_id' => $f['company']->id, 'actor_id' => $actor->id, 'username' => $actor->username,
            'run_id' => $run, 'item_id' => $item->id, 'items_before' => $items->all(),
            'slips_before' => DB::table('hr_payslips')->where('payroll_run_id', $run)->orderBy('id')->get()->all(), 'context' => payrollFinancialContext($f)];
    });
    file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    chmod($path, 0600);
});

test('real browser allocation repair preserves entitlement sources and exact fractional cost', function (): void {
    if (getenv('MGYPACK_PAYROLL_ALLOCATION_BROWSER_VERIFY') !== '1') {
        $this->markTestSkipped('Explicit browser result verification only.');
    }
    $manifest = json_decode(file_get_contents('/tmp/mgypack-payroll-allocation-runtime-20261003.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['synthetic'])->toBeTrue()->and(Company::findOrFail($manifest['company_id'])->name)->toContain('SYNTHETIC');
    expect(DB::table('hr_payslip_items')->whereIn('payslip_id', DB::table('hr_payslips')->where('payroll_run_id', $manifest['run_id'])->select('id'))->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())
        ->toBe($manifest['items_before'])
        ->and(DB::table('hr_payslips')->where('payroll_run_id', $manifest['run_id'])->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($manifest['slips_before'])
        ->and(DB::table('hr_payroll_cost_allocations')->where('payslip_item_id', $manifest['item_id'])->sole()->amount)->toBe('3333.3333')
        ->and(app(PayrollCostAllocationService::class)->previewRun($manifest['run_id'])['errors'])->toBe([])
        ->and(DB::table('hr_payroll_runs')->where('id', $manifest['run_id'])->value('status'))->toBe('calculated');
});
