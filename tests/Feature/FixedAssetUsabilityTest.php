<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\ArchiveFileService;
use Modules\Core\Services\ArchiveFolderService;
use Modules\FixedAssets\Services\FixedAssetDepreciationService;
use Modules\FixedAssets\Services\FixedAssetReportService;
use Modules\FixedAssets\Services\FixedAssetService;
use Modules\HR\Models\HrEmployee;

uses(RefreshDatabase::class);
require_once dirname(__DIR__).'/FixedAssetCycleSupport.php';

beforeEach(function (): void {
    coreFixedAssetActor(['fixed_assets.view', 'fixed_assets.view_trashed', 'fixed_assets.create', 'fixed_assets.edit', 'fixed_assets.delete', 'fixed_assets.restore', 'fixed_assets.activate', 'fixed_assets.custody.post', 'fixed_assets.dispose', 'fixed_assets.depreciation.preview', 'fixed_assets.depreciation.post', 'fixed_assets.reports', 'fixed_assets.print', 'fixed_assets.export', 'file_manager.view', 'file_manager.upload', 'file_manager.download']);
});

test('custody uses scoped paginated employees and supports assign transfer return without no ops', function (): void {
    $context = coreFixedAssetContext();
    $asset = coreRecognizedAsset($context);
    $employees = collect([1, 2])->map(fn ($number) => HrEmployee::query()->create(['company_id' => $asset->company_id, 'branch_id' => $asset->branch_id, 'doc_num' => 'EMP-9900'.$number, 'doc_number' => 99000 + $number, 'full_name' => 'Custodian '.$number, 'name' => 'Custodian '.$number, 'status' => 'active']));
    $response = $this->getJson(route('admin.fixed-assets.select2.custodians', ['q' => 'Custodian', 'page' => 1]))->assertOk()->assertJsonStructure(['results', 'pagination' => ['more']]);
    expect(collect($response->json('results'))->pluck('id')->all())->toBe($employees->pluck('doc_num')->all());
    $show = $this->get(route('admin.fixed-assets.assets.show', $asset))->assertOk();
    $show->assertSee('js-custody-employee')->assertDontSee('<section class="tab-pane', false)->assertDontSee('<option value="return">', false);
    config(['select2.pagination.per_page' => 1]);
    $page = $this->getJson(route('admin.fixed-assets.select2.custodians', ['q' => 'Custodian', 'page' => 1]))->assertOk()->assertJsonPath('pagination.more', true);
    expect($page->json('results'))->toHaveCount(1);
    $this->getJson(route('admin.fixed-assets.select2.custodians', ['q' => 'Custodian', 'page' => 2]))->assertOk()->assertJsonPath('pagination.more', false)->assertJsonPath('results.0.id', $employees->last()->doc_num);
    $data = ['movement_date' => $asset->operation_date->toDateString(), 'reason' => 'Custody acceptance', 'custody_action' => 'assign'];
    $url = route('admin.fixed-assets.movements.custody', $asset);
    $this->postJson($url, $data)->assertUnprocessable();
    foreach ($employees as $employee) {
        $this->post($url, [...$data, 'custodian_doc_num' => $employee->doc_num])->assertSessionHasNoErrors();
        $this->post($url, [...$data, 'custodian_doc_num' => $employee->doc_num])->assertSessionHasErrors();
    }
    $this->get(route('admin.fixed-assets.assets.show', $asset))->assertSee('<option value="return">', false);
    $this->post($url, [...$data, 'custody_action' => 'return'])->assertSessionHasNoErrors();
    $this->post($url, [...$data, 'custody_action' => 'return'])->assertSessionHasErrors();
    $history = $asset->movements()->where('movement_type', 'custody')->get();
    expect($history)->toHaveCount(3)->and($history->first()->destination_custodian_id)->toBeNull();
    $this->get(route('admin.fixed-assets.assets.show', $asset))->assertSee('Custodian 1')->assertSee('Custodian 2')->assertDontSee('fixed_assets.post.success');
});

test('depreciation missing period provides a usable action and informational exclusions stay collapsed', function (): void {
    $context = coreFixedAssetContext();
    $asset = coreRecognizedAsset($context);
    $land = coreFixedAsset($context, ['is_depreciable' => false]);
    $data = ['financial_period_doc_num' => $context['period']->doc_num, 'posting_date' => $context['period']->from_date->copy()->addMonth()->endOfMonth()->toDateString(), 'asset_doc_nums' => [$asset->doc_num, $land->doc_num]];
    $preview = app(FixedAssetDepreciationService::class)->preview($data);
    $row = collect($preview['excluded'])->firstWhere('asset.id', $asset->id);
    expect($row['actionable'])->toBeTrue()->and($row['next_date'])->toBe($context['period']->from_date->copy()->endOfMonth()->toDateString());
    $this->post(route('admin.fixed-assets.depreciation.preview'), $data)->assertOk()
        ->assertSee(__('fixed_assets.usability.run_required_month', ['period' => $context['period']->from_date->locale(app()->getLocale())->translatedFormat('F Y')]))
        ->assertSee('card mb-3 border-warning', false)
        ->assertSee('card mb-3 border-danger', false)
        ->assertSee('card-header bg-warning-subtle text-warning', false)
        ->assertSee('card-header bg-danger-subtle text-danger', false)
        ->assertSee('<td class="text-danger">', false)
        ->assertSee('data-confirm-title="'.__('fixed_assets.lifecycle.confirm_depreciation_title').'"', false);
    expect(app(FixedAssetDepreciationService::class)->readiness($asset->fresh()))->toBe(__('fixed_assets.usability.ready'));
    corePostMonth($context, $asset);
    $this->get(route('admin.fixed-assets.assets.show', $asset))->assertOk()->assertSee(__('fixed_assets.depreciation.success'))->assertDontSee('fixed_assets.depreciation.success');
});

test('opening a depreciation preview URL directly returns to the run screen', function (): void {
    $this->get('/admin/fixed-assets/depreciation/preview')
        ->assertRedirect(route('admin.fixed-assets.depreciation.index'));
});

test('depreciation screen defaults to the current month end inside the selected financial period', function (): void {
    $context = coreFixedAssetContext();
    $today = $context['period']->from_date->copy()->addMonths(8)->addDays(6);
    Carbon::setTestNow($today);

    try {
        $this->get(route('admin.fixed-assets.depreciation.index'))
            ->assertOk()
            ->assertSee('name="posting_date"', false)
            ->assertSee('value="'.$today->copy()->endOfMonth()->min($context['period']->to_date)->toDateString().'"', false)
            ->assertSee('data-date-format="d/m/Y"', false)
            ->assertSee(__('fixed_assets.usability.depreciation_month_end'));
    } finally {
        Carbon::setTestNow();
    }
});

test('missing depreciation action opens the financial period that covers the required month', function (): void {
    $context = coreFixedAssetContext();
    $year = $context['period']->from_date->year;
    $context['period']->forceFill([
        'from_date' => "{$year}-07-01",
        'to_date' => "{$year}-12-31",
    ])->save();
    $firstHalf = FinancialPeriod::query()->create([
        'company_id' => $context['company']->getKey(),
        'doc_number' => (int) FinancialPeriod::query()->max('doc_number') + 1,
        'doc_num' => 'PERIOD-FIRST-HALF-'.$year,
        'name' => 'First half '.$year,
        'from_date' => "{$year}-01-01",
        'to_date' => "{$year}-06-30",
        'is_closed' => false,
    ]);
    $asset = coreRecognizedAsset($context, [
        'purchase_date' => "{$year}-01-10",
        'acquisition_date' => "{$year}-01-10",
        'operation_date' => "{$year}-01-10",
        'depreciation_start_date' => "{$year}-01-10",
    ]);

    $response = $this->post(route('admin.fixed-assets.depreciation.preview'), [
        'financial_period_doc_num' => $context['period']->doc_num,
        'posting_date' => "{$year}-09-30",
        'asset_doc_nums' => [$asset->doc_num],
    ])->assertOk();

    $response->assertSee('name="financial_period_doc_num" value="'.$firstHalf->doc_num.'"', false)
        ->assertSee('name="posting_date" value="'.$year.'-01-31"', false);

    $this->post(route('admin.fixed-assets.depreciation.preview'), [
        'financial_period_doc_num' => $firstHalf->doc_num,
        'posting_date' => "{$year}-01-31",
        'asset_doc_nums' => [$asset->doc_num],
    ])->assertOk()->assertSee('data-asset="'.$asset->doc_num.'"', false);
});

test('asset movements render with bootstrap pagination and a responsive mobile history', function (): void {
    $context = coreFixedAssetContext();
    foreach (range(1, 26) as $number) {
        coreFixedAsset($context, ['asset_name' => 'Movement list asset '.$number]);
    }

    $this->get(route('admin.fixed-assets.movements.index'))
        ->assertOk()
        ->assertSee(__('fixed_assets.product.movements_help'))
        ->assertSee('window.fixedAssetsMessages', false)
        ->assertSee('select2Search', false)
        ->assertSee(json_encode(__('fixed_assets.js.select2Search')), false)
        ->assertSee('data-placeholder="'.__('fixed_assets.placeholders.asset').'"', false)
        ->assertSee('data-placeholder="'.__('fixed_assets.placeholders.branch').'"', false)
        ->assertSee(__('fixed_assets.product.pagination_summary', ['from' => 1, 'to' => 25, 'total' => 26]))
        ->assertSee('d-none d-lg-block', false)
        ->assertSee('<article class="p-3 border-bottom', false)
        ->assertSee('class="page-link"', false)
        ->assertSee(__('pagination.next'))
        ->assertDontSee('pagination.previous')
        ->assertDontSee('pagination.next')
        ->assertDontSee('<svg', false);
});

test('fixed asset select2 controls render localized placeholders in English as well', function (): void {
    coreFixedAssetContext();
    auth()->user()->forceFill(['locale' => 'en'])->save();
    app()->setLocale('en');

    $this->withSession(['locale' => 'en'])->get(route('admin.fixed-assets.movements.index'))
        ->assertOk()
        ->assertSee('select2Search', false)
        ->assertSee(json_encode('Search'), false)
        ->assertSee('data-placeholder="Select Asset"', false)
        ->assertSee('data-placeholder="Select Branch"', false);
});

test('asset card keeps compact responsive attachment zones in the context of every financial document', function (): void {
    $context = coreFixedAssetContext();
    $asset = coreRecognizedAsset($context);
    $run = corePostMonth($context, $asset);
    Storage::fake('local');
    config()->set('archive.disk', 'local');
    $file = app(ArchiveFileService::class)->upload(
        files: [UploadedFile::fake()->create('asset-warranty.pdf', 20, 'application/pdf')],
        attachable: $context['company'],
        module: 'core',
        recordType: 'company',
        recordDocNum: $context['company']->doc_num,
        folder: app(ArchiveFolderService::class)->generalRoot(),
    )[0];

    $movement = $asset->costMovements()->firstOrFail();
    $url = route('admin.fixed-assets.movements.document', $asset);
    $this->post($url, ['archive_file_doc_num' => $file->doc_num, 'document_target' => 'movement|'.$movement->doc_num])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $this->post($url, ['archive_file_doc_num' => $file->doc_num, 'document_target' => 'depreciation|'.$run->doc_num])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($movement->archiveFileUsages()->count())->toBe(1)
        ->and($asset->depreciations()->firstOrFail()->archiveFileUsages()->count())->toBe(1);

    $this->get(route('admin.fixed-assets.assets.show', $asset))
        ->assertOk()
        ->assertSee('id="asset-attachments"', false)
        ->assertSee('class="fa-attachment-zone m-3 p-3"', false)
        ->assertSee('data-attachment-target="movement|'.$movement->doc_num.'"', false)
        ->assertSee('value="depreciation|'.$run->doc_num.'"', false)
        ->assertSee('asset-warranty.pdf')
        ->assertSee(route('admin.file-manager.files.preview', $file), false)
        ->assertDontSee('fixed_assets.product.', false);

    $this->get(route('admin.fixed-assets.assets.show', [$asset, 'tab' => 'documents', 'attachment_target' => 'movement|'.$movement->doc_num]))
        ->assertOk()
        ->assertSee('class="nav-link  active " id="documents-tab"', false)
        ->assertSee('value="movement|'.$movement->doc_num.'" selected', false)
        ->assertSee('<section class="p-0" aria-labelledby="movement-document-heading">', false);

    $this->post($url, ['archive_file_doc_num' => $file->doc_num, 'document_target' => 'asset|WRONG'])
        ->assertSessionHasErrors('document_target');
});

test('asset register explains the next step and prioritizes operational columns', function (): void {
    coreFixedAssetContext();

    $response = $this->get(route('admin.fixed-assets.assets.index'))
        ->assertOk()
        ->assertSee(__('fixed_assets.product.register_help'))
        ->assertSee(__('fixed_assets.product.open_movements'))
        ->assertSee(__('fixed_assets.product.register_filters_help'));

    expect($response->getContent())->toContain('["doc_num","asset_name","asset_category","branch","status"');
});

test('fixed asset reports expose real workflows with summaries totals and mobile rows', function (): void {
    $context = coreFixedAssetContext();
    $asset = coreRecognizedAsset($context);

    expect(FixedAssetReportService::visibleTypes())
        ->toContain('transfers', 'custody', FixedAssetReportService::FullyDepreciated)
        ->not->toContain('by_branch', 'by_location', 'by_cost_center', 'by_category');

    $reports = app(FixedAssetReportService::class);
    expect($reports->report(['type' => 'net_book_value'])['totals'])
        ->toHaveKeys([
            __('fixed_assets.pdf.totals.asset_cost'),
            __('fixed_assets.pdf.totals.accumulated_depreciation'),
            __('fixed_assets.pdf.totals.net_book_value'),
        ])
        ->and($reports->report(['type' => 'capital_additions'])['totals'])
        ->toHaveKey(__('fixed_assets.pdf.totals.additions'))
        ->and($reports->report(['type' => 'disposals'])['totals'])
        ->toHaveKeys([
            __('fixed_assets.pdf.totals.disposal_expenses'),
            __('fixed_assets.pdf.totals.net_proceeds'),
            __('fixed_assets.pdf.totals.gains'),
            __('fixed_assets.pdf.totals.losses'),
        ]);

    $this->get(route('admin.fixed-assets.reports.index', ['type' => 'net_book_value']))
        ->assertOk()
        ->assertSee(__('fixed_assets.reports.help'))
        ->assertSee(__('fixed_assets.reports.descriptions.net_book_value'))
        ->assertSee(__('fixed_assets.reports.types.transfers'))
        ->assertSee(__('fixed_assets.reports.types.custody'))
        ->assertSee(__('fixed_assets.reports.types.fully_depreciated'))
        ->assertSee(__('fixed_assets.pdf.filters.posting_status'))
        ->assertSee(route('admin.fixed-assets.assets.show', $asset), false)
        ->assertSee('fa-report-mobile-row', false)
        ->assertDontSee('fixed_assets.reports.descriptions.', false);
});

test('depreciation screen keeps accessible recent runs in the operating scope', function (): void {
    $context = coreFixedAssetContext();
    $run = corePostMonth($context, coreRecognizedAsset($context));

    $this->get(route('admin.fixed-assets.depreciation.index'))
        ->assertOk()
        ->assertSee(__('fixed_assets.lifecycle.recent_runs'))
        ->assertSee($run->doc_num)
        ->assertSee(__('fixed_assets.lifecycle.open_run'));
});

test('trashed assets remain restorable without being selectable for bulk deletion', function (): void {
    $context = coreFixedAssetContext();
    $asset = coreFixedAsset($context, ['asset_name' => 'Restorable asset']);
    app(FixedAssetService::class)->delete($asset);

    $response = $this->getJson(route('admin.fixed-assets.assets.data', [
        'draw' => 1,
        'trash_filter' => 'trashed',
        'columns' => [
            ['data' => 'checkbox', 'name' => 'checkbox', 'searchable' => 'false', 'orderable' => 'false', 'search' => ['value' => null, 'regex' => 'false']],
            ['data' => 'doc_num', 'name' => 'fixed_assets.doc_number', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => null, 'regex' => 'false']],
        ],
        'order' => [['column' => 1, 'dir' => 'desc']],
        'start' => 0,
        'length' => 10,
        'search' => ['value' => '', 'regex' => 'false'],
    ]))->assertOk();

    expect($response->json('data.0.checkbox'))->toBe('')
        ->and($response->json('data.0.actions'))->toContain(__('common.actions.restore'));
});

test('asset register status badges use the domain state colors', function (): void {
    $context = coreFixedAssetContext();
    $assets = collect([
        ['draft', 'warning'],
        ['active', 'success'],
        ['suspended', 'warning'],
        ['fully_depreciated', 'info'],
        ['disposed', 'danger'],
    ])->mapWithKeys(function (array $state) use ($context): array {
        $asset = coreFixedAsset($context, ['status' => $state[0], 'asset_name' => 'Tone '.$state[0]]);

        return [$asset->doc_num => $state[1]];
    });

    $response = $this->getJson(route('admin.fixed-assets.assets.data', [
        'draw' => 1,
        'columns' => [
            ['data' => 'checkbox', 'name' => 'checkbox', 'searchable' => 'false', 'orderable' => 'false', 'search' => ['value' => null, 'regex' => 'false']],
            ['data' => 'doc_num', 'name' => 'fixed_assets.doc_number', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => null, 'regex' => 'false']],
        ],
        'order' => [['column' => 1, 'dir' => 'desc']],
        'start' => 0,
        'length' => 20,
        'search' => ['value' => '', 'regex' => 'false'],
    ]))->assertOk();

    foreach ($assets as $docNum => $tone) {
        $row = collect($response->json('data'))->first(fn (array $record): bool => str_contains($record['doc_num'], $docNum));
        expect($row)->not->toBeNull()
            ->and($row['status'])->toContain('badge-subtle-'.$tone);
    }
});

test('fixed asset Arabic and English translation contracts stay aligned', function (): void {
    $flattenKeys = function (array $translations, string $prefix = '') use (&$flattenKeys): array {
        $keys = [];
        foreach ($translations as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $keys = [...$keys, ...(is_array($value) ? $flattenKeys($value, $path) : [$path])];
        }

        sort($keys);

        return $keys;
    };

    expect($flattenKeys(require resource_path('lang/ar/fixed_assets.php')))
        ->toBe($flattenKeys(require resource_path('lang/en/fixed_assets.php')));
});

test('disposal form separates direct settlement invoice and expense fields', function (): void {
    $context = coreFixedAssetContext();
    $asset = coreRecognizedAsset($context);

    $this->get(route('admin.fixed-assets.assets.show', $asset))
        ->assertOk()
        ->assertSee('data-disposal-field="direct"', false)
        ->assertSee('data-disposal-field="invoice"', false)
        ->assertSee('data-disposal-field="expenses"', false)
        ->assertSee('name="customer_doc_num"', false)
        ->assertDontSee(__('A Customer is required for an invoiced Fixed Asset sale.'));
});
