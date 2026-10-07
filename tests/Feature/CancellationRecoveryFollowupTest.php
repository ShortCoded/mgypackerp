<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Services\ActivityLogger;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Production\Models\ProductionExpenseRequest;
use Modules\Production\Services\ProductionExpenseRequestService;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\SalesCycleAuditService;

require_once __DIR__.'/../CancellationRecoverySupport.php';

test('archived source drafts restore original reservations once without changing amounts or history', function (string $sourceType): void {
    $f = cancellationRestoreFixture($sourceType);
    $service = app(CustomerInvoiceService::class);
    $invoice = $f['invoice'];
    $rows = $invoice->lines->map->getRawOriginal()->all();
    $totals = [$invoice->total_amount, JournalEntry::count(), InventoryTransaction::count()];
    $service->deleteDraft($invoice);
    if ($sourceType === 'order') {
        DB::table('activity_log')->where('subject_type', CustomerInvoice::class)->where('subject_id', $invoice->id)
            ->where('event', 'customer_invoice.deleted')->update(['properties' => json_encode(['doc_num' => $invoice->doc_num])]);
    }
    $url = route('admin.sales.sales-invoices.restore', $invoice->doc_num);
    $this->patchJson($url)->assertOk();
    $this->patchJson($url)->assertNotFound();
    $restored = $invoice->fresh();
    expect($restored->status)->toBe('draft')->and($restored->trashed())->toBeFalse()
        ->and($restored->lines->map->getRawOriginal()->all())->toBe($rows)
        ->and([$restored->total_amount, JournalEntry::count(), InventoryTransaction::count()])->toBe($totals)
        ->and(DB::table('activity_log')->where('subject_type', CustomerInvoice::class)->where('subject_id', $invoice->id)
            ->where('event', 'customer_invoice.restored')->count())->toBe(1);
    $field = $sourceType === 'order' ? 'invoiced_quantity' : 'converted_quantity';
    $sourceLine = $f['source']->fresh()->lines->first();
    expect($sourceLine->{$field})->toBe($sourceType === 'order' ? '100.00000000' : '1.00000000');
    $service->deleteDraft($restored);
    $service->restoreArchived($restored->fresh());
    expect($sourceLine->fresh()->{$field})->toBe($sourceType === 'order' ? '100.00000000' : '1.00000000');
})->with(['order', 'request']);

test('source draft restoration rejects missing proof stale counters closed period and unauthorized allocation', function (string $sourceType): void {
    $f = cancellationRestoreFixture($sourceType);
    $service = app(CustomerInvoiceService::class);
    $service->deleteDraft($f['invoice']);
    $archived = $f['invoice']->fresh();
    $line = $f['source']->fresh()->lines->first();
    $field = $sourceType === 'order' ? 'invoiced_quantity' : 'converted_quantity';
    $permission = $sourceType === 'order' ? 'sales_orders.invoice' : 'customer_invoices.create';
    $f['user']->revokePermissionTo($permission);
    $this->patchJson(route('admin.sales.sales-invoices.restore', $archived->doc_num))->assertForbidden();
    $f['user']->givePermissionTo($permission);
    $f['period']->update(['is_closed' => true]);
    expect(fn () => $service->restoreArchived($archived))->toThrow(DomainException::class);
    $f['period']->update(['is_closed' => false]);
    if ($sourceType === 'order') {
        $line->update(['invoiced_quantity' => '1']);
    } else {
        DB::table('activity_log')->where('subject_type', $f['source']::class)->where('subject_id', $f['source']->id)
            ->where('event', 'sales_request.conversion_reversed')->delete();
    }
    expect(fn () => $service->restoreArchived($archived))->toThrow(DomainException::class)
        ->and($archived->fresh()->trashed())->toBeTrue()->and($line->fresh()->{$field})->toBe($sourceType === 'order' ? '1.00000000' : '0.00000000');
})->with(['order', 'request']);

test('restore audit failure rolls back the invoice and original source reservation', function (): void {
    $f = cancellationRestoreFixture();
    app(CustomerInvoiceService::class)->deleteDraft($f['invoice']);
    $this->mock(SalesCycleAuditService::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('SYNTHETIC restore audit unavailable'));
    expect(fn () => app(CustomerInvoiceService::class)->restoreArchived($f['invoice']->fresh()))->toThrow(RuntimeException::class)
        ->and($f['invoice']->fresh()->trashed())->toBeTrue()->and($f['source']->fresh()->lines->first()->invoiced_quantity)->toBe('0.00000000');
});

test('unpaid expense withdrawal retains approval is localized and idempotent without financial effects', function (string $locale): void {
    $f = cancellationExpenseFixture();
    $expense = $f['expense'];
    app()->setLocale($locale);
    $this->withSession(['locale' => $locale]);
    $before = [$expense->amount, $expense->approved_by, $expense->approved_at->toISOString(), JournalEntry::count(), InventoryTransaction::count()];
    $url = route('admin.production.expenses.withdraw-approval', $expense);
    $this->get(route('admin.production.expenses.show', $expense))->assertOk()->assertSee($url, false)->assertSee(__('cancellation_review.withdraw_expense_approval'));
    $payload = ['reason' => 'SYNTHETIC unpaid approval withdrawn', '_submission_token' => (string) Str::uuid()];
    $this->postJson($url, $payload)->assertOk();
    $this->postJson($url, $payload)->assertOk();
    $fresh = $expense->fresh();
    expect($fresh->status)->toBe(ProductionExpenseRequest::StatusRejected)
        ->and([$fresh->amount, $fresh->approved_by, $fresh->approved_at->toISOString(), JournalEntry::count(), InventoryTransaction::count()])->toBe($before)
        ->and(DB::table('activity_log')->where('subject_type', ProductionExpenseRequest::class)->where('subject_id', $expense->id)
            ->where('event', 'production.expense.approval_withdrawn')->count())->toBe(1);
})->with(['ar', 'en']);

test('expense withdrawal forbids payment closed periods and missing permission and rolls back on audit failure', function (): void {
    $f = cancellationExpenseFixture();
    $expense = $f['expense'];
    $url = route('admin.production.expenses.withdraw-approval', $expense);
    $payload = ['reason' => 'SYNTHETIC withdrawal guards', '_submission_token' => (string) Str::uuid()];
    $f['user']->revokePermissionTo('production.expenses.reverse');
    $this->postJson($url, $payload)->assertForbidden();
    $f['user']->givePermissionTo('production.expenses.reverse');
    $f['period']->update(['is_closed' => true]);
    expect(fn () => app(ProductionExpenseRequestService::class)->withdrawApproval($expense, $payload['reason']))->toThrow(DomainException::class);
    $f['period']->update(['is_closed' => false]);
    $expense->update(['paid_at' => now()]);
    expect(fn () => app(ProductionExpenseRequestService::class)->withdrawApproval($expense->fresh(), $payload['reason']))->toThrow(DomainException::class);
    $expense->update(['paid_at' => null]);
    $f['runs'][0]->update(['received_base_quantity' => '1']);
    expect(fn () => app(ProductionExpenseRequestService::class)->withdrawApproval($expense->fresh(), $payload['reason']))->toThrow(DomainException::class);
    $f['runs'][0]->update(['received_base_quantity' => '0']);
    $this->mock(ActivityLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('SYNTHETIC withdrawal audit unavailable'));
    expect(fn () => app(ProductionExpenseRequestService::class)->withdrawApproval($expense->fresh(), $payload['reason']))->toThrow(RuntimeException::class)
        ->and($expense->fresh()->status)->toBe(ProductionExpenseRequest::StatusApproved);
});
