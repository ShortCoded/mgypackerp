<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\Role;
use Modules\Core\Models\UserNotification;
use Modules\Core\Services\OperatingContextService;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Purchases\Services\ProcurementSourcingService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/../ProcurementSupport.php';

test('submitted purchase request notifies only scoped approver and dashboard remains document driven', function (): void {
    $fixture = procurementFixture();
    $actionPermission = Permission::findOrCreate('purchases.purchase_requisition_approvals.approve', 'web');
    $viewPermission = Permission::findOrCreate('purchases.purchase_requisitions.view', 'web');
    $otherBranch = procurementAdministrativeBranch($fixture);
    $approver = User::factory()->create();
    $outsideApprover = User::factory()->create();

    $roleFor = function (string $suffix, int $branchId) use ($actionPermission, $fixture, $viewPermission): Role {
        $role = Role::query()->create([
            'name' => "notification-approver-{$suffix}",
            'guard_name' => 'web',
            'doc_number' => random_int(700000, 799999),
            'doc_num' => "Role-NOTIFY-{$suffix}",
            'company_access_restricted' => true,
            'branch_access_restricted' => true,
            'financial_period_access_restricted' => false,
        ]);
        $role->givePermissionTo([$actionPermission, $viewPermission]);
        DB::table('role_company_access')->insert(['role_id' => $role->getKey(), 'company_id' => $fixture['company']->getKey()]);
        DB::table('role_branch_access')->insert(['role_id' => $role->getKey(), 'branch_id' => $branchId]);

        return $role;
    };

    $approverRole = $roleFor('IN', $fixture['branch']->getKey());
    $outsideRole = $roleFor('OUT', $otherBranch->getKey());
    $approver->assignRole($approverRole);
    $outsideApprover->assignRole($outsideRole);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $requisition = procurementManualRequisition($fixture);
    app(ProcurementSourcingService::class)->submitRequisition($requisition);

    $notification = UserNotification::query()
        ->where('user_id', $approver->getKey())
        ->where('type', 'purchases.'.PurchaseRequisition::StatusSubmitted)
        ->sole();
    expect($notification->requires_action)->toBeTrue()
        ->and($notification->required_permission)->toBe($actionPermission->name)
        ->and($notification->branch_id)->toBe($fixture['branch']->getKey())
        ->and($notification->external_body)->not->toContain('amount', 'price', 'total')
        ->and(UserNotification::query()->where('user_id', $outsideApprover->getKey())->exists())->toBeFalse();

    $context = [
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
        OperatingContextService::BranchIdKey => $fixture['branch']->getKey(),
        OperatingContextService::BranchDocNumKey => $fixture['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $fixture['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $fixture['period']->doc_num,
    ];

    $this->actingAs($approver)->withSession($context)
        ->getJson(route('dashboard.data'))
        ->assertOk()
        ->assertJsonPath('data.summary.approval_count', 1);
    $this->postJson(route('admin.notifications.read', $notification))->assertOk();
    $this->getJson(route('dashboard.data'))
        ->assertOk()
        ->assertJsonPath('data.summary.approval_count', 1);

    $approverRole->revokePermissionTo($actionPermission);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $approver->unsetRelation('roles')->unsetRelation('permissions');

    $this->getJson(route('admin.notifications.poll'))
        ->assertOk()
        ->assertJsonPath('data.unread_count', 0)
        ->assertJsonCount(0, 'data.notifications');
});

test('personal dashboard supports approval sources that use a public uuid instead of a document number', function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $permission = Permission::findOrCreate('hr.hr_requests.manage', 'web');
    $secondPermission = Permission::findOrCreate('purchases.purchase_requisition_approvals.approve', 'web');
    $user = User::factory()->create();
    $user->givePermissionTo([$permission, $secondPermission]);

    $this->actingAs($user)
        ->getJson(route('dashboard.data'))
        ->assertOk()
        ->assertJsonPath('data.summary.approval_count', 0)
        ->assertJsonPath('data.cards.3.key', 'approvals');
});
