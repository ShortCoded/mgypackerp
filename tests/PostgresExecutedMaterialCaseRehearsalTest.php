<?php

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryMovementCorrectionService;
use Modules\Production\Models\ProductionMaterialRequirement;
use Modules\Production\Models\ProductionRun;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function (): void {
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBe('mgypack_executed_case_pg_20261004_d8e41a')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(1);
    $this->travelTo('2026-10-04 12:00:00');
});

/** @return array<string, mixed> */
function executedMaterialCase(): array
{
    $document = InventoryDocument::query()->findOrFail(45);
    $line = $document->lines()->findOrFail(107);
    $run = ProductionRun::query()->findOrFail(14);
    $requirement = ProductionMaterialRequirement::query()->findOrFail(27);
    $alternate = Product::query()->findOrFail(77);
    expect($document->doc_num)->toBe('INV-MOV-00010')->and($document->status)->toBe('posted')
        ->and($line->product_id)->toBe(59)->and($line->quantity)->toBe('1.17600000')->and($line->total_cost)->toBe('109.36800000')
        ->and($run->run_number)->toBe('PROD-00011-R001')->and($run->status)->toBe('running')
        ->and($document->production_run_id)->toBe($run->id)->and($line->source_line_id)->toBe($requirement->id)
        ->and($requirement->product_id)->toBe($line->product_id)->and($requirement->consumed_quantity)->toBe('0.00000000')
        ->and($requirement->waste_quantity)->toBe('0.00000000')->and($run->received_base_quantity)->toBe('0.00000000')
        ->and($alternate->doc_num)->toBe('RAW-04020')->and($alternate->company_id)->toBe($document->company_id)
        ->and($alternate->item_unit_id)->toBe($line->unit_id)->and($alternate->item_classification)->toBe(Product::ClassificationRawMaterial);

    return compact('document', 'line', 'run', 'requirement', 'alternate');
}

/** @param array<string, mixed> $case */
function executedMaterialActor(array $case, int $id): void
{
    $company = Company::query()->findOrFail($case['document']->company_id);
    $branch = Branch::query()->findOrFail($case['document']->branch_id);
    $period = FinancialPeriod::query()->findOrFail($case['document']->financial_period_id);
    $user = User::query()->findOrFail($id);
    test()->actingAs($user)->withSession([
        OperatingContextService::CompanyIdKey => $company->id, OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->id, OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->id, OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ]);
    auth()->login($user);
    request()->setUserResolver(fn (): User => $user);
    request()->setLaravelSession(app('session.store'));
}

/** @param array<string, mixed> $case @return array<string, mixed> */
function executedMaterialMetrics(array $case): array
{
    $stock = InventoryTransaction::query()->where('company_id', $case['document']->company_id)
        ->where('branch_store_id', $case['document']->branch_store_id)->where('product_id', $case['line']->product_id)
        ->groupBy('stock_status')->orderBy('stock_status')->get(['stock_status', DB::raw('sum(quantity_in - quantity_out) as quantity'),
            DB::raw('sum(case when quantity_in > 0 then total_cost else -total_cost end) as value')])->keyBy('stock_status')
        ->map(fn ($row): array => ['quantity' => bcadd((string) $row->quantity, '0', 8), 'value' => bcadd((string) $row->value, '0', 8)])->all();

    return ['stock' => $stock, 'requirement' => $case['requirement']->fresh()->getAttributes(),
        'run' => $case['run']->fresh()->getAttributes(), 'order' => $case['run']->order->fresh()->getAttributes(),
        'original_line' => $case['line']->fresh()->getAttributes(),
        'original_transactions' => $case['document']->transactions()->orderBy('id')->get()->map->getAttributes()->all(),
        'original_journal' => DB::table('journal_entries')->where('id', 70)->first(),
        'original_journal_lines' => DB::table('journal_entry_lines')->where('journal_entry_id', 70)->orderBy('id')->get()->all(),
        'source_sales_order' => DB::table('sales_orders')->where('id', 22)->first(),
        'source_sales_lines' => DB::table('sales_order_lines')->where('sales_order_id', 22)->orderBy('id')->get()->all()];
}

/** @param array<string, mixed> $values */
function recordExecutedMaterialCase(array $values): void
{
    $file = '/tmp/mgypack-executed-case-rehearsal-20261004-d8e41a.json';
    $current = is_file($file) ? json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];
    file_put_contents($file, json_encode([...$current, ...$values], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    chmod($file, 0600);
}

test('the concrete issued production line rejects manual product replacement through its owner boundary', function (): void {
    $case = executedMaterialCase();
    executedMaterialActor($case, 18);
    expect(auth()->user()->can('inventory.documents.correct_prepare'))->toBeTrue();
    $before = executedMaterialMetrics($case);
    $preview = app(InventoryMovementCorrectionService::class)->preview($case['document']);
    expect($preview['item_correction_supported'])->toBeFalse();
    $payload = ['operation' => 'replace_items', 'source_fingerprint' => $preview['source_fingerprint'],
        'posting_date' => '2026-10-04', 'reason' => 'LOCAL REHEARSAL ONLY: hypothetical recording error; no assertion about physical goods',
        'lines' => $case['document']->lines->map(fn ($line): array => ['line_id' => $line->id,
            'product_doc_num' => $line->id === 107 ? $case['alternate']->doc_num : $line->product->doc_num,
            'unit_doc_num' => $line->unit->doc_num, 'quantity' => $line->quantity])->all()];
    foreach (['ar', 'en'] as $locale) {
        app()->setLocale($locale);
        $this->withSession(['locale' => $locale])->postJson(route('admin.inventory.documents.corrections.store', $case['document']), $payload)
            ->assertUnprocessable()->assertJsonPath('errors.correction.0', __('inventory_correction.source'));
    }
    expect(executedMaterialMetrics($case))->toEqual($before);
    recordExecutedMaterialCase(['scenario' => $payload['reason'], 'original_document' => 'INV-MOV-00010', 'original_line_id' => 107,
        'original_product' => 'RAW-04002', 'candidate_product' => 'RAW-04020',
        'candidate_compatibility' => 'Same company, active raw-material class and kilogram base unit; PP black masterbatch grade differs and is absent from this run BOM. Technical substitution is not established.',
        'manual_correction' => ['status' => 'blocked', 'http_status' => 422, 'message' => __('inventory_correction.source')],
        'before' => $before, 'after_blocked_attempt' => executedMaterialMetrics($case)]);
});

test('existing source return rehearses only the unconsumed issued quantity and preserves original documents', function (): void {
    $case = executedMaterialCase();
    executedMaterialActor($case, 1);
    expect(auth()->user()->can('production.runs.issue'))->toBeTrue();
    $before = executedMaterialMetrics($case);
    $response = $this->postJson(route('admin.production.runs.return', $case['run']), ['_submission_token' => (string) Str::uuid(),
        'branch_store_id' => $case['document']->branch_store_id, 'lines' => [['requirement_id' => 27, 'quantity' => '1.17600000']]]);
    recordExecutedMaterialCase(['return_response' => ['status' => $response->status(), 'body' => $response->json()]]);
    $response->assertOk();
    $return = InventoryDocument::query()->where('production_run_id', 14)->where('document_type', InventoryDocument::TypeMaterialReturn)->latest('id')->firstOrFail();
    $after = executedMaterialMetrics($case);
    expect($return->status)->toBe('posted')->and($return->lines()->sole()->product_id)->toBe(59)
        ->and($return->lines()->sole()->quantity)->toBe('1.17600000')->and($return->lines()->sole()->total_cost)->toBe('109.36800000')
        ->and($case['requirement']->fresh()->returned_quantity)->toBe('1.17600000')
        ->and(bcsub($after['stock']['available']['quantity'], $before['stock']['available']['quantity'], 8))->toBe('1.17600000')
        ->and(bcsub($after['stock']['production_staging']['quantity'], $before['stock']['production_staging']['quantity'], 8))->toBe('-1.17600000')
        ->and(bcsub($after['stock']['available']['value'], $before['stock']['available']['value'], 8))->toBe('109.36800000')
        ->and(bcsub($after['stock']['production_staging']['value'], $before['stock']['production_staging']['value'], 8))->toBe('-109.36800000');
    foreach (['run', 'order', 'original_line', 'original_transactions', 'original_journal', 'original_journal_lines', 'source_sales_order', 'source_sales_lines'] as $key) {
        expect($after[$key])->toEqual($before[$key]);
    }
    $journal = DB::table('journal_entry_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
        ->where('journal_entry_id', $return->journal_entry_id)->orderBy('l.id')->get(['a.account_code', 'l.debit_amount', 'l.credit_amount'])->all();
    expect(bcadd((string) DB::table('journal_entry_lines')->where('journal_entry_id', $return->journal_entry_id)->sum('debit_amount'), '0', 4))->toBe('109.3680')
        ->and(bcadd((string) DB::table('journal_entry_lines')->where('journal_entry_id', $return->journal_entry_id)->sum('credit_amount'), '0', 4))->toBe('109.3680')
        ->and($case['run']->requirements()->where('product_id', 77)->exists())->toBeFalse();
    recordExecutedMaterialCase(['return_rehearsal' => ['status' => 'successful_inside_rollback_transaction', 'document' => $return->doc_num,
        'quantity' => '1.17600000', 'value' => '109.36800000', 'journal' => $journal], 'before_return' => $before, 'during_return' => $after,
        'replacement_blocker' => 'Run requirement 27 and immutable BOM still require RAW-04002; the native issue endpoint accepts requirement IDs, not replacement product IDs. No RAW-04020 requirement is authorized. No identity correction was completed.']);
});
