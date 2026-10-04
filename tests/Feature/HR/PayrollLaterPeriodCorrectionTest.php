<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\HR\Services\PayrollCorrectionService;

require_once dirname(__DIR__, 2).'/PayrollLaterPeriodCorrectionCases.php';

test('payroll correction mode rollback retains prepared approval evidence', function (): void {
    $fixture = payrollLaterPeriodFixture();
    $actor = payrollFinancialActor(['hr.payroll_approval.correct', 'hr.payroll_approval.correct_later_period']);
    $this->actingAs($actor)->withSession(payrollFinancialContext($fixture));
    $runId = payrollCorrectionPostedRun($fixture);
    $fixture['period']->update(['is_closed' => true]);
    $this->withSession(payrollLaterPeriodContext($fixture))->get(route('admin.hr.payroll-runs.corrections.index', $runId))->assertOk();
    $service = app(PayrollCorrectionService::class);
    $proposal = $service->propose($runId, '2026-10-01', 'SYNTHETIC migration evidence', $service->preview($runId)['fingerprint'], 'later_period');
    $migration = require base_path('modules/HR/Database/Migrations/2026_10_03_143436_add_later_period_mode_to_payroll_corrections.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class)
        ->and(DB::table('hr_payroll_corrections')->where('id', $proposal->id)->value('correction_mode'))->toBe('later_period');
});

test('payroll correction mode migration preserves a column owned before its installation', function (): void {
    $migration = require base_path('modules/HR/Database/Migrations/2026_10_03_143436_add_later_period_mode_to_payroll_corrections.php');
    $migration->down();
    expect(Schema::hasColumn('hr_payroll_corrections', 'correction_mode'))->toBeFalse();
    Schema::table('hr_payroll_corrections', function (Blueprint $table): void {
        $table->string('correction_mode', 30)->nullable();
    });
    $migration->up();
    expect((bool) DB::table('hr_payroll_posting_column_ownership')->where('column_name', 'hr_payroll_corrections.correction_mode')->value('created_by_migration'))->toBeFalse();
    $migration->down();
    expect(Schema::hasColumn('hr_payroll_corrections', 'correction_mode'))->toBeTrue();
    $migration->up();
});
