<?php

use Carbon\Carbon;
use Illuminate\Support\Str;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Modules\Production\Services\ProductionRunCorrectionService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/ManufacturingInventorySupport.php';

/** @return array<string, mixed> */
function productionLaterPeriodFixture(bool $isolatedCompany = true, string $postingDate = '2026-10-04'): array
{
    test()->travelTo(Carbon::parse('2026-09-30 12:00:00'));
    $fixture = productionCorrectionCompletedFixture(true, '-SYNTHETIC-LATER-'.Str::random(8), isolatedCompany: $isolatedCompany);
    $fixture['period']->update(['to_date' => '2026-09-30', 'is_closed' => true]);
    $target = FinancialPeriod::query()->create([
        'company_id' => $fixture['company']->id, 'doc_number' => 99773, 'doc_num' => 'SYNTHETIC-PRODUCTION-OCT',
        'name' => 'SYNTHETIC October production correction', 'from_date' => '2026-10-01', 'to_date' => '2026-12-31', 'is_closed' => false,
    ]);
    $permissions = ['production.runs.correct_later_period', 'production.runs.account_materials', 'production.runs.receive',
        'production.runs.complete', 'production.runs.labor', 'production.runs.print'];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
        $fixture['approver']->givePermissionTo($permission);
    }
    test()->travelTo(Carbon::parse($postingDate.' 12:00:00'));
    $session = [...manufacturingIntegritySession($fixture), OperatingContextService::FinancialPeriodIdKey => $target->id,
        OperatingContextService::FinancialPeriodDocNumKey => $target->doc_num];
    test()->actingAs($fixture['user'])->withSession($session);
    $fixture['run']->refresh();
    $preview = app(ProductionRunCorrectionService::class)->preview($fixture['run']);
    $payload = ['fingerprint' => $preview['fingerprint'], 'reason' => 'SYNTHETIC closed production quantity and waste correction',
        'posting_date' => $postingDate, 'correction_mode' => 'later_period',
        'output' => ['good_base_quantity' => '9', 'rejected_base_quantity' => '1', 'rework_base_quantity' => '0', 'scrap_base_quantity' => '0']];

    return [...$fixture, 'target' => $target, 'session' => $session, 'payload' => $payload];
}
