<?php

use Illuminate\Support\Facades\DB;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Inventory\Exports\InventoryPeriodicCostCloseExport;
use Modules\Inventory\Services\InventoryStandardCostReport;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

require_once __DIR__.'/../InventoryStandardCostSupport.php';

test('standard UI and Arabic English frozen exports share exact eight digit component costs and independently enforce output rights', function (): void {
    $fixture = standardCostFixture();
    $registry = app(PermissionRegistryService::class);
    foreach (['view', 'prepare', 'settle', 'approve', 'export', 'print'] as $action) {
        expect($registry->formAssignablePermissions())->toContain('inventory.cost_policies.standard.'.$action);
    }
    app()->setLocale('ar');
    $groups = json_encode($registry->groupedForForm(['inventory.cost_policies.standard.view', 'inventory.cost_policies.standard.prepare',
        'inventory.cost_policies.standard.settle', 'inventory.cost_policies.standard.approve', 'inventory.cost_policies.standard.export', 'inventory.cost_policies.standard.print']), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    expect($groups)->toContain('التكلفة المعيارية', 'inventory.cost_policies.standard.approve');
    $standard = prepareSyntheticStandard($fixture);
    $session = manufacturingIntegritySession($fixture);
    $this->actingAs($fixture['preparer'])->withSession($session);
    $url = route('admin.inventory.standard-costs.show', ['kind' => 'versions', 'uuid' => $standard->public_uuid]);
    $this->get(route('admin.inventory.standard-costs.index'))->assertOk()->assertSee('js-date-picker', false)->assertDontSee('type="date"', false)
        ->assertSee('js-select2-ajax', false)->assertSee('novalidate', false);
    $this->get($url)->assertOk()->assertSee('3.20000000')->assertDontSee('std_approve', false);
    $this->getJson(route('admin.inventory.standard-costs.lookup', 'products').'?q='.$fixture['finished']->doc_num)->assertOk()
        ->assertJsonPath('results.0.id', (string) $fixture['finished']->id)->assertJsonPath('pagination.more', false);
    foreach (['xlsx', 'csv', 'pdf'] as $format) {
        $this->get(route('admin.inventory.standard-costs.output', ['kind' => 'versions', 'uuid' => $standard->public_uuid, 'format' => $format]))->assertForbidden();
    }
    $fixture['preparer']->givePermissionTo(['inventory.cost_policies.standard.export', 'inventory.cost_policies.standard.print']);
    foreach (['ar', 'en'] as $locale) {
        $fixture['preparer']->forceFill(['locale' => $locale])->save();
        $this->withSession([...$session, 'locale' => $locale]);
        app()->setLocale($locale);
        $sections = app(InventoryStandardCostReport::class)->sections($standard);
        $rows = (new InventoryPeriodicCostCloseExport($sections))->array();
        $this->get($url)->assertOk()->assertSee(__('inventory_standard_cost.title'))->assertSee(__('inventory_standard_cost.field'));
        $excel = $this->get(route('admin.inventory.standard-costs.output', ['kind' => 'versions', 'uuid' => $standard->public_uuid, 'format' => 'xlsx']))->assertOk();
        $workbook = IOFactory::load($excel->baseResponse->getFile()->getPathname());
        foreach ($rows as $index => $row) {
            foreach ($row as $column => $expected) {
                $cell = $workbook->getActiveSheet()->getCell([$column + 1, $index + 1]);
                expect($cell->getValue() ?? '')->toBe($expected)->and($cell->getDataType())->not->toBe(DataType::TYPE_FORMULA);
            }
        }
        $workbook->disconnectWorksheets();
        $csv = $this->get(route('admin.inventory.standard-costs.output', ['kind' => 'versions', 'uuid' => $standard->public_uuid, 'format' => 'csv']))->assertOk();
        $handle = fopen($csv->baseResponse->getFile()->getPathname(), 'r');
        try {
            foreach ($rows as $row) {
                $actual = fgetcsv($handle, separator: ',', enclosure: '"', escape: '');
                foreach ($row as $column => $expected) {
                    expect($actual[$column] ?? '')->toBe($expected);
                }
            }
        } finally {
            fclose($handle);
        }
        $pdf = $this->get(route('admin.inventory.standard-costs.output', ['kind' => 'versions', 'uuid' => $standard->public_uuid, 'format' => 'pdf']))->assertOk()->assertHeader('content-type', 'application/pdf');
        expect($pdf->getContent())->toStartWith('%PDF-');
        file_put_contents('/tmp/mgypack-standard-version-'.DB::getDriverName().'-'.$locale.'-20261003.pdf', $pdf->getContent());
    }
});
