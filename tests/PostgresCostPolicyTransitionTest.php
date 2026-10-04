<?php

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\PeriodClosePreflightService;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryCostPolicyTransition;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryLayerAllocation;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryCostPolicyService;
use Modules\Inventory\Services\InventoryCostPolicyTransitionService;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Inventory\Services\InventoryLayerService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

require_once __DIR__.'/InventoryCostTransitionSupport.php';

beforeEach(function (): void {
    expect(DB::getDriverName())->toBe('pgsql');
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBe('mgypack_acceptance_closure_20261003')->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432);
});

test('approved moving average balance transition activates FIFO without stock or GL mutation and preserves exact value', function (): void {
    $fixture = costTransitionFixture();
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    costTransitionMovement($fixture, $day(1), InventoryDocument::TypeAdjustmentIn, '10', '10');
    costTransitionMovement($fixture, $day(2), InventoryDocument::TypeAdjustmentIn, '10', '20');
    $movingAverageIssue = costTransitionMovement($fixture, $day(3), InventoryDocument::TypeIssue, '12');

    expect($movingAverageIssue->transactions->sole()->total_cost)->toBe('180.00000000');
    $remainingLayer = InventoryReceiptLayer::query()->where('branch_store_id', $fixture['store']->id)->where('product_id', $fixture['product']->id)->where('remaining_quantity', '>', 0)->sole();
    expect($remainingLayer->remaining_quantity)->toBe('8.00000000')
        ->and($remainingLayer->unit_cost)->toBe('20.00000000');

    $transactionCount = InventoryTransaction::query()->count();
    $journalCount = JournalEntry::query()->count();
    $service = app(InventoryCostPolicyTransitionService::class);
    $transition = $service->prepare($fixture['company']->getKey(), [
        'branch_store_id' => $fixture['store']->getKey(),
        'effective_from' => $day(4),
        'reason' => 'COST-03 acceptance',
    ], $fixture['preparer']->getKey());

    expect($transition->status)->toBe(InventoryCostPolicyTransition::StatusPrepared)
        ->and($transition->total_quantity)->toBe('8.00000000')
        ->and($transition->total_book_value)->toBe('120.00000000')
        ->and($transition->bases->sole()->inventory_receipt_layer_id)->toBe($remainingLayer->getKey())
        ->and($transition->bases->sole()->basis_unit_cost)->toBe('15.00000000');

    $fixture['preparer']->givePermissionTo('inventory.cost_policies.transition.approve');
    expect(fn () => $service->approve($transition, $fixture['preparer']->getKey()))->toThrow(DomainException::class);
    $fixture['preparer']->revokePermissionTo('inventory.cost_policies.transition.approve');

    auth()->login($fixture['approver']);
    request()->setUserResolver(fn (): User => $fixture['approver']);
    $transition = $service->approve($transition, $fixture['approver']->getKey());
    $transition = $service->activate($transition, $fixture['approver']->getKey());

    expect($transition->status)->toBe(InventoryCostPolicyTransition::StatusActivated)
        ->and($transition->policy->method)->toBe(InventoryCostPolicy::Fifo)
        ->and(InventoryTransaction::query()->count())->toBe($transactionCount)
        ->and(JournalEntry::query()->count())->toBe($journalCount)
        ->and($remainingLayer->refresh()->remaining_quantity)->toBe('8.00000000')
        ->and($remainingLayer->unit_cost)->toBe('20.00000000')
        ->and(fn () => $service->activate($transition, $fixture['approver']->getKey()))->toThrow(DomainException::class);

    expect(fn () => costTransitionMovement(
        $fixture,
        $day(3),
        InventoryDocument::TypeAdjustmentIn,
        '1',
        '99',
    ))->toThrow(DomainException::class)
        ->and(InventoryTransaction::query()->count())->toBe($transactionCount);

    $fifoIssue = costTransitionMovement($fixture, $day(5), InventoryDocument::TypeIssue, '3');
    $fifoTransaction = $fifoIssue->transactions->sole();
    $allocation = InventoryLayerAllocation::query()->where('issue_transaction_id', $fifoTransaction->getKey())->sole();
    $basis = $transition->bases->sole()->refresh();
    expect($fifoTransaction->total_cost)->toBe('45.00000000')
        ->and($allocation->cost_unit_snapshot)->toBe('15.00000000')
        ->and($allocation->cost_total_snapshot)->toBe('45.00000000')
        ->and($basis->remaining_quantity)->toBe('5.00000000')
        ->and($basis->remaining_book_value)->toBe('75.00000000')
        ->and($remainingLayer->refresh()->remaining_quantity)->toBe('5.00000000')
        ->and($remainingLayer->unit_cost)->toBe('20.00000000');

    app(InventoryDocumentPostingService::class)->reverse($fifoIssue);
    $reversal = InventoryTransaction::query()->where('reversal_of_id', $fifoTransaction->getKey())->sole();
    expect($reversal->total_cost)->toBe('45.00000000')
        ->and(InventoryReceiptLayer::query()->where('receipt_transaction_id', $reversal->getKey())->sole()->unit_cost)
        ->toBe('15.00000000')
        ->and(InventoryReceiptLayer::query()->where('receipt_transaction_id', $reversal->getKey())->sole()->source_allocation_cost_snapshot)
        ->toBe('45.00000000')
        ->and($allocation->refresh()->cost_total_snapshot)->toBe('45.00000000');

    $migration = require base_path('modules/Inventory/Database/Migrations/2026_10_03_120000_create_inventory_cost_policy_transitions.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
});

test('transition rejects stale preview and permission or replay attempts', function (): void {
    $fixture = costTransitionFixture();
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    costTransitionMovement($fixture, $day(1), InventoryDocument::TypeAdjustmentIn, '5', '10');
    $service = app(InventoryCostPolicyTransitionService::class);
    $transition = $service->prepare($fixture['company']->getKey(), [
        'branch_store_id' => $fixture['store']->getKey(),
        'effective_from' => $day(4),
        'reason' => 'Stale preview check',
    ], $fixture['preparer']->getKey());
    costTransitionMovement($fixture, $day(2), InventoryDocument::TypeAdjustmentIn, '1', '20');
    auth()->login($fixture['approver']);
    request()->setUserResolver(fn (): User => $fixture['approver']);
    expect(fn () => $service->approve($transition, $fixture['approver']->getKey()))->toThrow(DomainException::class);

    $session = costTransitionSession($fixture);
    $outsider = closureSyntheticUser();
    $this->actingAs($outsider)->withSession($session)
        ->post(route('admin.inventory.cost-policies.transitions.approve', $transition))
        ->assertForbidden();
    $this->actingAs($fixture['approver'])->withSession($session)
        ->post(route('admin.inventory.cost-policies.transitions.approve', $transition))
        ->assertRedirect()->assertSessionHasErrors('transition');
    expect(fn () => $service->prepare($fixture['company']->getKey(), [
        'branch_store_id' => $fixture['store']->getKey(),
        'effective_from' => $day(4),
        'reason' => 'Mismatched actor',
    ], $fixture['preparer']->getKey()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(InventoryCostPolicyService::class)->createVersion($fixture['company']->getKey(), [
            'branch_store_id' => $fixture['store']->getKey(),
            'method' => InventoryCostPolicy::MovingAverage,
            'effective_from' => $day(4),
        ], $fixture['approver']->getKey()))->toThrow(AuthorizationException::class);
    $fixture['approver']->givePermissionTo(['inventory.cost_policies.view', 'inventory.cost_policies.transition.prepare']);
    $this->actingAs($fixture['approver'])->withSession($session)
        ->get(route('admin.inventory.cost-policies.index'))
        ->assertOk()
        ->assertSee(__('inventory_cost_policy.transition_title'))
        ->assertSee(__('inventory_cost_policy.prepare_transition'));
});

test('transition service enforces actor branch and selected period ceilings', function (): void {
    $fixture = costTransitionFixture();
    $otherBranch = Branch::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => (int) Branch::withTrashed()->max('doc_number') + 1,
        'doc_num' => 'COST-TRANSITION-OTHER-BRANCH',
        'name' => 'Other authorized branch',
        'type' => Branch::TypeWarehouse,
        'status' => 'active',
    ]);
    $makeRestrictedActor = function (Branch $allowedBranch, bool $allowSelectedPeriod, int $sequence) use ($fixture): User {
        $role = Role::query()->create([
            'name' => 'cost-transition-restricted-'.$sequence,
            'guard_name' => 'web',
            'doc_number' => 992100 + $sequence,
            'doc_num' => 'COST-TRANSITION-ROLE-'.$sequence,
            'company_access_restricted' => true,
            'branch_access_restricted' => true,
            'financial_period_access_restricted' => true,
        ]);
        DB::table('role_company_access')->insert([
            'role_id' => $role->getKey(), 'company_id' => $fixture['company']->getKey(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('role_branch_access')->insert([
            'role_id' => $role->getKey(), 'branch_id' => $allowedBranch->getKey(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($allowSelectedPeriod) {
            DB::table('role_financial_period_access')->insert([
                'role_id' => $role->getKey(), 'financial_period_id' => $fixture['period']->getKey(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        Permission::findOrCreate('inventory.cost_policies.transition.prepare', 'web');
        $actor = closureSyntheticUser();
        $actor->givePermissionTo('inventory.cost_policies.transition.prepare');
        $actor->assignRole($role);

        return $actor;
    };
    $prepare = function (User $actor) use ($fixture): void {
        auth()->login($actor);
        request()->setUserResolver(fn (): User => $actor);
        app(InventoryCostPolicyTransitionService::class)->prepare($fixture['company']->getKey(), [
            'branch_store_id' => $fixture['store']->getKey(),
            'effective_from' => $fixture['period']->from_date->copy()->addDay()->toDateString(),
        ], $actor->getKey());
    };

    expect(fn () => $prepare($makeRestrictedActor($otherBranch, true, 1)))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $prepare($makeRestrictedActor($fixture['branch'], false, 2)))
        ->toThrow(AuthorizationException::class);
});

test('company transition basis respects store policy precedence', function (): void {
    $fixture = costTransitionFixture(isolatedCompany: true);
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    $otherStore = BranchStore::query()->create(['branch_id' => $fixture['branch']->getKey(), 'name' => 'Company transition store']);
    app(InventoryCostPolicyService::class)->createVersion($fixture['company']->getKey(), [
        'branch_store_id' => $fixture['store']->getKey(),
        'method' => InventoryCostPolicy::MovingAverage,
        'effective_from' => $day(0),
    ], $fixture['preparer']->getKey());
    costTransitionMovement($fixture, $day(1), InventoryDocument::TypeAdjustmentIn, '5', '10');
    costTransitionMovement([...$fixture, 'movement_store' => $otherStore], $day(1), InventoryDocument::TypeAdjustmentIn, '2', '20');

    $transition = app(InventoryCostPolicyTransitionService::class)->prepare($fixture['company']->getKey(), [
        'effective_from' => $day(2),
        'reason' => 'Company transition precedence',
    ], $fixture['preparer']->getKey());

    expect($transition->total_quantity)->toBe('2.00000000')
        ->and($transition->total_book_value)->toBe('40.00000000')
        ->and($transition->bases->pluck('branch_store_id')->unique()->all())->toBe([$otherStore->getKey()]);
});

test('pending transition blocks period close and authorized cancellation releases stale scope', function (): void {
    $fixture = costTransitionFixture();
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    costTransitionMovement($fixture, $day(1), InventoryDocument::TypeAdjustmentIn, '2', '5');
    $service = app(InventoryCostPolicyTransitionService::class);
    $transition = $service->prepare($fixture['company']->getKey(), [
        'branch_store_id' => $fixture['store']->getKey(),
        'effective_from' => $day(2),
        'reason' => 'Close blocker recovery',
    ], $fixture['preparer']->getKey());
    $closeCheck = collect(app(PeriodClosePreflightService::class)->checks($fixture['period']))
        ->firstWhere('key', 'pending_inventory_cost_policy_transitions');
    expect($closeCheck['status'])->toBe('blocker')->and($closeCheck['count'])->toBe(1);

    $fixture['period']->forceFill(['is_closed' => true])->save();
    auth()->login($fixture['approver']);
    request()->setUserResolver(fn (): User => $fixture['approver']);
    expect(fn () => $service->approve($transition, $fixture['approver']->getKey()))
        ->toThrow(AuthorizationException::class);

    $this->actingAs($fixture['preparer'])->withSession(costTransitionSession($fixture))
        ->post(route('admin.inventory.cost-policies.transitions.cancel', $transition), [
            'cancellation_reason' => 'Period is no longer eligible',
        ])->assertRedirect()->assertSessionHasNoErrors();
    expect($transition->refresh()->status)->toBe(InventoryCostPolicyTransition::StatusCancelled)
        ->and($transition->cancelled_by)->toBe($fixture['preparer']->getKey());
    $closeCheck = collect(app(PeriodClosePreflightService::class)->checks($fixture['period']->refresh()))
        ->firstWhere('key', 'pending_inventory_cost_policy_transitions');
    expect($closeCheck['status'])->toBe('pass')->and($closeCheck['count'])->toBe(0);

    $fixture['period']->forceFill(['is_closed' => false])->save();
    auth()->login($fixture['preparer']);
    request()->setUserResolver(fn (): User => $fixture['preparer']);
    $replacement = $service->prepare($fixture['company']->getKey(), [
        'branch_store_id' => $fixture['store']->getKey(),
        'effective_from' => $day(2),
        'reason' => 'Replacement after cancellation',
    ], $fixture['preparer']->getKey());
    expect($replacement->status)->toBe(InventoryCostPolicyTransition::StatusPrepared);
});

test('fractional transition preserves final residual through partial return reversal and repost', function (): void {
    $fixture = costTransitionFixture();
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    $layers = app(InventoryLayerService::class);
    $receipt = costTransitionTransaction($fixture, [
        'transaction_date' => $day(1),
        'quantity_in' => '3.00000000',
        'unit_cost' => '0.33333333',
        'total_cost' => '1.00000000',
    ]);
    $layers->recordInbound($receipt);
    $service = app(InventoryCostPolicyTransitionService::class);
    $transition = $service->prepare($fixture['company']->getKey(), [
        'branch_store_id' => $fixture['store']->getKey(),
        'effective_from' => $day(2),
        'reason' => 'Fractional residual acceptance',
    ], $fixture['preparer']->getKey());
    auth()->login($fixture['approver']);
    request()->setUserResolver(fn (): User => $fixture['approver']);
    $transition = $service->activate(
        $service->approve($transition, $fixture['approver']->getKey()),
        $fixture['approver']->getKey(),
    );
    $issueDocument = costTransitionMovement($fixture, $day(3), InventoryDocument::TypeIssue, '3');
    $issue = $issueDocument->transactions->sole();
    $allocation = InventoryLayerAllocation::query()->where('issue_transaction_id', $issue->getKey())->sole();
    $journal = JournalEntry::query()->with('lines')->findOrFail($issueDocument->journal_entry_id);
    $journalSnapshot = $journal->lines->map->only(['account_id', 'debit_amount', 'credit_amount'])->all();
    $journalCount = JournalEntry::query()->count();

    $postPartialReturn = function (int $offset) use ($fixture, $day, $layers, $issue): InventoryTransaction {
        $plan = $layers->planRestoration($issue, '1.00000000', limitToUnreturned: true);
        $cost = $layers->restorationCost($plan, '1.00000000');
        $return = costTransitionTransaction($fixture, [
            'transaction_date' => $day($offset),
            'transaction_type' => InventoryDocument::TypeSalesReturnReceipt,
            'quantity_in' => '1.00000000',
            'unit_cost' => $cost['unit_cost'],
            'total_cost' => $cost['total_cost'],
            'cost_method' => InventoryCostPolicy::Fifo,
            'cost_policy_id' => $issue->cost_policy_id,
        ]);
        $layers->recordInbound($return, $issue, restorationPlan: $plan);

        return $return;
    };

    $firstReturn = $postPartialReturn(4);
    $firstReturnReversal = costTransitionTransaction($fixture, [
        'transaction_date' => $day(5),
        'transaction_type' => InventoryDocument::TypeSalesReturnReceipt,
        'quantity_out' => '1.00000000',
        'unit_cost' => $firstReturn->unit_cost,
        'total_cost' => $firstReturn->total_cost,
        'cost_method' => InventoryCostPolicy::Fifo,
        'cost_policy_id' => $issue->cost_policy_id,
        'is_reversal' => true,
        'reversal_of_id' => $firstReturn->getKey(),
    ]);
    $layers->allocateIssue($firstReturnReversal, $firstReturn->getKey());
    $repostedReturn = $postPartialReturn(6);
    $secondReturn = $postPartialReturn(7);
    $finalReturn = $postPartialReturn(8);

    expect($transition->bases->sole()->refresh()->remaining_quantity)->toBe('0.00000000')
        ->and($transition->bases->sole()->remaining_book_value)->toBe('0.00000000')
        ->and($allocation->cost_total_snapshot)->toBe('1.00000000')
        ->and($repostedReturn->total_cost)->toBe('0.33333333')
        ->and($secondReturn->total_cost)->toBe('0.33333333')
        ->and($finalReturn->total_cost)->toBe('0.33333334')
        ->and(bcadd(bcadd((string) $repostedReturn->total_cost, (string) $secondReturn->total_cost, 8), (string) $finalReturn->total_cost, 8))->toBe('1.00000000')
        ->and(InventoryReceiptLayer::query()
            ->whereIn('receipt_transaction_id', [$repostedReturn->getKey(), $secondReturn->getKey(), $finalReturn->getKey()])
            ->sum('remaining_quantity') == 3)->toBeTrue()
        ->and(InventoryReceiptLayer::query()
            ->whereIn('receipt_transaction_id', [$repostedReturn->getKey(), $secondReturn->getKey(), $finalReturn->getKey()])
            ->orderBy('id')->pluck('source_allocation_cost_snapshot')->all())
        ->toBe(['0.33333333', '0.33333333', '0.33333334'])
        ->and(JournalEntry::query()->count())->toBe($journalCount)
        ->and($journal->refresh()->load('lines')->lines->map->only(['account_id', 'debit_amount', 'credit_amount'])->all())
        ->toBe($journalSnapshot);
});
