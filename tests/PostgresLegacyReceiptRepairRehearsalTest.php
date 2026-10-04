<?php

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryLayerAllocation;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Inventory\Services\InventoryMovementCorrectionService;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/InventoryMovementCorrectionSupport.php';

beforeEach(function (): void {
    if (getenv('MGYPACK_CUSTOMER_LEGACY_REHEARSAL') !== '1') {
        $this->markTestSkipped('Explicit separate local customer-copy repair rehearsal only.');
    }
    $identity = DB::selectOne('select current_database() as db, host(inet_server_addr()) as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBe('mgypack_legacy_repair_candidate_20261004')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
});

function legacyRepairFinancialHashes(): array
{
    $result = [];
    foreach (['inventory_opening_stocks', 'inventory_opening_stock_lines', 'inventory_opening_stock_pricings', 'inventory_opening_stock_pricing_lines', 'inventory_documents',
        'inventory_document_lines', 'inventory_transactions', 'journal_entries', 'journal_entry_lines', 'sales_orders', 'sales_order_lines',
        'customer_invoices', 'customer_invoice_lines', 'customer_invoice_deliveries'] as $table) {
        $hashes = DB::table($table)->orderBy('id')->get()->map(fn ($row) => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)))->all();
        $result[$table] = ['count' => count($hashes), 'sha256' => hash('sha256', implode('', $hashes))];
    }

    return $result;
}

test('the exact 3 October customer copy repairs 252 original receipt units without changing any original financial document', function (): void {
    $path = '/tmp/mgypack-legacy-customer-repair-rehearsal-20261004.json';
    expect(file_exists($path))->toBeFalse();
    request()->setLaravelSession(app('session.store'));
    $document = InventoryDocument::query()->where('company_id', 1)->where('doc_num', 'INV-MOV-00002')->sole();
    $f = ['company' => Company::findOrFail($document->company_id), 'branch' => Branch::findOrFail($document->branch_id),
        'period' => FinancialPeriod::findOrFail($document->financial_period_id), 'user' => closureSyntheticUser(), 'reviewer' => closureSyntheticUser()];
    $syntheticAccounts = [];
    foreach (['raw_material_inventory', 'packaging_material_inventory', 'abnormal_waste_loss', 'inventory_adjustment_gain',
        'inventory_adjustment_loss', 'warehouse_damage_loss', 'finished_goods_inventory', 'quarantine_inventory', 'rework_inventory', 'work_in_process_inventory'] as $code) {
        $classification = AccountClassification::query()->where('code', $code)->where('status', 'active')->sole();
        if (Account::query()->where('company_id', $document->company_id)->where('account_classification_id', $classification->id)->eligibleForDirectPosting()->doesntExist()) {
            $number = (int) Account::withTrashed()->where('company_id', $document->company_id)->max('doc_number') + 1;
            $account = Account::query()->create(['company_id' => $document->company_id, 'doc_number' => $number,
                'doc_num' => 'SYNTHETIC-REHEARSAL-ACCOUNT-'.$number, 'account_code' => '9999'.$number, 'name' => 'SYNTHETIC unbooked reconciliation '.$code,
                'account_classification_id' => $classification->id, 'account_type' => $classification->account_type,
                'statement_type' => $classification->statement_type, 'normal_balance' => $classification->normal_balance,
                'level' => 1, 'is_group' => false, 'is_postable' => true, 'status' => 'active']);
            $syntheticAccounts[] = ['id' => $account->id, 'classification' => $code, 'customer_approval' => false];
        }
    }
    foreach (['inventory.documents.view', 'inventory.documents.correct_prepare', 'inventory.documents.correct_approve',
        'customer_invoices.view', 'customer_invoices.view_prices', 'journal_entries.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $f['user']->givePermissionTo($permission);
        $f['reviewer']->givePermissionTo($permission);
    }
    $backup = '/tmp/mgypack-legacy-customer-before-repair-final2-20261004.dump';
    expect(file_exists($backup))->toBeFalse();
    $config = config('database.connections.pgsql');
    (new Process(['pg_dump', '--host=127.0.0.1', '--port=5432', '--username='.$config['username'], '--format=custom', '--file='.$backup,
        'mgypack_legacy_repair_candidate_20261004'], env: ['PGPASSWORD' => $config['password']]))->setTimeout(90)->mustRun();
    chmod($backup, 0600);
    manualCorrectionActor($f, $f['user']);
    $service = app(InventoryMovementCorrectionService::class);
    $preview = $service->preview($document);
    $plan = $preview['legacy_repair'];
    expect($plan['misplaced_quantity'])->toBe('209.00000000')->and($plan['exchanged_quantity'])->toBe('10.00000000')
        ->and($plan['known_value_change'])->toBe('121745.00000000')->and($plan['lines'])->toHaveCount(14)
        ->and($plan['affected_documents'][0]['number'])->toBe('INV-MOV-00006')
        ->and(collect($plan['financial_proof']['invoices'])->pluck('doc_num')->all())->toContain('SINV-00001');
    $before = legacyRepairFinancialHashes();
    $bookBefore = DB::table('inventory_receipt_layers')->selectRaw('sum(remaining_quantity * coalesce(unit_cost, 0)) as value')->first()->value;
    $glBefore = app(InventoryGlReconciliationService::class)->reconcile($f['company']->id, $f['period']->id);
    $this->get(route('admin.inventory.documents.corrections.index', $document))->assertOk()->assertSee('INV-MOV-00006')->assertSee('SINV-00001');
    $proposalId = $this->postJson(route('admin.inventory.documents.corrections.store', $document), [
        'source_fingerprint' => $preview['source_fingerprint'], 'operation' => 'repair_lineage', 'posting_date' => '2026-10-04',
        'reason' => 'SYNTHETIC isolated local rehearsal. No customer approval or history is supplied.',
    ])->assertOk()->json('data.proposal_id');
    $this->postJson(route('admin.inventory.documents.corrections.approve', [$document, $proposalId]),
        ['approval_reason' => 'SYNTHETIC same actor denied'])->assertUnprocessable();
    manualCorrectionActor($f, $f['reviewer']);
    $this->postJson(route('admin.inventory.documents.corrections.approve', [$document, $proposalId]),
        ['approval_reason' => 'SYNTHETIC independent local rehearsal only, not customer approval'])->assertOk();
    expect(legacyRepairFinancialHashes())->toBe($before);
    $receiptIds = array_column($plan['lines'], 'receipt_id');
    $reverseIds = array_column($plan['lines'], 'reversal_id');
    $layers = InventoryReceiptLayer::query()->whereIn('receipt_transaction_id', $receiptIds)->get()->keyBy('id');
    $allocations = InventoryLayerAllocation::query()->whereIn('issue_transaction_id', $reverseIds)->get();
    expect($layers)->toHaveCount(14)->and($allocations)->toHaveCount(14)
        ->and($layers->where('remaining_quantity', '<>', '0.00000000'))->toHaveCount(0)
        ->and($allocations->whereNotIn('inventory_receipt_layer_id', $layers->modelKeys()))->toHaveCount(0)
        ->and($allocations->reduce(fn ($sum, $row) => bcadd($sum, $row->quantity, 8), '0'))->toBe('252.00000000');
    $bookAfter = DB::table('inventory_receipt_layers')->selectRaw('sum(remaining_quantity * coalesce(unit_cost, 0)) as value')->first()->value;
    expect(bcsub((string) $bookAfter, (string) $bookBefore, 8))->toBe($plan['known_value_change']);
    $this->postJson(route('admin.inventory.documents.corrections.approve', [$document, $proposalId]),
        ['approval_reason' => 'SYNTHETIC no-op repeat local approval'])->assertOk();
    expect(legacyRepairFinancialHashes())->toBe($before)->and($service->preview($document)['legacy_repair']['misplaced_quantity'])->toBe('0.00000000');
    expect(DB::table('journal_entry_lines')->whereIn('account_id', array_column($syntheticAccounts, 'id'))->exists())->toBeFalse();
    $manifest = ['database' => 'mgypack_legacy_repair_candidate_20261004', 'synthetic_rehearsal' => true, 'customer_approval' => false,
        'source_document' => $document->doc_num, 'proposal_id' => $proposalId, 'backup' => $backup, 'backup_sha256' => hash_file('sha256', $backup),
        'preparer_id' => $f['user']->id, 'reviewer_id' => $f['reviewer']->id, 'plan' => $plan, 'original_financial_rows' => $before,
        'synthetic_zero_balance_account_setup' => $syntheticAccounts,
        'logical_quantity_unchanged' => true, 'original_reversal_quantity' => '252.00000000', 'foreign_reversal_allocations_after' => 0,
        'known_book_value_before' => $bookBefore, 'known_book_value_after' => $bookAfter,
        'gl_before' => $glBefore, 'gl_after' => app(InventoryGlReconciliationService::class)->reconcile($f['company']->id, $f['period']->id)];
    file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    chmod($path, 0600);
});
