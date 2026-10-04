<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\OverheadAllocationRun;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Accounting\Services\OverheadAllocationService;
use Modules\Auth\Services\DefaultLoginContextService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\HR\Models\HrEmployee;
use Modules\Production\Models\ProductionRun;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/InventoryCostTransitionSupport.php';
require_once __DIR__.'/PayrollFinancialSupport.php';
require_once __DIR__.'/ClosurePostgresRaceSupport.php';

beforeEach(function (): void {
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBe('mgypack_acceptance_closure_20261003')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
});

test('canonical allocation serializes with source reversal and newly eligible production progress on PostgreSQL', function (): void {
    config()->set('erp.phase_mode', 'expanded');
    $fixture = costTransitionFixture('-SYNTHETIC-COST-RACE-'.Str::random(10), isolatedCompany: true);
    $fixture['branch']->update(['type' => Branch::TypeFactory]);
    $center = CostCenter::query()->create(['company_id' => $fixture['company']->id,
        'doc_number' => (int) CostCenter::withTrashed()->max('doc_number') + 1,
        'doc_num' => 'SYNTHETIC-COST-RACE-CC-'.$fixture['company']->id, 'cost_center_code' => 'RACE',
        'name' => 'SYNTHETIC overhead race center', 'is_group' => false, 'status' => 'active']);
    $account = Account::query()->where('company_id', $fixture['company']->id)->where('account_code', '523')->firstOrFail();
    $cash = Account::query()->where('company_id', $fixture['company']->id)->where('account_code', '111')->firstOrFail();
    $currency = Currency::query()->where('company_id', $fixture['company']->id)->where('is_main', true)->firstOrFail();
    $center->accounts()->sync([$account->id]);
    $fixture['cost_center'] = $center;
    $run = payrollManufacturingRun($fixture, $fixture['product'], $fixture['unit'], (int) ProductionRun::max('id') + 1, []);
    $sourceHeader = ['entry_date' => '2026-09-30', 'company_id' => $fixture['company']->id,
        'financial_period_id' => $fixture['period']->id, 'branch_id' => $fixture['branch']->id,
        'currency_id' => $currency->id, 'exchange_rate' => '1', 'description' => 'SYNTHETIC paid overhead race',
        'source_type' => 'synthetic_cost_race', 'source_id' => $run->id, 'source_doc_num' => 'SYNTHETIC-COST-RACE'];
    $makeSource = fn (array $header): JournalEntry => app(JournalEntryService::class)->createPostedFromSource($header, [
        ['account_id' => $account->id, 'cost_center_id' => $center->id, 'branch_id' => $fixture['branch']->id,
            'description' => 'SYNTHETIC eligible overhead', 'debit_amount' => '300', 'credit_amount' => '0'],
        ['account_id' => $cash->id, 'description' => 'SYNTHETIC payment', 'debit_amount' => '0', 'credit_amount' => '300'],
    ]);
    $source = $makeSource($sourceHeader);
    $service = app(OverheadAllocationService::class);
    $rule = $service->createRule(['name' => 'SYNTHETIC concurrent allocation', 'source_cost_center_id' => $center->id,
        'source_account_ids' => [$account->id], 'target_cost_center_ids' => [$center->id], 'basis' => 'machine_hours',
        'cost_behavior' => 'variable', 'effective_from' => $fixture['period']->from_date->toDateString()], $fixture['company']->id, $fixture['branch']->id);
    $from = $fixture['period']->from_date->toDateString();
    $to = $fixture['period']->to_date->toDateString();
    $allocation = $service->preview($rule, $fixture['period'], $fixture['branch']->id, $from, $to);
    $reverseHeader = [...$sourceHeader, 'source_type' => 'synthetic_cost_race_reversal', 'source_id' => $source->id];
    $results = closurePostgresRace([
        ['operation' => 'cost-allocation-approve', 'allocation' => $allocation->id, 'user' => $fixture['preparer']->id],
        ['operation' => 'cost-source-reverse', 'journal' => $source->id, 'header' => $reverseHeader, 'user' => $fixture['preparer']->id],
    ]);
    expect(collect($results)->pluck('result')->sort()->values()->all())->toBe(['applied', 'blocked']);
    $allocation->refresh();
    $approvedFirst = $allocation->status === OverheadAllocationRun::StatusPosted;
    expect($source->fresh()->reversed_entry_id === null)->toBe($approvedFirst)
        ->and($allocation->journal_entry_id !== null)->toBe($approvedFirst);
    if ($approvedFirst) {
        $service->reverse($allocation, 'SYNTHETIC release source after race');
        app(JournalEntryService::class)->createPostedReversalFromSource($source, $reverseHeader);
    }
    $nextSource = $makeSource([...$sourceHeader, 'source_id' => $run->id + 100000]);
    $candidate = payrollManufacturingRun($fixture, $fixture['product'], $fixture['unit'], (int) ProductionRun::max('id') + 1, []);
    $candidate->update(['good_base_quantity' => '0']);
    $candidate->progressEntries()->update(['good_base_quantity' => '0']);
    $preview = $service->preview($rule, $fixture['period'], $fixture['branch']->id, $from, $to);
    expect($preview->lines)->toHaveCount(1)->and($preview->sources->sole()->journalEntryLine->journal_entry_id)->toBe($nextSource->id);
    $results = closurePostgresRace([
        ['operation' => 'cost-allocation-approve', 'allocation' => $preview->id, 'user' => $fixture['preparer']->id],
        ['operation' => 'production-progress', 'run' => $candidate->id, 'user' => $fixture['preparer']->id],
    ]);
    expect(collect($results)->firstWhere('operation', 'production-progress')['result'])->toBe('applied')
        ->and($candidate->fresh()->good_base_quantity)->toBe('100.00000000');
    $preview->refresh();
    if ($preview->status === OverheadAllocationRun::StatusPosted) {
        expect($preview->lines()->sole()->allocated_amount)->toBe('300.0000');
        $service->reverse($preview, 'SYNTHETIC reallocate after later progress');
    } else {
        expect($preview->status)->toBe(OverheadAllocationRun::StatusDraft)->and($preview->journal_entry_id)->toBeNull();
    }
    $fresh = $service->preview($rule, $fixture['period'], $fixture['branch']->id, $from, $to);
    $posted = $service->approve($fresh);
    file_put_contents('/tmp/mgypack-cost-labor-runtime-20261003.json', json_encode([
        'user' => $fixture['preparer']->id, 'username' => $fixture['preparer']->username,
        'company' => $fixture['company']->id, 'branch' => $fixture['branch']->id, 'period' => $fixture['period']->id,
        'run' => $run->public_id, 'run_id' => $run->id, 'candidate' => $candidate->public_id,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    expect($posted->allocated_cost)->toBe('300.0000')->and($posted->lines)->toHaveCount(2)
        ->and($posted->lines->every(fn ($line): bool => bccomp($line->allocated_amount, '0', 4) > 0))->toBeTrue()
        ->and(OverheadAllocationRun::query()->where('company_id', $fixture['company']->id)->where('status', 'posted')->count())->toBe(1);
});

test('prepare isolated daily labor browser actor and starting employee without customer records', function (): void {
    if (getenv('MGYPACK_LABOR_RUNTIME_FIXTURE') !== '1') {
        $this->markTestSkipped('Opt-in explicitly synthetic runtime preparation only.');
    }
    $path = '/tmp/mgypack-cost-labor-runtime-20261003.json';
    $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $run = ProductionRun::query()->findOrFail($manifest['run_id']);
    expect($run->order->company->name)->toContain('SYNTHETIC');
    $user = User::query()->findOrFail($manifest['user']);
    foreach (['production.runs.view', 'production.runs.labor'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }
    app(DefaultLoginContextService::class)->update($user, ['company_doc_num' => $run->order->company->doc_num,
        'branch_doc_num' => $run->order->branch->doc_num, 'financial_period_doc_num' => FinancialPeriod::findOrFail($run->financial_period_id)->doc_num]);
    if (empty($manifest['employee'])) {
        $number = max(7800, (int) HrEmployee::withTrashed()->max('doc_number') + 1);
        $employee = HrEmployee::query()->create(['doc_number' => $number, 'doc_num' => 'SYNTHETIC-LABOR-RUNTIME-'.$number,
            'employee_code' => 'SYNTHETIC-LABOR-RUNTIME-'.$number, 'full_name' => 'SYNTHETIC daily labor worker',
            'name' => 'SYNTHETIC daily labor worker', 'person_type' => 'regular_labor', 'status' => 'active',
            'company_id' => $run->company_id, 'branch_id' => $run->branch_id, 'pay_basis' => 'hourly_rate', 'hourly_wage' => '10']);
        $manifest['employee'] = $employee->id;
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
    expect(HrEmployee::query()->findOrFail($manifest['employee'])->full_name)->toContain('SYNTHETIC');
});

test('verify saved browser labor days and both scoped AJAX worker identifier contracts', function (): void {
    if (getenv('MGYPACK_LABOR_RUNTIME_VERIFY') !== '1') {
        $this->markTestSkipped('Opt-in verification of the saved synthetic browser workflow.');
    }
    $manifest = json_decode(file_get_contents('/tmp/mgypack-daily-labor-runtime-saved-20261003.json'), true, flags: JSON_THROW_ON_ERROR);
    $run = ProductionRun::query()->findOrFail($manifest['run_id']);
    $employee = HrEmployee::query()->findOrFail($manifest['employee']);
    $user = User::query()->findOrFail($manifest['user']);
    expect($run->order->company->name)->toContain('SYNTHETIC')
        ->and($run->actual_labor_count)->toBe(1)->and($run->updated_by)->toBe($user->id)
        ->and($run->labor_details)->toHaveCount(1);
    $worker = $run->labor_details[0];
    expect((int) $worker['employee_id'])->toBe($employee->id)
        ->and(bcadd($worker['actual_hours'], '0', 8))->toBe('5.00000000')
        ->and(collect($worker['work_segments'])->pluck('work_date')->all())->toBe(['2026-09-10', '2026-09-11'])
        ->and(collect($worker['work_segments'])->pluck('actual_hours')->map(fn ($hours) => bcadd($hours, '0', 8))->all())->toBe(['2.00000000', '3.00000000']);
    $lookupActor = closureSyntheticUser();
    foreach (['production.runs.view', 'production.runs.labor'] as $permission) {
        $lookupActor->givePermissionTo($permission);
    }
    $this->actingAs($lookupActor)->withSession(costTransitionSession([
        'company' => $run->order->company, 'branch' => $run->order->branch,
        'period' => FinancialPeriod::findOrFail($run->financial_period_id),
    ]));
    foreach (['id' => (string) $employee->id, 'doc_num' => $employee->doc_num] as $identifier => $expected) {
        $this->getJson(route('admin.production.runs.select2.workers', ['q' => $employee->doc_num, 'identifier' => $identifier]))
            ->assertOk()->assertJsonPath('results.0.id', $expected)->assertJsonPath('pagination.more', false);
    }
    $this->getJson(route('admin.production.runs.select2.workers', ['q' => 'not-present-SYNTHETIC-worker']))
        ->assertOk()->assertJsonPath('results', [])->assertJsonPath('pagination.more', false);
});
