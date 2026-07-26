<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\CompanyAccessService;
use Modules\Core\Services\SettingService;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function userWithPermissions(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function allowMultipleCompaniesForRoleTests(): void
{
    DB::statement('DROP INDEX IF EXISTS companies_one_active_main_unique');
}

function protectedRoleFixture(array $attributes = []): Role
{
    return Role::query()->create([
        'name' => 'system-administrator',
        'guard_name' => 'web',
        'doc_number' => 0,
        'doc_num' => 'Role-00000',
        ...$attributes,
    ]);
}

test('roles index requires view permission', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/roles')
        ->assertForbidden();

    $this->actingAs(userWithPermissions(['roles.view']))
        ->get('/admin/roles')
        ->assertOk()
        ->assertDontSee('id="btn_add_record"', false)
        ->assertDontSee('id="roles_trash_filter"', false)
        ->assertDontSee(__('roles.document_number_settings.title'));

    $this->actingAs(userWithPermissions(['roles.view', 'roles.create', 'roles.delete']))
        ->get('/admin/roles')
        ->assertOk()
        ->assertSee(__('auth.roles.title'))
        ->assertSee(__('menu.dashboard'))
        ->assertSee(__('menu.basic_data'))
        ->assertDontSee(__('menu.administration'))
        ->assertSee('/admin/roles/create')
        ->assertDontSee(__('roles.document_number_settings.title'))
        ->assertSee('id="btn_add_record"', false)
        ->assertSee(__('common.add_new_record'))
        ->assertSee(__('common.shortcuts.add_new_record'))
        ->assertSee(__('common.shortcuts.global_search'))
        ->assertSee(__('common.shortcuts.lock_screen'))
        ->assertSee(__('common.shortcuts.logout'))
        ->assertSee('tableSearchTitle', false)
        ->assertSee('assets/js/modules/Core/shortcuts.js', false)
        ->assertSee('id="navbar_search_input"', false)
        ->assertSee('id="btn_lock_screen"', false)
        ->assertSee('id="btn_logout"', false)
        ->assertSee('id="bulk_actions_bar"', false)
        ->assertSee('roles-bulk-actions-bar', false)
        ->assertSee('roles-toolbar-actions', false)
        ->assertSee('id="bulk_action_select"', false)
        ->assertSee('id="bulk_action_apply"', false)
        ->assertSee('id="bulk_selected_count"', false)
        ->assertSee(__('common.shortcuts.bulk_apply'), false)
        ->assertSee('id="select_all_records"', false)
        ->assertSee('d-none', false)
        ->assertSee('all no-colvis dt-code', false)
        ->assertSee('all no-colvis dt-actions', false)
        ->assertSee(__('roles.bulk_action'))
        ->assertSee(__('common.actions.apply'))
        ->assertSee('vendors/select2/select2.min.css', false)
        ->assertSee('vendors/select2-bootstrap-5-theme/select2-bootstrap-5-theme.min.css', false)
        ->assertSee('vendors/select2/select2.full.min.js', false)
        ->assertSee('assets/js/modules/Core/select2-ajax.js', false)
        ->assertSee('column_visibility', false)
        ->assertSee('vendors/datatables.net-responsive-bs5/css/responsive.bootstrap5.min.css', false)
        ->assertSee('vendors/datatables.net-buttons-bs5/css/buttons.bootstrap5.min.css', false)
        ->assertSee('vendors/datatables.net-buttons/js/dataTables.buttons.min.js', false)
        ->assertSee('vendors/datatables.net-buttons-bs5/js/buttons.bootstrap5.min.js', false)
        ->assertSee('vendors/datatables.net-buttons/js/buttons.colVis.min.js', false)
        ->assertSee('assets/js/modules/Core/datatables-defaults.js', false)
        ->assertDontSee('cdn.datatables.net', false)
        ->assertSee(__('common.fields.notes'))
        ->assertSee(__('common.fields.created_by'))
        ->assertSee(__('common.fields.updated_by'))
        ->assertDontSee(__('auth.roles.guard'))
        ->assertDontSee(__('auth.roles.permissions_count'))
        ->assertDontSee('id="roles_trash_filter"', false)
        ->assertDontSee('role-form-modal')
        ->assertDontSee('roles-filters');

    $this->actingAs(userWithPermissions(['roles.view', 'roles.view_trashed']))
        ->get('/admin/roles')
        ->assertOk()
        ->assertSee('id="roles_trash_filter"', false)
        ->assertSee(__('common.trash.active'))
        ->assertSee(__('common.trash.trashed'))
        ->assertSee(__('common.trash.all'));

    $rolesScript = file_get_contents(public_path('assets/js/modules/Auth/roles.js'));

    expect($rolesScript)
        ->toContain('responsivePriority: 1')
        ->toContain('responsivePriority: 2')
        ->toContain('protectedColumns = [0, 1, -1]')
        ->toContain('selectedDocNums = new Set()')
        ->toContain('rowCheckboxSelector')
        ->toContain('selectAllSelector')
        ->toContain('selectedDocNumsArray')
        ->toContain('restoreSelectionState')
        ->toContain('clearSelection')
        ->toContain('updateBulkActionsUi')
        ->toContain('updateSelectAllState')
        ->toContain('trashFilterValue')
        ->toContain('trash_filter')
        ->toContain('roles_trash_filter')
        ->toContain('shieldSelectionEvents')
        ->toContain('responsiveControlTarget = 1')
        ->toContain('patchResponsiveControlTarget')
        ->toContain('syncResponsiveControlColumn')
        ->toContain('queueResponsiveControlSync')
        ->toContain('responsive.c.details.target = responsiveControlTarget')
        ->toContain('column-sizing.dt.rolesResponsive')
        ->toContain('window.requestAnimationFrame')
        ->toContain("type: 'inline'")
        ->toContain(".off('click.rolesSelectCell', 'tbody tr:not(.child) td.dt-select')")
        ->toContain('$checkbox.prop(\'checked\', !$checkbox.prop(\'checked\')).trigger(\'change\');')
        ->toContain(".off('dblclick.rolesEditRow', 'tbody tr:not(.child)')")
        ->toContain("$(this).find('.js-edit-record').get(0)")
        ->toContain('editLink.click();')
        ->toContain('td.dt-select, td.dtr-control')
        ->not->toContain('admin.roles.edit')
        ->toContain(".off('click.rolesSelectStop mousedown.rolesSelectStop mouseup.rolesSelectStop', '.js-record-select, #select_all_records, td.dt-select')")
        ->toContain('$.fn.DataTable.isDataTable($table[0])')
        ->toContain("$('#bulk_actions_bar')")
        ->toContain("$('#bulk_action_select')")
        ->toContain("$('#bulk_action_apply')")
        ->toContain("$('#bulk_selected_count')")
        ->toContain(".off('click.rolesBulk')")
        ->toContain(".off('click.rolesDelete'")
        ->toContain(".off('click.rolesRestore'")
        ->toContain('data-role-restore-url')
        ->toContain('data: { doc_nums: docNums }')
        ->toContain('target: responsiveControlTarget')
        ->toContain("data: 'checkbox', name: 'checkbox'")
        ->toContain("className: 'dt-select no-colvis all align-middle text-center'")
        ->toContain("data: 'doc_num', name: 'roles.doc_number'")
        ->toContain('stateLoadParams')
        ->toContain('stateSaveParams')
        ->toContain('showProtectedColumns')
        ->toContain('window.AppDataTables.options')
        ->toContain("className: 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control'")
        ->toContain("className: 'dt-actions no-colvis all'")
        ->toContain('window.AppAlerts')
        ->toContain('window.AppAlerts.toast(icon, title)')
        ->toContain('showCloseButton: true')
        ->toContain('focusCancel: true')
        ->toContain('allowEscapeKey: true')
        ->toContain('restoreConfirmTitle')
        ->toContain('restoreConfirmText')
        ->toContain('restoreConfirmYes')
        ->toContain("confirmButtonColor: options.confirmButtonColor || '#d33'")
        ->toContain("confirmButtonColor: '#00a65a'")
        ->toContain("cancelButtonText: options.cancelButtonText || messages.no || ''")
        ->toContain("cancelButtonColor: '#748194'")
        ->toContain('dt-ellipsis')
        ->toContain('applyFalconEnhancements');

    $dataTablesDefaultsScript = file_get_contents(public_path('assets/js/modules/Core/datatables-defaults.js'));

    expect($dataTablesDefaultsScript)
        ->toContain('lengthMenuValues = [10, 25, 50, 75, 100, 125, 150, 200, 225, 250, 275, 300]')
        ->toContain('lengthMenuValues.slice()')
        ->not->toContain('-1]')
        ->not->toContain('allLabel')
        ->toContain("type: 'inline'")
        ->toContain('target: 1')
        ->toContain("extend: 'colvis'")
        ->toContain('columns: noColvisSelector')
        ->toContain("noColvisSelector = ':not(.no-colvis)'")
        ->toContain("className: 'btn btn-falcon-default btn-sm'")
        ->toContain('columnVisibilityButton')
        ->toContain('protectStateColumns')
        ->toContain('showColumns');

    $userCss = file_get_contents(public_path('assets/css/user.css'));

    expect($userCss)
        ->toContain('table.erp-datatable.dataTable.dtr-inline.collapsed > tbody > tr > td.dtr-control::before')
        ->toContain('html[dir="rtl"] table.erp-datatable.dataTable.dtr-inline.collapsed > tbody > tr > td.dtr-control')
        ->not->toContain('table.erp-datatable.dataTable.dtr-column.collapsed')
        ->not->toContain('html[dir="rtl"] table.erp-datatable.dataTable.dtr-column.collapsed')
        ->toContain('direction: ltr;')
        ->toContain('text-align: left !important;')
        ->toContain('margin-right: .5rem;')
        ->toContain('.swal2-container.erp-swal-toast-container')
        ->toContain('height: auto !important;')
        ->toContain('pointer-events: none;')
        ->toContain('.erp-swal-toast-popup')
        ->toContain('html[dir="ltr"] .roles-datatable-card .roles-toolbar-actions')
        ->toContain('html[dir="ltr"] .roles-datatable-card .roles-bulk-actions-bar')
        ->toContain('html[dir="ltr"] .roles-datatable-card #bulk_action_select');

    $shortcutsScript = file_get_contents(public_path('assets/js/modules/Core/shortcuts.js'));

    expect($shortcutsScript)
        ->toContain("const navbarSearchSelector = '#navbar_search_input'")
        ->toContain("const dataTableSearchSelector = '.dataTables_filter input[type=\"search\"], .dt-search input[type=\"search\"]'")
        ->toContain('focusNavbarSearch')
        ->toContain('focusDataTableSearch')
        ->toContain('isTypingTarget')
        ->toContain('isRichTypingTarget')
        ->toContain('clickTarget')
        ->toContain('clickShortcutAction')
        ->toContain('shortcutActionSelector')
        ->toContain('shortcutTarget(action, { allowHidden: true })')
        ->toContain('form.save_view')
        ->toContain('form.save_clone')
        ->toContain("0: 'form.back'")
        ->toContain("1: 'form.save'")
        ->toContain("9: 'form.delete'")
        ->not->toContain('isCtrlN')
        ->not->toContain("['KeyS']")
        ->toContain("const bulkApplyButtonId = 'bulk_action_apply'")
        ->toContain('isBulkApply')
        ->toContain('canApplyBulkAction')
        ->toContain('isBulkActionSelectTarget')
        ->toContain('selectedBulkRecordsCount')
        ->toContain('clickTarget(bulkApplyButtonId)')
        ->toContain('logoutButtonId')
        ->toContain('lockScreenButtonId')
        ->toContain('isAltQ')
        ->toContain('isAltK')
        ->toContain('applyDataTableSearchTitles');

    expect(public_path('vendors/datatables.net-buttons/js/dataTables.buttons.min.js'))->toBeFile()
        ->and(public_path('vendors/datatables.net-buttons/js/buttons.colVis.min.js'))->toBeFile()
        ->and(public_path('vendors/datatables.net-buttons-bs5/js/buttons.bootstrap5.min.js'))->toBeFile()
        ->and(public_path('vendors/datatables.net-buttons-bs5/css/buttons.bootstrap5.min.css'))->toBeFile()
        ->and(public_path('vendors/datatables.net-responsive/js/dataTables.responsive.min.js'))->toBeFile()
        ->and(public_path('vendors/datatables.net-responsive-bs5/js/responsive.bootstrap5.min.js'))->toBeFile()
        ->and(public_path('vendors/datatables.net-responsive-bs5/css/responsive.bootstrap5.min.css'))->toBeFile();
});

test('role document number settings are permission gated and update future numbers only', function () {
    $viewer = userWithPermissions(['roles.view']);

    $this->actingAs($viewer)
        ->get(route('admin.roles.index'))
        ->assertOk()
        ->assertDontSee(__('roles.document_number_settings.title'));

    $this->actingAs($viewer)
        ->putJson(route('admin.roles.document-number-settings.update'), [
            'prefix' => '',
            'padding' => 0,
        ])
        ->assertForbidden();

    $user = userWithPermissions([
        'roles.view',
        'roles.create',
        'roles.edit',
        'roles.document_number_settings.update',
    ]);

    $this->actingAs($user)
        ->get(route('admin.roles.index'))
        ->assertOk()
        ->assertSee(__('roles.document_number_settings.title'))
        ->assertSee('id="roles-document-number-settings"', false)
        ->assertSee('class="collapse"', false)
        ->assertSee(route('admin.roles.document-number-settings.update'), false);

    $this->putJson(route('admin.roles.document-number-settings.update'), [
        'prefix' => '',
        'padding' => 0,
    ])
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => __('roles.document_number_settings.updated_successfully'),
            'data' => [
                'prefix' => '',
                'padding' => 0,
            ],
        ]);

    $settingsActivity = Activity::query()
        ->where('action', 'roles.document_number_settings.update')
        ->firstOrFail();

    $settingsProperties = $settingsActivity->properties->toArray();

    expect(data_get($settingsProperties, 'changes.prefix.old'))->toBe('Role-');
    expect(data_get($settingsProperties, 'changes.prefix.new'))->toBeNull();
    expect(data_get($settingsProperties, 'changes.padding.old'))->toBe(5);
    expect(data_get($settingsProperties, 'changes.padding.new'))->toBe(0);
    expect($settingsActivity->properties->has('role_id'))->toBeFalse();

    $this->assertDatabaseHas('settings', [
        'key' => 'document_numbers.roles.prefix',
        'value' => '',
    ]);
    $this->assertDatabaseHas('settings', [
        'key' => 'document_numbers.roles.padding',
        'value' => '0',
    ]);

    $this->postJson(route('admin.roles.store'), [
        'name' => 'plain-number-role',
        'permissions' => [],
    ])
        ->assertOk()
        ->assertJsonPath('data.doc_num', '1');

    $firstRole = Role::query()->where('name', 'plain-number-role')->firstOrFail();

    expect($firstRole->doc_number)->toBe(1);
    expect($firstRole->doc_num)->toBe('1');

    $this->putJson(route('admin.roles.document-number-settings.update'), [
        'prefix' => 'Role-',
        'padding' => 5,
    ])
        ->assertOk()
        ->assertJsonPath('data.prefix', 'Role-')
        ->assertJsonPath('data.padding', 5);

    $this->actingAs(userWithPermissions(['roles.create']))
        ->postJson(route('admin.roles.store'), [
            'name' => 'padded-number-role',
            'permissions' => [],
        ])
        ->assertOk()
        ->assertJsonPath('data.doc_num', 'Role-00002');

    $firstRole->refresh();
    $secondRole = Role::query()->where('name', 'padded-number-role')->firstOrFail();

    expect($firstRole->doc_num)->toBe('1');
    expect($secondRole->doc_number)->toBe(2);
    expect($secondRole->doc_num)->toBe('Role-00002');
});

test('role company access permission is discovered and admin remains unrestricted', function () {
    $this->seed(PermissionSeeder::class);

    $adminRole = Role::query()
        ->where('name', 'admin')
        ->where('guard_name', 'web')
        ->firstOrFail();
    $adminUser = User::factory()->create();
    $company = Company::factory()->create();

    $adminUser->assignRole($adminRole);

    expect(Permission::query()->where('name', 'roles.operating_scope.manage')->exists())->toBeTrue()
        ->and($adminRole->hasPermissionTo('roles.operating_scope.manage'))->toBeTrue()
        ->and($adminRole->company_access_restricted)->toBeFalse()
        ->and(app(CompanyAccessService::class)->accessibleCompanyIdsFor($adminUser))->toBeNull()
        ->and(app(CompanyAccessService::class)->canAccessCompany($adminUser, $company))->toBeTrue();
});

test('protected first role edit page is read only and hides operating scope assignments', function () {
    allowMultipleCompaniesForRoleTests();

    $editor = userWithPermissions([
        'roles.view',
        'roles.edit',
        'roles.clone',
        'roles.delete',
        'roles.document_number.control',
        'roles.operating_scope.manage',
    ]);
    Permission::findOrCreate('users.view', 'web');

    $company = Company::factory()->create([
        'doc_number' => 611,
        'doc_num' => 'Company-00611',
        'name' => 'Protected Scope Company',
    ]);
    $branch = Branch::query()->create([
        'doc_number' => 611,
        'doc_num' => 'Branch-00611',
        'company_id' => $company->id,
        'name' => 'Protected Scope Branch',
        'type' => 'warehouse',
        'status' => 'active',
    ]);
    $period = FinancialPeriod::query()->create([
        'doc_number' => 611,
        'doc_num' => 'Period-00611',
        'company_id' => $company->id,
        'name' => 'Protected FY 2026',
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
    ]);
    $protected = protectedRoleFixture([
        'name' => 'first-main-role',
        'notes' => 'Protected notes',
        'company_access_restricted' => true,
        'branch_access_restricted' => true,
        'financial_period_access_restricted' => true,
    ]);

    $protected->givePermissionTo('users.view');
    $protected->companyAccessCompanies()->sync([$company->id]);
    $protected->branchAccessBranches()->sync([$branch->id]);
    $protected->financialPeriodAccessPeriods()->sync([$period->id]);

    $this->actingAs($editor)
        ->get(route('admin.roles.edit', $protected->doc_num))
        ->assertOk()
        ->assertSee(__('roles.messages.protected_readonly_warning'))
        ->assertSee('data-protected-role-alert', false)
        ->assertSee('id="role-doc-number"', false)
        ->assertSee('id="role-name"', false)
        ->assertSee('id="role-notes"', false)
        ->assertSee('readonly', false)
        ->assertSee('disabled', false)
        ->assertDontSee('name="doc_number"', false)
        ->assertDontSee('name="name"', false)
        ->assertDontSee('name="notes"', false)
        ->assertSee('value="users.view"', false)
        ->assertSee('name="permissions[]"', false)
        ->assertSee('disabled', false)
        ->assertSee(__('roles.operating_scope.all_companies'))
        ->assertSee(__('roles.operating_scope.all_branches'))
        ->assertSee(__('roles.operating_scope.all_financial_periods'))
        ->assertDontSee('name="accessible_company_doc_nums[]"', false)
        ->assertDontSee('name="accessible_branch_doc_nums[]"', false)
        ->assertDontSee('name="accessible_financial_period_doc_nums[]"', false)
        ->assertSee('data-shortcut-action="form.back"', false)
        ->assertDontSee('js-role-submit-action', false)
        ->assertDontSee('data-submit-action="save"', false)
        ->assertDontSee('data-shortcut-action="form.save"', false);
});

test('protected first role update is blocked without mutating permissions notes or operating scope', function () {
    allowMultipleCompaniesForRoleTests();

    $editor = userWithPermissions([
        'roles.edit',
        'roles.document_number.control',
        'roles.operating_scope.manage',
    ]);
    Permission::findOrCreate('users.view', 'web');
    Permission::findOrCreate('roles.view', 'web');

    $originalCompany = Company::factory()->create([
        'doc_number' => 621,
        'doc_num' => 'Company-00621',
        'name' => 'Original Protected Company',
    ]);
    $newCompany = Company::factory()->create([
        'doc_number' => 622,
        'doc_num' => 'Company-00622',
        'name' => 'New Protected Company',
    ]);
    $originalBranch = Branch::query()->create([
        'doc_number' => 621,
        'doc_num' => 'Branch-00621',
        'company_id' => $originalCompany->id,
        'name' => 'Original Protected Branch',
        'type' => 'warehouse',
        'status' => 'active',
    ]);
    $newBranch = Branch::query()->create([
        'doc_number' => 622,
        'doc_num' => 'Branch-00622',
        'company_id' => $newCompany->id,
        'name' => 'New Protected Branch',
        'type' => 'warehouse',
        'status' => 'active',
    ]);
    $originalPeriod = FinancialPeriod::query()->create([
        'doc_number' => 621,
        'doc_num' => 'Period-00621',
        'company_id' => $originalCompany->id,
        'name' => 'Original Protected FY',
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
    ]);
    $newPeriod = FinancialPeriod::query()->create([
        'doc_number' => 622,
        'doc_num' => 'Period-00622',
        'company_id' => $newCompany->id,
        'name' => 'New Protected FY',
        'from_date' => '2027-01-01',
        'to_date' => '2027-12-31',
        'is_closed' => false,
    ]);
    $protected = protectedRoleFixture([
        'name' => 'first-protected-role',
        'notes' => 'Original notes',
        'company_access_restricted' => true,
        'branch_access_restricted' => true,
        'financial_period_access_restricted' => true,
    ]);
    $protected->givePermissionTo('users.view');
    $protected->companyAccessCompanies()->sync([$originalCompany->id]);
    $protected->branchAccessBranches()->sync([$originalBranch->id]);
    $protected->financialPeriodAccessPeriods()->sync([$originalPeriod->id]);

    $this->actingAs($editor)
        ->putJson(route('admin.roles.update', $protected->doc_num), [
            'name' => 'tampered-protected-role',
            'doc_number' => 99,
            'notes' => 'Tampered notes',
            'permissions' => ['roles.view'],
            'accessible_company_doc_nums' => [$newCompany->doc_num],
            'accessible_branch_doc_nums' => [$newBranch->doc_num],
            'accessible_financial_period_doc_nums' => [$newPeriod->doc_num],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role'])
        ->assertJsonPath('errors.role.0', __('roles.messages.protected_update_blocked'));

    $protected->refresh();

    expect($protected->name)->toBe('first-protected-role')
        ->and($protected->doc_number)->toBe(0)
        ->and($protected->doc_num)->toBe('Role-00000')
        ->and($protected->notes)->toBe('Original notes')
        ->and($protected->permissions()->pluck('name')->values()->all())->toBe(['users.view'])
        ->and($protected->accessibleCompanies()->pluck('companies.doc_num')->values()->all())->toBe([$originalCompany->doc_num])
        ->and($protected->accessibleBranches()->pluck('branches.doc_num')->values()->all())->toBe([$originalBranch->doc_num])
        ->and($protected->accessibleFinancialPeriods()->pluck('financial_periods.doc_num')->values()->all())->toBe([$originalPeriod->doc_num])
        ->and(Activity::query()->whereIn('action', [
            'roles.update',
            'roles.permissions.sync',
            'roles.doc_number.changed',
            'roles.operating_scope.sync',
        ])->exists())->toBeFalse();
});

test('normal role remains editable after the first protected role', function () {
    protectedRoleFixture();

    $editor = userWithPermissions(['roles.edit', 'roles.view']);
    Permission::findOrCreate('users.view', 'web');
    $role = Role::query()->create([
        'name' => 'editable-role',
        'guard_name' => 'web',
        'doc_number' => 1,
        'doc_num' => 'Role-00001',
    ]);

    $this->actingAs($editor)
        ->get(route('admin.roles.edit', $role->doc_num))
        ->assertOk()
        ->assertDontSee(__('roles.messages.protected_readonly_warning'))
        ->assertSee('name="name"', false)
        ->assertSee('data-submit-action="save"', false);

    $this->putJson(route('admin.roles.update', $role->doc_num), [
        'name' => 'editable-role-updated',
        'notes' => 'Still editable',
        'permissions' => ['users.view'],
    ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $role->refresh();

    expect($role->name)->toBe('editable-role-updated')
        ->and($role->notes)->toBe('Still editable')
        ->and($role->permissions()->pluck('name')->values()->all())->toBe(['users.view']);
});

test('first protected role cannot be cloned deleted or bulk deleted by id rule', function () {
    $user = userWithPermissions(['roles.clone', 'roles.delete']);
    $protected = protectedRoleFixture(['name' => 'first-role-by-id']);
    $other = Role::query()->create([
        'name' => 'bulk-normal-role',
        'guard_name' => 'web',
        'doc_number' => 1,
        'doc_num' => 'Role-00001',
    ]);

    $this->actingAs($user)
        ->get(route('admin.roles.clone', $protected->doc_num))
        ->assertRedirect(route('admin.roles.index'))
        ->assertSessionHas('error', __('roles.messages.clone_not_allowed'));

    $this->actingAs($user)
        ->withSession(['roles.clone_sources.first-role-token' => $protected->doc_num])
        ->postJson(route('admin.roles.store'), [
            'clone_source_token' => 'first-role-token',
            'name' => 'Copy of first role',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name'])
        ->assertJsonPath('errors.name.0', __('roles.messages.clone_not_allowed'));

    $this->actingAs($user)
        ->deleteJson(route('admin.roles.destroy', $protected->doc_num))
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', __('auth.roles.messages.cannot_delete_admin'));

    $this->actingAs($user)
        ->deleteJson(route('admin.roles.bulk-delete'), ['doc_nums' => [$protected->doc_num, $other->doc_num]])
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('data.blocked_records.0.doc_num', 'Role-00000')
        ->assertJsonPath('data.blocked_records.0.reason', 'admin_role');

    expect(Role::query()->whereKey($protected->id)->exists())->toBeTrue()
        ->and(Role::query()->whereKey($other->id)->exists())->toBeTrue()
        ->and(Role::query()->where('name', 'Copy of first role')->exists())->toBeFalse()
        ->and(Activity::query()->where('action', 'roles.clone')->exists())->toBeFalse()
        ->and(Activity::query()->where('action', 'roles.delete')->exists())->toBeFalse()
        ->and(Activity::query()->where('action', 'roles.bulk_delete')->exists())->toBeFalse();
});

test('roles index and datatable draws do not write activity while view still does', function () {
    $user = userWithPermissions(['roles.view']);
    $role = Role::query()->create([
        'name' => 'manager',
        'guard_name' => 'web',
        'doc_number' => 1,
        'doc_num' => 'Role-00001',
    ]);

    $this->actingAs($user)
        ->get(route('admin.roles.index'))
        ->assertOk();

    expect(Activity::query()->where('action', 'roles.index')->exists())->toBeFalse();

    $this->getJson(route('admin.roles.data', [
        'draw' => 1,
        'start' => 0,
        'length' => 10,
        'search' => ['value' => 'manager'],
    ]))
        ->assertOk();

    expect(Activity::query()->where('action', 'roles.index')->exists())->toBeFalse();
    expect(Activity::query()->where('action', 'roles.view')->count())->toBe(0);

    $this->actingAs($user)
        ->get(route('admin.roles.show', $role->doc_num))
        ->assertOk();

    $viewActivity = Activity::query()->where('action', 'roles.view')->firstOrFail();

    expect($viewActivity->properties->get('doc_num'))->toBe('Role-00001');
    expect($viewActivity->properties->get('role_name'))->toBe('manager');
    expect($viewActivity->properties->has('id'))->toBeFalse();
    expect($viewActivity->properties->has('role_id'))->toBeFalse();
});

test('roles datatable returns server side data and uses builtin search', function () {
    $user = userWithPermissions(['roles.view']);

    Role::query()->create([
        'name' => 'manager',
        'notes' => 'Factory role',
        'guard_name' => 'web',
        'doc_number' => 1,
        'doc_num' => 'Role-00001',
        'created_by' => $user->id,
        'updated_by' => $user->id,
        'created_at' => CarbonImmutable::create(2026, 4, 28, 10, 15),
        'updated_at' => CarbonImmutable::create(2026, 4, 28, 10, 15),
    ]);
    Role::query()->create([
        'name' => 'accountant',
        'notes' => 'Finance role',
        'guard_name' => 'web',
        'doc_number' => 2,
        'doc_num' => 'Role-00002',
        'created_at' => CarbonImmutable::create(2026, 4, 27, 9, 0),
        'updated_at' => CarbonImmutable::create(2026, 4, 27, 9, 0),
    ]);
    Role::query()->create([
        'name' => 'admin',
        'notes' => 'Security role',
        'guard_name' => 'web',
        'doc_number' => 12,
        'doc_num' => 'Role-00012',
        'created_at' => CarbonImmutable::create(2026, 4, 28, 12, 30),
        'updated_at' => CarbonImmutable::create(2026, 4, 28, 12, 30),
    ]);

    $response = $this->actingAs($user)
        ->getJson(route('admin.roles.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'manager'],
        ]))
        ->assertOk()
        ->assertJsonPath('recordsFiltered', 1);

    $row = $response->json('data.0');

    expect($row['name'])->toContain('dt-ellipsis-content')
        ->and($row['name'])->toContain('title="manager"')
        ->and($row['created_by'])->toContain('dt-ellipsis-content')
        ->and($row['created_by'])->toContain('title="'.e($user->name).'"');

    $this->getJson(route('admin.roles.data', [
        'draw' => 2,
        'start' => 0,
        'length' => 10,
        'search' => ['value' => 'Role-00001'],
    ]))
        ->assertOk()
        ->assertJsonPath('recordsFiltered', 1);

    $this->getJson(route('admin.roles.data', [
        'draw' => 3,
        'start' => 0,
        'length' => 10,
        'search' => ['value' => 'admin&&Role-00012'],
    ]))
        ->assertOk()
        ->assertJsonPath('recordsFiltered', 1);

    $this->getJson(route('admin.roles.data', [
        'draw' => 4,
        'start' => 0,
        'length' => 10,
        'search' => ['value' => '28/04/2026'],
    ]))
        ->assertOk()
        ->assertJsonPath('recordsFiltered', 2);

    $this->actingAs(userWithPermissions(['roles.view']))
        ->getJson(route('admin.roles.data', [
            'draw' => 5,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'admin&&28/04/2026'],
        ]))
        ->assertOk()
        ->assertJsonPath('recordsFiltered', 1);

    $this->actingAs(userWithPermissions(['roles.view']))
        ->getJson(route('admin.roles.data', [
            'draw' => 6,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'unknown&&admin'],
        ]))
        ->assertOk()
        ->assertJsonPath('recordsFiltered', 0);

    $this->actingAs(userWithPermissions(['roles.view']))
        ->getJson(route('admin.roles.data', [
            'draw' => 7,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => '%'],
        ]))
        ->assertOk()
        ->assertJsonPath('recordsFiltered', 0);

    $this->actingAs(userWithPermissions(['roles.view']))
        ->getJson(route('admin.roles.data', [
            'draw' => 8,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'adminMBv'],
        ]))
        ->assertOk()
        ->assertJsonPath('recordsFiltered', 0);

    $this->actingAs(userWithPermissions(['roles.view']))
        ->getJson(route('admin.roles.data', [
            'draw' => 9,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'admin&&MBv'],
        ]))
        ->assertOk()
        ->assertJsonPath('recordsFiltered', 0);
});

test('roles datatable leaves empty optional notes blank', function () {
    $user = userWithPermissions(['roles.view']);

    Role::query()->create([
        'name' => 'empty-notes-role',
        'notes' => null,
        'guard_name' => 'web',
        'doc_number' => 31,
        'doc_num' => 'Role-00031',
    ]);

    $row = $this->actingAs($user)
        ->getJson(route('admin.roles.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'empty-notes-role'],
        ]))
        ->assertOk()
        ->json('data.0');

    expect(trim($row['notes']))->toBe('')
        ->and($row['notes'])->not->toContain(__('common.messages.not_available'))
        ->and($row['notes'])->not->toContain('-');
});

test('roles view shows placeholders for empty notes and audit fields', function () {
    $user = userWithPermissions(['roles.view']);
    $role = Role::query()->create([
        'name' => 'blank-view-role',
        'notes' => null,
        'guard_name' => 'web',
        'doc_number' => 32,
        'doc_num' => 'Role-00032',
        'updated_by' => null,
        'updated_at' => null,
    ]);

    $this->actingAs($user)
        ->get(route('admin.roles.show', $role->doc_num))
        ->assertOk()
        ->assertSee('id="role-notes"', false)
        ->assertSee('>'.__('common.empty_value').'</textarea>', false)
        ->assertSee('value="'.__('common.empty_value').'"', false)
        ->assertDontSee('value="'.__('common.messages.not_available').'"', false)
        ->assertDontSee('>'.__('common.messages.not_available').'<', false);
});

test('roles datatable trash filter is permission gated and limits trashed row actions', function () {
    protectedRoleFixture();

    $active = Role::query()->create([
        'name' => 'active-role',
        'notes' => 'Active role',
        'guard_name' => 'web',
        'doc_number' => 1,
        'doc_num' => 'Role-00001',
    ]);
    $trashed = Role::query()->create([
        'name' => 'trashed-role',
        'notes' => 'Deleted role',
        'guard_name' => 'web',
        'doc_number' => 2,
        'doc_num' => 'Role-00002',
    ]);
    $trashed->delete();
    $deleter = User::factory()->create([
        'name' => 'Role Deleter',
        'doc_num' => 'User-00911',
    ]);
    $trashed->forceFill(['deleted_by' => $deleter->id])->saveQuietly();
    $trashed = Role::withTrashed()->whereKey($trashed->getKey())->firstOrFail();

    $viewer = userWithPermissions(['roles.view']);

    $this->actingAs($viewer)
        ->getJson(route('admin.roles.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'trash_filter' => 'trashed',
            'search' => ['value' => 'active-role'],
        ]))
        ->assertOk()
        ->assertJsonPath('recordsFiltered', 1)
        ->assertJsonPath('data.0.name', fn (string $name): bool => str_contains($name, 'active-role'));

    $this->get(route('admin.roles.show', $trashed->doc_num))
        ->assertNotFound();

    $trashViewer = userWithPermissions([
        'roles.view',
        'roles.view_trashed',
        'roles.restore',
        'roles.edit',
        'roles.clone',
        'roles.delete',
    ]);

    $activeResponse = $this->actingAs($trashViewer)
        ->getJson(route('admin.roles.data', [
            'draw' => 2,
            'start' => 0,
            'length' => 10,
            'trash_filter' => 'active',
            'search' => ['value' => 'active-role'],
        ]))
        ->assertOk()
        ->assertJsonPath('recordsFiltered', 1);
    $activeRow = $activeResponse->json('data.0');

    expect($activeRow['name'])->toContain('active-role')
        ->and($activeRow['checkbox'])->toContain('value="Role-00001"')
        ->and($activeRow['actions'])->toContain('js-edit-record')
        ->and($activeRow['actions'])->toContain('js-clone-record')
        ->and($activeRow['actions'])->toContain('data-role-delete-url');

    $trashedResponse = $this->getJson(route('admin.roles.data', [
        'draw' => 3,
        'start' => 0,
        'length' => 10,
        'trash_filter' => 'trashed',
        'search' => ['value' => 'trashed-role'],
    ]))
        ->assertOk()
        ->assertJsonPath('recordsFiltered', 1);
    $trashedRow = $trashedResponse->json('data.0');

    expect($trashedRow['name'])->toContain('trashed-role')
        ->and($trashedRow['doc_num'])->toContain('Role-00002')
        ->and($trashedRow['doc_num'])->toContain(route('admin.roles.show', $trashed->doc_num))
        ->and($trashedRow['checkbox'])->not->toContain('js-record-select')
        ->and($trashedRow['actions'])->toContain(route('admin.roles.show', $trashed->doc_num))
        ->and($trashedRow['actions'])->toContain('data-role-restore-url')
        ->and($trashedRow['actions'])->toContain('data-restore-url')
        ->and($trashedRow['actions'])->toContain('js-restore-record')
        ->and($trashedRow['actions'])->toContain(route('admin.roles.restore', $trashed->doc_num))
        ->and($trashedRow['actions'])->not->toContain('js-edit-record')
        ->and($trashedRow['actions'])->not->toContain('js-clone-record')
        ->and($trashedRow['actions'])->not->toContain('data-role-delete-url');

    $this->getJson(route('admin.roles.data', [
        'draw' => 4,
        'start' => 0,
        'length' => 10,
        'trash_filter' => 'all',
    ]))
        ->assertOk()
        ->assertJsonPath('recordsFiltered', 3);

    $this->get(route('admin.roles.show', $trashed->doc_num))
        ->assertOk()
        ->assertSee(__('common.fields.created_by'))
        ->assertSee(__('common.fields.created_at'))
        ->assertSee(__('common.fields.updated_by'))
        ->assertSee(__('common.fields.updated_at'))
        ->assertSee(__('common.fields.deleted_by'))
        ->assertSee(__('common.fields.deleted_at'))
        ->assertDontSee(__('common.fields.restored_by'))
        ->assertDontSee(__('common.fields.restored_at'))
        ->assertSee('Role Deleter / User-00911')
        ->assertSee(app(SettingService::class)->formatDateTime($trashed->deleted_at))
        ->assertDontSee('value="'.$deleter->id.'"', false)
        ->assertDontSee('data-id=', false)
        ->assertSee('data-role-restore-url', false)
        ->assertSee('data-restore-url', false)
        ->assertSee(__('roles.trash.restore'))
        ->assertDontSee('data-shortcut-action="form.edit"', false)
        ->assertDontSee('data-shortcut-action="form.clone"', false)
        ->assertDontSee('data-role-delete-url', false)
        ->assertDontSee('data-shortcut-action="form.delete"', false);

    $this->get(route('admin.roles.edit', $trashed->doc_num))
        ->assertNotFound();

    $this->get(route('admin.roles.clone', $trashed->doc_num))
        ->assertNotFound();

    $this->putJson(route('admin.roles.update', $trashed->doc_num), [
        'name' => 'should-not-update',
        'permissions' => [],
    ])
        ->assertNotFound();

    $this->deleteJson(route('admin.roles.destroy', $trashed->doc_num))
        ->assertNotFound();

    expect(Role::query()->whereKey($active->getKey())->exists())->toBeTrue();
});

test('trashed role can be restored by public document number and logs public activity', function () {
    $restorer = userWithPermissions(['roles.restore']);
    $updater = User::factory()->create();
    $previousUpdatedAt = now()->subHours(5)->startOfSecond();
    $role = Role::query()->create([
        'name' => 'restorable-role',
        'guard_name' => 'web',
        'doc_number' => 1,
        'doc_num' => 'Role-00001',
        'updated_by' => $updater->id,
        'updated_at' => $previousUpdatedAt,
    ]);
    $role->forceFill(['deleted_by' => $restorer->id])->saveQuietly();
    $role->delete();
    $role->getConnection()
        ->table($role->getTable())
        ->where($role->getKeyName(), $role->getKey())
        ->update([
            'updated_by' => $updater->id,
            'updated_at' => $previousUpdatedAt,
            'deleted_by' => $restorer->id,
        ]);

    $this->actingAs(userWithPermissions(['roles.view_trashed']))
        ->patchJson(route('admin.roles.restore', $role->doc_num))
        ->assertForbidden();

    $this->actingAs($restorer)
        ->patchJson(route('admin.roles.restore', $role->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', __('roles.messages.restored_successfully'));

    $role->refresh();
    $activity = Activity::query()->where('action', 'roles.restore')->firstOrFail();

    expect($role->trashed())->toBeFalse()
        ->and($role->deleted_by)->toBeNull()
        ->and($role->deleted_at)->toBeNull()
        ->and($role->restored_by)->toBe($restorer->id)
        ->and($role->restored_at)->not->toBeNull()
        ->and((int) $role->updated_by)->toBe($updater->id)
        ->and($role->updated_at?->toDateTimeString())->toBe($previousUpdatedAt->toDateTimeString())
        ->and($activity->module)->toBe('auth')
        ->and(data_get($activity->properties->toArray(), 'record.doc_num'))->toBe('Role-00001')
        ->and(data_get($activity->properties->toArray(), 'record.label'))->toBe('restorable-role')
        ->and(data_get($activity->properties->toArray(), 'meta.guard_name'))->toBe('web')
        ->and(data_get($activity->properties->toArray(), 'meta.restored_by_user_doc_num'))->toBe($restorer->doc_num)
        ->and(data_get($activity->properties->toArray(), 'meta.restored_at'))->not->toBeNull()
        ->and($activity->properties->has('id'))->toBeFalse()
        ->and($activity->properties->has('role_id'))->toBeFalse();

    $this->actingAs(userWithPermissions(['roles.view']))
        ->get(route('admin.roles.show', $role->doc_num))
        ->assertOk()
        ->assertSee(__('common.fields.restored_by'))
        ->assertSee(__('common.fields.restored_at'))
        ->assertSee($restorer->name.' / '.$restorer->doc_num)
        ->assertDontSee(__('common.fields.deleted_by'))
        ->assertDontSee(__('common.fields.deleted_at'))
        ->assertDontSee('value="'.$restorer->id.'"', false);

    $this->actingAs($restorer)
        ->patchJson(route('admin.roles.restore', $role->doc_num))
        ->assertUnprocessable()
        ->assertJsonPath('message', __('roles.messages.restore_not_allowed'))
        ->assertJsonPath('errors.restore.0', __('roles.messages.restore_not_allowed'))
        ->assertJsonMissingPath('data.id')
        ->assertJsonMissingPath('data.role_id');
});

test('trashed role restore is blocked when an active role reuses its name and guard', function () {
    $restorer = userWithPermissions(['roles.restore']);
    $trashed = Role::query()->create([
        'name' => 'duplicate-role',
        'guard_name' => 'web',
        'doc_number' => 1,
        'doc_num' => 'Role-00001',
    ]);
    $trashed->delete();
    Role::query()->create([
        'name' => 'duplicate-role',
        'guard_name' => 'web',
        'doc_number' => 2,
        'doc_num' => 'Role-00002',
    ]);

    $this->actingAs($restorer)
        ->patchJson(route('admin.roles.restore', $trashed->doc_num))
        ->assertUnprocessable()
        ->assertJsonPath('message', __('roles.messages.restore_conflict'))
        ->assertJsonPath('errors.restore.0', __('roles.messages.restore_conflict'))
        ->assertJsonPath('data.conflict_type', 'role_name_conflict')
        ->assertJsonPath('data.conflict_fields', ['name', 'guard_name'])
        ->assertJsonMissingPath('data.id')
        ->assertJsonMissingPath('data.role_id');

    $blockedActivity = Activity::query()->where('action', 'roles.restore_blocked')->firstOrFail();

    expect(Role::withTrashed()->whereKey($trashed->getKey())->firstOrFail()->trashed())->toBeTrue();
    expect($blockedActivity->status)->toBe('blocked')
        ->and($blockedActivity->properties->get('doc_num'))->toBe('Role-00001')
        ->and($blockedActivity->properties->get('role_name'))->toBe('duplicate-role')
        ->and($blockedActivity->properties->get('conflict_type'))->toBe('role_name_conflict')
        ->and($blockedActivity->properties->get('conflict_fields'))->toBe(['name', 'guard_name'])
        ->and($blockedActivity->properties->has('id'))->toBeFalse()
        ->and($blockedActivity->properties->has('role_id'))->toBeFalse();
});

test('trashed role restore is blocked when an active role reuses its document number', function () {
    $restorer = userWithPermissions(['roles.restore']);
    $trashed = Role::query()->create([
        'name' => 'deleted-doc-number-role',
        'guard_name' => 'web',
        'doc_number' => 10,
        'doc_num' => 'Role-00010',
    ]);
    $trashed->delete();
    Role::query()->create([
        'name' => 'active-doc-number-role',
        'guard_name' => 'web',
        'doc_number' => 10,
        'doc_num' => 'Role-00011',
    ]);

    $this->actingAs($restorer)
        ->patchJson(route('admin.roles.restore', $trashed->doc_num))
        ->assertUnprocessable()
        ->assertJsonPath('message', __('roles.messages.restore_conflict'))
        ->assertJsonPath('errors.restore.0', __('roles.messages.restore_conflict'))
        ->assertJsonPath('data.conflict_type', 'document_number_conflict')
        ->assertJsonPath('data.conflict_fields', ['doc_number'])
        ->assertJsonMissingPath('data.id')
        ->assertJsonMissingPath('data.role_id');

    expect(Role::withTrashed()->whereKey($trashed->getKey())->firstOrFail()->trashed())->toBeTrue();
});

test('trashed role restore is blocked when an active role reuses its document code', function () {
    $restorer = userWithPermissions(['roles.restore']);
    $trashed = Role::query()->create([
        'name' => 'deleted-doc-code-role',
        'guard_name' => 'web',
        'doc_number' => 20,
        'doc_num' => 'Role-00020',
    ]);
    $trashed->delete();
    Role::query()->create([
        'name' => 'active-doc-code-role',
        'guard_name' => 'web',
        'doc_number' => 21,
        'doc_num' => 'Role-00020',
    ]);

    $this->actingAs($restorer)
        ->patchJson(route('admin.roles.restore', $trashed->doc_num))
        ->assertUnprocessable()
        ->assertJsonPath('message', __('roles.messages.restore_conflict'))
        ->assertJsonPath('errors.restore.0', __('roles.messages.restore_conflict'))
        ->assertJsonPath('data.conflict_type', 'document_code_conflict')
        ->assertJsonPath('data.conflict_fields', ['doc_num'])
        ->assertJsonMissingPath('data.id')
        ->assertJsonMissingPath('data.role_id');

    expect(Role::withTrashed()->whereKey($trashed->getKey())->firstOrFail()->trashed())->toBeTrue();
});

test('role can be created with permissions and generated document number', function () {
    $user = userWithPermissions(['roles.create', 'roles.edit']);
    Permission::findOrCreate('users.view', 'web');

    $this->actingAs($user)
        ->postJson(route('admin.roles.store'), [
            'name' => 'manager',
            'notes' => 'Can manage records',
            'permissions' => ['users.view'],
            'doc_num' => 'Ignored-99999',
            'doc_number' => 99999,
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.doc_num', 'Role-00001')
        ->assertJsonPath('submit_action', 'save_new')
        ->assertJsonPath('reset_form', true)
        ->assertJsonMissingPath('redirect');

    $role = Role::query()->where('name', 'manager')->firstOrFail();

    expect($role->doc_number)->toBe(1);
    expect($role->doc_num)->toBe('Role-00001');
    expect($role->notes)->toBe('Can manage records');
    expect($role->created_by)->toBe($user->id);
    expect($role->created_at)->not->toBeNull();
    expect($role->updated_by)->toBeNull();
    expect($role->updated_at)->toBeNull();
    expect($role->deleted_by)->toBeNull();
    expect($role->deleted_at)->toBeNull();
    expect($role->restored_by)->toBeNull();
    expect($role->restored_at)->toBeNull();
    expect($role->hasPermissionTo('users.view'))->toBeTrue();
});

test('role can be created with company access by public company document numbers', function () {
    allowMultipleCompaniesForRoleTests();

    $user = userWithPermissions(['roles.create', 'roles.operating_scope.manage']);
    $firstCompany = Company::factory()->create([
        'doc_number' => 61,
        'doc_num' => 'Company-00061',
        'name' => 'North Company',
    ]);
    $secondCompany = Company::factory()->create([
        'doc_number' => 62,
        'doc_num' => 'Company-00062',
        'name' => 'South Company',
    ]);

    $this->actingAs($user)
        ->postJson(route('admin.roles.store'), [
            'name' => 'regional-manager',
            'permissions' => [],
            'accessible_company_doc_nums' => [$secondCompany->doc_num, $firstCompany->doc_num],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $role = Role::query()->where('name', 'regional-manager')->firstOrFail();
    $companyDocNums = $role->accessibleCompanies()
        ->pluck('companies.doc_num')
        ->sort()
        ->values()
        ->all();
    $activity = Activity::query()->where('action', 'roles.operating_scope.sync')->firstOrFail();

    expect($role->company_access_restricted)->toBeTrue()
        ->and($companyDocNums)->toBe(['Company-00061', 'Company-00062'])
        ->and($activity->properties->get('role_doc_num'))->toBe($role->doc_num)
        ->and($activity->properties->get('added_company_doc_nums'))->toBe(['Company-00061', 'Company-00062'])
        ->and($activity->properties->get('selected_company_doc_nums'))->toBe(['Company-00061', 'Company-00062'])
        ->and($activity->properties->get('selected_company_count'))->toBe(2)
        ->and($activity->properties->has('role_id'))->toBeFalse()
        ->and($activity->properties->has('company_id'))->toBeFalse();
});

test('role company access select2 and validation use active public company document numbers', function () {
    allowMultipleCompaniesForRoleTests();

    $user = userWithPermissions(['roles.create', 'roles.operating_scope.manage']);
    $activeCompany = Company::factory()->create([
        'doc_number' => 71,
        'doc_num' => 'Company-00071',
        'name' => 'Visible Company',
    ]);
    $inactiveCompany = Company::factory()->inactive()->create([
        'doc_number' => 72,
        'doc_num' => 'Company-00072',
        'name' => 'Inactive Company',
    ]);
    $deletedCompany = Company::factory()->create([
        'doc_number' => 73,
        'doc_num' => 'Company-00073',
        'name' => 'Deleted Company',
    ]);
    $deletedCompany->delete();

    $response = $this->actingAs($user)
        ->getJson(route('admin.select2.companies', ['q' => 'Company']))
        ->assertOk()
        ->assertJsonFragment([
            'id' => $activeCompany->doc_num,
            'text' => 'Company-00071 / Visible Company',
        ])
        ->assertJsonMissing(['id' => $inactiveCompany->doc_num])
        ->assertJsonMissing(['id' => $deletedCompany->doc_num])
        ->json();

    expect(collect($response['results'])->pluck('id')->all())
        ->toContain($activeCompany->doc_num)
        ->not->toContain((string) $activeCompany->id);

    $this->postJson(route('admin.roles.store'), [
        'name' => 'invalid-company-access-role',
        'permissions' => [],
        'accessible_company_doc_nums' => [$deletedCompany->doc_num],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['accessible_company_doc_nums.0']);

    $this->postJson(route('admin.roles.store'), [
        'name' => 'inactive-company-access-role',
        'permissions' => [],
        'accessible_company_doc_nums' => [$inactiveCompany->doc_num],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['accessible_company_doc_nums.0']);
});

test('role operating scope can assign companies branches and financial periods by public document numbers', function () {
    allowMultipleCompaniesForRoleTests();

    $user = userWithPermissions(['roles.create', 'roles.operating_scope.manage']);
    $company = Company::factory()->create([
        'doc_number' => 801,
        'doc_num' => 'Company-00801',
        'name' => 'Scope Company',
    ]);
    $otherCompany = Company::factory()->create([
        'doc_number' => 802,
        'doc_num' => 'Company-00802',
        'name' => 'Other Scope Company',
    ]);
    $branch = Branch::query()->create([
        'doc_number' => 801,
        'doc_num' => 'Branch-00801',
        'company_id' => $company->id,
        'name' => 'Scope Branch',
        'type' => 'warehouse',
        'status' => 'active',
    ]);
    $otherBranch = Branch::query()->create([
        'doc_number' => 802,
        'doc_num' => 'Branch-00802',
        'company_id' => $otherCompany->id,
        'name' => 'Other Scope Branch',
        'type' => 'showroom',
        'status' => 'active',
    ]);
    $period = FinancialPeriod::query()->create([
        'doc_number' => 801,
        'doc_num' => 'Period-00801',
        'company_id' => $company->id,
        'name' => 'FY 2026',
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
    ]);

    $branchResults = $this->actingAs($user)
        ->getJson(route('admin.select2.branches', [
            'q' => 'Scope',
            'company_doc_nums' => [$company->doc_num],
        ]))
        ->assertOk()
        ->assertJsonFragment([
            'id' => $branch->doc_num,
            'text' => 'Scope Branch / Branch-00801 / Scope Company / '.__('branches.types.warehouse'),
        ])
        ->assertJsonMissing(['id' => $otherBranch->doc_num])
        ->json('results');

    expect(collect($branchResults)->pluck('id')->all())
        ->toContain($branch->doc_num)
        ->not->toContain((string) $branch->id);

    $this->postJson(route('admin.roles.store'), [
        'name' => 'invalid-branch-scope-role',
        'permissions' => [],
        'accessible_company_doc_nums' => [$company->doc_num],
        'accessible_branch_doc_nums' => [$otherBranch->doc_num],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['accessible_branch_doc_nums']);

    $this->postJson(route('admin.roles.store'), [
        'name' => 'operating-scope-role',
        'permissions' => [],
        'accessible_company_doc_nums' => [$company->doc_num],
        'accessible_branch_doc_nums' => [$branch->doc_num],
        'accessible_financial_period_doc_nums' => [$period->doc_num],
    ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $role = Role::query()->where('name', 'operating-scope-role')->firstOrFail();
    $activity = Activity::query()->where('action', 'roles.operating_scope.sync')->firstOrFail();

    expect($role->company_access_restricted)->toBeTrue()
        ->and($role->branch_access_restricted)->toBeTrue()
        ->and($role->financial_period_access_restricted)->toBeTrue()
        ->and($role->accessibleCompanies()->pluck('companies.doc_num')->values()->all())->toBe([$company->doc_num])
        ->and($role->accessibleBranches()->pluck('branches.doc_num')->values()->all())->toBe([$branch->doc_num])
        ->and($role->accessibleFinancialPeriods()->pluck('financial_periods.doc_num')->values()->all())->toBe([$period->doc_num])
        ->and($activity->properties->get('added_company_doc_nums'))->toBe([$company->doc_num])
        ->and($activity->properties->get('added_branch_doc_nums'))->toBe([$branch->doc_num])
        ->and($activity->properties->get('added_period_doc_nums'))->toBe([$period->doc_num])
        ->and($activity->properties->get('selected_company_count'))->toBe(1)
        ->and($activity->properties->get('selected_branch_count'))->toBe(1)
        ->and($activity->properties->get('selected_period_count'))->toBe(1)
        ->and($activity->properties->has('role_id'))->toBeFalse()
        ->and($activity->properties->has('branch_id'))->toBeFalse()
        ->and($activity->properties->has('financial_period_id'))->toBeFalse();
});

test('manual role document number control is permission gated and active unique', function () {
    $controller = userWithPermissions(['roles.create', 'roles.edit', 'roles.document_number.control']);

    $this->actingAs($controller)
        ->get(route('admin.roles.create'))
        ->assertOk()
        ->assertSee('name="doc_number"', false)
        ->assertSee(__('roles.document_number_control.helper'));

    $this->actingAs($controller)
        ->postJson(route('admin.roles.store'), [
            'name' => 'manual-accountant',
            'doc_number' => '0001',
            'permissions' => [],
        ])
        ->assertOk()
        ->assertJsonPath('data.doc_number', 1)
        ->assertJsonPath('data.doc_num', 'Role-00001');

    $manual = Role::query()->where('name', 'manual-accountant')->firstOrFail();

    expect($manual->doc_number)->toBe(1);
    expect($manual->doc_num)->toBe('Role-00001');

    $this->actingAs($controller)
        ->postJson(route('admin.roles.store'), [
            'name' => 'manual-duplicate',
            'doc_number' => 1,
            'permissions' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['doc_number'])
        ->assertJsonPath('errors.doc_number.0', __('roles.validation.doc_number_unique'));

    $manual->delete();

    $this->actingAs($controller)
        ->postJson(route('admin.roles.store'), [
            'name' => 'manual-reused',
            'doc_number' => 1,
            'permissions' => [],
        ])
        ->assertOk()
        ->assertJsonPath('data.doc_number', 1)
        ->assertJsonPath('data.doc_num', 'Role-00001');

    expect(Role::withTrashed()->where('doc_number', 1)->count())->toBe(2);
});

test('controlled role document number can be left empty for auto generation', function () {
    $controller = userWithPermissions(['roles.create', 'roles.document_number.control']);

    $this->actingAs($controller)
        ->get(route('admin.roles.create'))
        ->assertOk()
        ->assertSee('name="doc_number"', false)
        ->assertSee('placeholder="'.__('roles.document_number_control.placeholder').'"', false)
        ->assertDontSee('value="1"', false);

    $this->actingAs($controller)
        ->postJson(route('admin.roles.store'), [
            'name' => 'auto-controlled-number',
            'doc_number' => '',
            'permissions' => [],
        ])
        ->assertOk()
        ->assertJsonPath('data.doc_number', 1)
        ->assertJsonPath('data.doc_num', 'Role-00001');
});

test('role document number submissions are ignored without control permission', function () {
    $user = userWithPermissions(['roles.create', 'roles.edit']);
    Role::query()->create([
        'name' => 'existing',
        'guard_name' => 'web',
        'doc_number' => 9,
        'doc_num' => 'Role-00009',
    ]);

    $this->actingAs($user)
        ->get(route('admin.roles.create'))
        ->assertOk()
        ->assertDontSee('name="doc_number"', false);

    $this->actingAs($user)
        ->postJson(route('admin.roles.store'), [
            'name' => 'ignored-manual-number',
            'doc_number' => 50,
            'permissions' => [],
        ])
        ->assertOk()
        ->assertJsonPath('data.doc_number', 10)
        ->assertJsonPath('data.doc_num', 'Role-00010');

    $role = Role::query()->where('name', 'ignored-manual-number')->firstOrFail();

    $this->actingAs($user)
        ->putJson(route('admin.roles.update', $role->doc_num), [
            'name' => 'ignored-manual-number-updated',
            'doc_number' => 99,
            'permissions' => [],
        ])
        ->assertOk()
        ->assertJsonPath('data.doc_number', 10)
        ->assertJsonPath('data.doc_num', 'Role-00010');

    $role->refresh();

    expect($role->name)->toBe('ignored-manual-number-updated');
    expect($role->doc_number)->toBe(10);
    expect($role->doc_num)->toBe('Role-00010');
});

test('role name uniqueness applies only to non deleted roles with the same guard', function () {
    $user = userWithPermissions(['roles.create', 'roles.edit']);
    $original = Role::query()->create([
        'name' => 'Accountant',
        'guard_name' => 'web',
        'doc_number' => 1,
        'doc_num' => 'Role-00001',
    ]);

    $this->actingAs($user)
        ->postJson(route('admin.roles.store'), [
            'name' => 'Accountant',
            'permissions' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name'])
        ->assertJsonPath('errors.name.0', __('roles.validation.name_unique'));

    $original->delete();

    $this->actingAs($user)
        ->postJson(route('admin.roles.store'), [
            'name' => 'Accountant',
            'permissions' => [],
        ])
        ->assertOk()
        ->assertJsonPath('data.doc_num', 'Role-00002');

    $newRole = Role::query()->where('name', 'Accountant')->firstOrFail();
    $otherRole = Role::query()->create([
        'name' => 'Auditor',
        'guard_name' => 'web',
        'doc_number' => 3,
        'doc_num' => 'Role-00003',
    ]);

    expect($newRole->is($original))->toBeFalse();
    expect(Role::withTrashed()->where('name', 'Accountant')->where('guard_name', 'web')->count())->toBe(2);

    $this->actingAs($user)
        ->putJson(route('admin.roles.update', $otherRole->doc_num), [
            'name' => 'Accountant',
            'doc_number' => $otherRole->doc_number,
            'permissions' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name'])
        ->assertJsonPath('errors.name.0', __('roles.validation.name_unique'));

    $this->actingAs($user)
        ->putJson(route('admin.roles.update', $newRole->doc_num), [
            'name' => 'Accountant',
            'doc_number' => $newRole->doc_number,
            'permissions' => [],
        ])
        ->assertOk()
        ->assertJsonPath('type', 'no_changes');

    expect(fn () => Role::query()->create([
        'name' => 'Accountant',
        'guard_name' => 'web',
        'doc_number' => 4,
        'doc_num' => 'Role-00004',
    ]))->toThrow(QueryException::class);
});

test('role validation returns field errors', function () {
    $user = userWithPermissions(['roles.create']);

    $this->actingAs($user)
        ->postJson(route('admin.roles.store'), ['name' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('role can be cloned from public doc num without copying document number', function () {
    protectedRoleFixture();

    $user = userWithPermissions(['roles.clone', 'roles.edit']);
    $company = Company::factory()->create([
        'doc_number' => 111,
        'doc_num' => 'Company-00111',
        'name' => 'Clone Scope Company',
    ]);
    Permission::findOrCreate('users.view', 'web');
    Permission::findOrCreate('roles.view', 'web');
    $source = Role::query()->create([
        'name' => 'manager',
        'notes' => 'Can manage records',
        'guard_name' => 'web',
        'doc_number' => 7,
        'doc_num' => 'Role-00007',
        'company_access_restricted' => true,
    ]);
    $source->givePermissionTo(['users.view', 'roles.view']);
    $source->companyAccessCompanies()->sync([$company->id]);

    $this->actingAs($user)
        ->withSession(['roles.clone_sources.clone-token' => $source->doc_num])
        ->postJson(route('admin.roles.store'), [
            'clone_source_token' => 'clone-token',
            'submit_action' => 'save',
            'name' => 'Copy of manager',
            'notes' => $source->notes,
            'permissions' => ['users.view', 'roles.view'],
            'doc_num' => 'Ignored-99999',
            'doc_number' => 99999,
        ])
        ->assertOk()
        ->assertJsonPath('message', __('roles.messages.cloned_successfully'))
        ->assertJsonPath('data.doc_num', 'Role-00008')
        ->assertJsonPath('submit_action', 'save_new')
        ->assertJsonPath('reset_form', true)
        ->assertJsonMissingPath('redirect');

    $clone = Role::query()->where('name', 'Copy of manager')->firstOrFail();
    $cloneActivity = Activity::query()->where('action', 'roles.clone')->firstOrFail();

    expect($clone->doc_number)->toBe(8);
    expect($clone->doc_num)->toBe('Role-00008');
    expect($clone->notes)->toBe('Can manage records');
    expect($clone->company_access_restricted)->toBeTrue();
    expect($clone->permissions()->pluck('name')->sort()->values()->all())->toBe(['roles.view', 'users.view']);
    expect($clone->accessibleCompanies()->pluck('companies.doc_num')->values()->all())->toBe(['Company-00111']);
    $cloneProperties = $cloneActivity->properties->toArray();

    expect(data_get($cloneProperties, 'related.source.doc_num'))->toBe('Role-00007');
    expect(data_get($cloneProperties, 'record.doc_num'))->toBe('Role-00008');
    expect(data_get($cloneProperties, 'related.source.label'))->toBe('manager');
    expect(data_get($cloneProperties, 'record.label'))->toBe('Copy of manager');
    expect(data_get($cloneProperties, 'meta.permissions_count'))->toBe(2);
    expect(data_get($cloneProperties, 'meta.submit_action'))->toBe('save_new');
    expect($cloneActivity->properties->has('role_id'))->toBeFalse();
    expect($cloneActivity->properties->has('source_role_id'))->toBeFalse();
    expect(Activity::query()->where('action', 'roles.create')->exists())->toBeFalse();
});

test('admin role cannot be cloned', function () {
    $user = userWithPermissions(['roles.clone']);
    $admin = Role::query()->create(['name' => 'admin', 'guard_name' => 'web', 'doc_number' => 1, 'doc_num' => 'Role-00001']);

    $this->actingAs($user)
        ->get(route('admin.roles.clone', $admin->doc_num))
        ->assertRedirect(route('admin.roles.index'))
        ->assertSessionHas('error', __('roles.messages.clone_not_allowed'));

    $this->actingAs($user)
        ->withSession(['roles.clone_sources.admin-clone-token' => $admin->doc_num])
        ->postJson(route('admin.roles.store'), [
            'clone_source_token' => 'admin-clone-token',
            'name' => 'Copy of admin',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    expect(Role::query()->where('name', 'Copy of admin')->exists())->toBeFalse();
    expect(Activity::query()->where('action', 'roles.clone')->exists())->toBeFalse();
});

test('role form is reused as full pages for create edit and view modes', function () {
    protectedRoleFixture();

    $role = Role::query()->create(['name' => 'manager', 'guard_name' => 'web', 'doc_number' => 1, 'doc_num' => 'Role-00001']);
    $company = Company::factory()->create([
        'doc_number' => 101,
        'doc_num' => 'Company-00101',
        'name' => 'Scoped Company',
    ]);
    Permission::findOrCreate('dashboard.view', 'web');
    Permission::findOrCreate('file_manager.view', 'web');
    Permission::findOrCreate('companies.view', 'web');
    Permission::findOrCreate('hr.employees.view', 'web');
    Permission::findOrCreate('hr.departments.view', 'web');
    Permission::findOrCreate('users.view', 'web');
    Permission::findOrCreate('users.index', 'web');
    Permission::findOrCreate('roles.bulk_delete', 'web');
    Permission::findOrCreate('companies.files.view', 'web');
    $role->givePermissionTo('users.view');
    $role->forceFill(['company_access_restricted' => true])->save();
    $role->companyAccessCompanies()->sync([$company->id]);

    $this->actingAs(userWithPermissions(['roles.create']))
        ->get(route('admin.roles.create'))
        ->assertOk()
        ->assertSee('js-role-form', false)
        ->assertSee(__('breadcrumb.create'))
        ->assertSee('js-permission-global-check', false)
        ->assertSee(__('roles.permissions_ui.general'))
        ->assertSee(__('roles.operating_scope.title'))
        ->assertSee(__('roles.operating_scope.all_companies'))
        ->assertSee(__('roles.operating_scope.manage_forbidden'))
        ->assertDontSee('name="accessible_company_doc_nums[]"', false)
        ->assertSee(__('menu.dashboard'))
        ->assertSee('roles-permissions-table', false)
        ->assertSee('roles-permission-row-main', false)
        ->assertSee('roles-permission-resource-row', false)
        ->assertSee('roles-permission-action', false)
        ->assertSee('dir="ltr"', false)
        ->assertSee('data-permission-node="tools"', false)
        ->assertSee('data-permission-node="tools_files_documents_file_manager"', false)
        ->assertDontSee('value="users.index"', false)
        ->assertDontSee('value="roles.bulk_delete"', false)
        ->assertDontSee('value="companies.files.view"', false)
        ->assertDontSee('Company Files')
        ->assertSee(__('menu.basic_data'))
        ->assertSee(__('menu.human_resources'))
        ->assertSee('data-permission-node="human_resources"', false)
        ->assertSee('data-permission-node="human_resources_employee_data_hr_employees"', false)
        ->assertSee('data-permission-nodes="human_resources human_resources_employee_data human_resources_employee_data_hr_employees"', false)
        ->assertSee('data-permission-node="human_resources_hr_setup_hr_departments"', false)
        ->assertSee('data-permission-nodes="human_resources human_resources_hr_setup human_resources_hr_setup_hr_departments"', false)
        ->assertDontSee('data-permission-node="human_resources_hr_setup_hr_countries"', false)
        ->assertDontSee('accordion', false)
        ->assertDontSee('js-permission-uncheck', false)
        ->assertDontSee('role-form-modal')
        ->assertDontSee(__('common.fields.deleted_by'))
        ->assertDontSee(__('common.fields.deleted_at'))
        ->assertDontSee(__('common.fields.restored_by'))
        ->assertDontSee(__('common.fields.restored_at'));

    $this->actingAs(userWithPermissions(['roles.create', 'roles.view', 'roles.edit', 'roles.clone', 'roles.document_number.control']))
        ->get(route('admin.roles.create'))
        ->assertOk()
        ->assertSee('name="doc_number"', false)
        ->assertSee('col-md-3 col-lg-2', false)
        ->assertSee('col-md-9 col-lg-10', false)
        ->assertSee('<span class="text-danger" aria-hidden="true">*</span>', false)
        ->assertSee('data-shortcut-action="form.save"', false)
        ->assertSee('data-shortcut-action="form.save_view"', false)
        ->assertSee('data-shortcut-action="form.save_edit"', false)
        ->assertSee('data-shortcut-action="form.save_back"', false)
        ->assertDontSee('data-submit-action="save_new"', false)
        ->assertDontSee('data-shortcut-action="form.save_new"', false)
        ->assertSee('data-shortcut-action="form.save_clone"', false)
        ->assertSee(__('common.shortcuts.save'), false)
        ->assertSee(__('common.shortcuts.save_clone'), false);

    $this->actingAs(userWithPermissions(['roles.create', 'roles.operating_scope.manage']))
        ->get(route('admin.roles.create'))
        ->assertOk()
        ->assertSee(__('roles.operating_scope.title'))
        ->assertSee('name="accessible_company_doc_nums[]"', false)
        ->assertSee(route('admin.select2.companies'), false);

    $this->actingAs(userWithPermissions(['roles.edit']))
        ->get(route('admin.roles.edit', $role->doc_num))
        ->assertOk()
        ->assertSee('Role-00001')
        ->assertSee('name="notes"', false)
        ->assertSee(__('breadcrumb.edit'))
        ->assertDontSee(__('common.fields.deleted_by'))
        ->assertDontSee(__('common.fields.deleted_at'))
        ->assertDontSee(__('common.fields.restored_by'))
        ->assertDontSee(__('common.fields.restored_at'));

    $this->actingAs(userWithPermissions(['roles.view']))
        ->get(route('admin.roles.show', $role->doc_num))
        ->assertOk()
        ->assertSee('disabled', false)
        ->assertSee(__('roles.operating_scope.title'))
        ->assertSee('Company-00101 / Scoped Company')
        ->assertDontSee('data-shortcut-action="form.save"', false)
        ->assertDontSee(__('common.fields.deleted_by'))
        ->assertDontSee(__('common.fields.deleted_at'))
        ->assertDontSee(__('common.fields.restored_by'))
        ->assertDontSee(__('common.fields.restored_at'));

    $this->actingAs(userWithPermissions(['roles.view', 'roles.edit', 'roles.clone', 'roles.delete']))
        ->get(route('admin.roles.show', $role->doc_num))
        ->assertOk()
        ->assertSee('data-shortcut-action="form.back"', false)
        ->assertSee('data-shortcut-action="form.edit"', false)
        ->assertSee('data-shortcut-action="form.clone"', false)
        ->assertSee('data-shortcut-action="form.delete"', false)
        ->assertSee('data-role-delete-url="'.route('admin.roles.destroy', $role->doc_num).'"', false)
        ->assertSee('data-delete-url="'.route('admin.roles.destroy', $role->doc_num).'"', false)
        ->assertSee('js-delete-record', false)
        ->assertSee(__('common.shortcuts.delete'), false);

    $this->actingAs(userWithPermissions(['roles.clone']))
        ->get(route('admin.roles.clone', $role->doc_num))
        ->assertOk()
        ->assertSee('data-mode="clone"', false)
        ->assertSee(route('admin.roles.store'), false)
        ->assertDontSee('data-submit-action="save_new"', false)
        ->assertDontSee('data-shortcut-action="form.save_new"', false)
        ->assertSee(__('roles.titles.clone'))
        ->assertSee(__('roles.defaults.clone_name', ['name' => 'manager']), false)
        ->assertSee('value="users.view"', false)
        ->assertSee('Company-00101 / Scoped Company')
        ->assertSee('name="clone_source_token"', false)
        ->assertDontSee('name="clone_source_doc_num"', false)
        ->assertDontSee('value="Role-00001"', false)
        ->assertDontSee('name="doc_number"', false)
        ->assertDontSee(__('common.fields.deleted_by'))
        ->assertDontSee(__('common.fields.deleted_at'))
        ->assertDontSee(__('common.fields.restored_by'))
        ->assertDontSee(__('common.fields.restored_at'));
});

test('role permissions table uses localized labels as primary text', function () {
    Permission::findOrCreate('users.roles.manage', 'web');

    $this->actingAs(userWithPermissions(['roles.create']))
        ->get(route('admin.roles.create'))
        ->assertOk()
        ->assertSee('إدارة مجموعات المستخدمين')
        ->assertDontSee('<span class="roles-permission-code text-500 fs-11" dir="ltr">users.roles.manage</span>', false);
});

test('role can be updated and permissions are synced', function () {
    protectedRoleFixture();

    $user = userWithPermissions(['roles.edit']);
    Permission::findOrCreate('users.view', 'web');
    Permission::findOrCreate('roles.view', 'web');
    $role = Role::query()->create(['name' => 'manager', 'guard_name' => 'web', 'doc_number' => 1, 'doc_num' => 'Role-00001']);

    $this->actingAs($user)
        ->putJson(route('admin.roles.update', $role->doc_num), [
            'name' => 'manager-updated',
            'doc_number' => 1,
            'notes' => 'Updated notes',
            'permissions' => ['users.view', 'roles.view'],
            'doc_num' => 'Ignored-99999',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $role->refresh();

    expect($role->name)->toBe('manager-updated');
    expect($role->notes)->toBe('Updated notes');
    expect($role->updated_by)->toBe($user->id);
    expect($role->updated_at)->not->toBeNull();
    expect($role->doc_num)->toBe('Role-00001');
    expect($role->permissions()->pluck('name')->sort()->values()->all())->toBe(['roles.view', 'users.view']);
});

test('role update syncs company access and no change detection includes it', function () {
    allowMultipleCompaniesForRoleTests();
    protectedRoleFixture();

    $user = userWithPermissions(['roles.edit', 'roles.operating_scope.manage']);
    $previousUpdatedAt = now()->subHours(4)->startOfSecond();
    $firstCompany = Company::factory()->create([
        'doc_number' => 81,
        'doc_num' => 'Company-00081',
        'name' => 'Original Company',
    ]);
    $secondCompany = Company::factory()->create([
        'doc_number' => 82,
        'doc_num' => 'Company-00082',
        'name' => 'Replacement Company',
    ]);
    $deletedCompany = Company::factory()->create([
        'doc_number' => 83,
        'doc_num' => 'Company-00083',
        'name' => 'Deleted Historical Company',
    ]);
    $deletedCompany->delete();
    $role = Role::query()->create([
        'name' => 'company-scoped-role',
        'guard_name' => 'web',
        'doc_number' => 1,
        'doc_num' => 'Role-00001',
        'company_access_restricted' => true,
        'updated_by' => $user->id,
        'updated_at' => $previousUpdatedAt,
    ]);
    $role->companyAccessCompanies()->sync([$firstCompany->id, $deletedCompany->id]);

    $this->actingAs($user)
        ->putJson(route('admin.roles.update', $role->doc_num), [
            'name' => ' company-scoped-role ',
            'doc_number' => 1,
            'notes' => '',
            'permissions' => [],
            'accessible_company_doc_nums' => [$firstCompany->doc_num],
        ])
        ->assertOk()
        ->assertJsonPath('success', false)
        ->assertJsonPath('type', 'no_changes');

    $role->refresh();

    expect($role->updated_by)->toBe($user->id)
        ->and($role->updated_at?->toDateTimeString())->toBe($previousUpdatedAt->toDateTimeString())
        ->and(Activity::query()->where('action', 'roles.operating_scope.sync')->exists())->toBeFalse();

    $this->putJson(route('admin.roles.update', $role->doc_num), [
        'name' => 'company-scoped-role',
        'doc_number' => 1,
        'notes' => '',
        'permissions' => [],
        'accessible_company_doc_nums' => [$secondCompany->doc_num],
    ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $role->refresh();
    $companyAccessActivity = Activity::query()->where('action', 'roles.operating_scope.sync')->firstOrFail();

    expect($role->company_access_restricted)->toBeTrue()
        ->and($role->accessibleCompanies()->pluck('companies.doc_num')->values()->all())->toBe([$secondCompany->doc_num])
        ->and(DB::table('role_company_access')->where('role_id', $role->id)->where('company_id', $deletedCompany->id)->exists())->toBeTrue()
        ->and($role->updated_by)->toBe($user->id)
        ->and($role->updated_at?->toDateTimeString())->not->toBe($previousUpdatedAt->toDateTimeString())
        ->and($companyAccessActivity->properties->get('added_company_doc_nums'))->toBe([$secondCompany->doc_num])
        ->and($companyAccessActivity->properties->get('removed_company_doc_nums'))->toBe([$firstCompany->doc_num])
        ->and($companyAccessActivity->properties->get('selected_company_doc_nums'))->toBe([$secondCompany->doc_num])
        ->and(Activity::query()->where('action', 'roles.update')->firstOrFail()->properties->has('role_id'))->toBeFalse();
});

test('role edit without company access permission cannot change company access payload', function () {
    allowMultipleCompaniesForRoleTests();
    protectedRoleFixture();

    $user = userWithPermissions(['roles.edit']);
    $firstCompany = Company::factory()->create([
        'doc_number' => 91,
        'doc_num' => 'Company-00091',
        'name' => 'Allowed Company',
    ]);
    $secondCompany = Company::factory()->create([
        'doc_number' => 92,
        'doc_num' => 'Company-00092',
        'name' => 'Tampered Company',
    ]);
    $role = Role::query()->create([
        'name' => 'restricted-role',
        'guard_name' => 'web',
        'doc_number' => 1,
        'doc_num' => 'Role-00001',
        'company_access_restricted' => true,
    ]);
    $role->companyAccessCompanies()->sync([$firstCompany->id]);

    $this->actingAs($user)
        ->putJson(route('admin.roles.update', $role->doc_num), [
            'name' => 'restricted-role-renamed',
            'permissions' => [],
            'accessible_company_doc_nums' => [$secondCompany->doc_num],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $role->refresh();

    expect($role->name)->toBe('restricted-role-renamed')
        ->and($role->company_access_restricted)->toBeTrue()
        ->and($role->accessibleCompanies()->pluck('companies.doc_num')->values()->all())->toBe([$firstCompany->doc_num])
        ->and(Activity::query()->where('action', 'roles.operating_scope.sync')->exists())->toBeFalse();
});

test('role update hides stale permissions from the form but preserves existing stale grants', function () {
    protectedRoleFixture();

    $user = userWithPermissions(['roles.edit']);
    Permission::findOrCreate('users.view', 'web');
    Permission::findOrCreate('roles.view', 'web');
    Permission::findOrCreate('companies.files.view', 'web');
    $role = Role::query()->create(['name' => 'legacy manager', 'guard_name' => 'web', 'doc_number' => 1, 'doc_num' => 'Role-00001']);
    $role->givePermissionTo(['users.view', 'companies.files.view']);

    $this->actingAs($user)
        ->get(route('admin.roles.edit', $role->doc_num))
        ->assertOk()
        ->assertDontSee('value="companies.files.view"', false);

    $this->actingAs($user)
        ->putJson(route('admin.roles.update', $role->doc_num), [
            'name' => 'legacy manager',
            'doc_number' => 1,
            'notes' => '',
            'permissions' => ['roles.view'],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($role->refresh()->permissions()->pluck('name')->sort()->values()->all())
        ->toBe(['companies.files.view', 'roles.view']);
});

test('role update detects no changes without mutating or logging update activity', function () {
    protectedRoleFixture();

    $user = userWithPermissions(['roles.edit']);
    Permission::findOrCreate('users.view', 'web');
    $previousUpdatedAt = now()->subHours(4)->startOfSecond();
    $role = Role::query()->create([
        'name' => 'manager',
        'guard_name' => 'web',
        'doc_number' => 1,
        'doc_num' => 'Role-00001',
        'updated_by' => $user->id,
        'updated_at' => $previousUpdatedAt,
    ]);
    $role->givePermissionTo('users.view');

    $this->actingAs($user)
        ->putJson(route('admin.roles.update', $role->doc_num), [
            'name' => ' manager ',
            'doc_number' => 1,
            'notes' => '',
            'permissions' => ['users.view'],
        ])
        ->assertOk()
        ->assertJsonPath('success', false)
        ->assertJsonPath('type', 'no_changes');

    $role->refresh();

    expect($role->updated_by)->toBe($user->id)
        ->and($role->updated_at?->toDateTimeString())->toBe($previousUpdatedAt->toDateTimeString())
        ->and(Activity::query()->where('action', 'roles.update')->count())->toBe(0);
});

test('role doc number can be changed through doc num route and returns new public urls', function () {
    protectedRoleFixture();

    $user = userWithPermissions(['roles.edit', 'roles.document_number.control']);
    $role = Role::query()->create(['name' => 'manager', 'guard_name' => 'web', 'doc_number' => 1, 'doc_num' => 'Role-00001']);

    $this->actingAs($user)
        ->putJson(route('admin.roles.update', $role->doc_num), [
            'name' => 'manager',
            'doc_number' => 27,
            'permissions' => [],
            'doc_num' => 'Ignored-99999',
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.old_doc_num', 'Role-00001')
        ->assertJsonPath('data.doc_num', 'Role-00027')
        ->assertJsonPath('data.urls.show', url('/admin/roles/Role-00027'));

    $role->refresh();

    expect($role->doc_number)->toBe(27);
    expect($role->doc_num)->toBe('Role-00027');
    expect(Activity::query()->where('action', 'roles.doc_number.changed')->exists())->toBeTrue();
});

test('admin role cannot be deleted directly or in bulk', function () {
    $user = userWithPermissions(['roles.delete']);
    $admin = Role::query()->create(['name' => 'admin', 'guard_name' => 'web', 'doc_number' => 1, 'doc_num' => 'Role-00001']);
    $other = Role::query()->create(['name' => 'manager', 'guard_name' => 'web', 'doc_number' => 2, 'doc_num' => 'Role-00002']);

    $this->actingAs($user)
        ->deleteJson(route('admin.roles.destroy', $admin->doc_num))
        ->assertUnprocessable()
        ->assertJsonPath('success', false);

    $this->actingAs($user)
        ->deleteJson(route('admin.roles.bulk-delete'), ['doc_nums' => [$admin->doc_num, $other->doc_num]])
        ->assertUnprocessable()
        ->assertJsonPath('success', false);

    expect(Role::query()->whereKey($admin->id)->exists())->toBeTrue();
    expect(Role::query()->whereKey($other->id)->exists())->toBeTrue();
});

test('role assigned to users cannot be deleted', function () {
    protectedRoleFixture();

    $user = userWithPermissions(['roles.delete']);
    $assignedUser = User::factory()->create();
    $role = Role::query()->create(['name' => 'assigned', 'guard_name' => 'web', 'doc_number' => 1, 'doc_num' => 'Role-00001']);
    $assignedUser->assignRole($role);

    $this->actingAs($user)
        ->deleteJson(route('admin.roles.destroy', $role->doc_num))
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', __('auth.roles.messages.related_data_exists'))
        ->assertJsonPath('data.blocked_records.0.doc_num', 'Role-00001')
        ->assertJsonPath('data.blocked_records.0.reason', 'users')
        ->assertJsonPath('data.blocked_records.0.related_users_count', 1);

    $blockedActivity = Activity::query()->where('action', 'roles.delete_blocked')->firstOrFail();

    expect(Role::query()->whereKey($role->id)->exists())->toBeTrue();
    expect(Activity::query()->where('action', 'roles.delete')->exists())->toBeFalse();
    expect($blockedActivity->status)->toBe('blocked');
    expect($blockedActivity->properties->get('doc_num'))->toBe('Role-00001');
    expect($blockedActivity->properties->get('role_name'))->toBe('assigned');
    expect($blockedActivity->properties->get('reason'))->toBe('users');
    expect($blockedActivity->properties->get('related_users_count'))->toBe(1);
    expect($blockedActivity->properties->has('role_id'))->toBeFalse();
});

test('role assigned to soft deleted users still cannot be deleted', function () {
    protectedRoleFixture();

    $user = userWithPermissions(['roles.delete']);
    $assignedUser = User::factory()->create();
    $role = Role::query()->create(['name' => 'assigned-deleted-user', 'guard_name' => 'web', 'doc_number' => 1, 'doc_num' => 'Role-00001']);
    $assignedUser->assignRole($role);
    $assignedUser->delete();

    $this->actingAs($user)
        ->deleteJson(route('admin.roles.destroy', $role->doc_num))
        ->assertUnprocessable()
        ->assertJsonPath('message', __('auth.roles.messages.related_data_exists'));

    expect(Role::query()->whereKey($role->id)->exists())->toBeTrue();
});

test('bulk role delete is all or nothing when selected roles have dependencies', function () {
    protectedRoleFixture();

    $user = userWithPermissions(['roles.delete']);
    $assignedUser = User::factory()->create();
    $blocked = Role::query()->create(['name' => 'blocked-role', 'guard_name' => 'web', 'doc_number' => 1, 'doc_num' => 'Role-00001']);
    $free = Role::query()->create(['name' => 'free-role', 'guard_name' => 'web', 'doc_number' => 2, 'doc_num' => 'Role-00002']);
    $assignedUser->assignRole($blocked);

    $this->actingAs($user)
        ->deleteJson(route('admin.roles.bulk-delete'), ['doc_nums' => [$blocked->doc_num, $free->doc_num]])
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('data.blocked_records.0.doc_num', 'Role-00001')
        ->assertJsonPath('data.blocked_records.0.reason', 'users')
        ->assertSee('Role-00001', false)
        ->assertSee('blocked-role', false);

    expect(Role::query()->whereKey($blocked->id)->exists())->toBeTrue();
    expect(Role::query()->whereKey($free->id)->exists())->toBeTrue();
    expect(Activity::query()->where('action', 'roles.bulk_delete')->exists())->toBeFalse();
    expect(Activity::query()->where('action', 'roles.delete_blocked')->exists())->toBeTrue();
});

test('role can be deleted and bulk deleted with ajax', function () {
    protectedRoleFixture();

    $user = userWithPermissions(['roles.delete']);
    $previousUpdatedAt = now()->subHours(3)->startOfSecond();
    $single = Role::query()->create(['name' => 'single', 'guard_name' => 'web', 'doc_number' => 1, 'doc_num' => 'Role-00001', 'updated_by' => $user->id, 'updated_at' => $previousUpdatedAt]);
    $first = Role::query()->create(['name' => 'first', 'guard_name' => 'web', 'doc_number' => 2, 'doc_num' => 'Role-00002', 'updated_by' => $user->id, 'updated_at' => $previousUpdatedAt]);
    $second = Role::query()->create(['name' => 'second', 'guard_name' => 'web', 'doc_number' => 3, 'doc_num' => 'Role-00003', 'updated_by' => $user->id, 'updated_at' => $previousUpdatedAt]);

    $this->actingAs($user)
        ->deleteJson(route('admin.roles.destroy', $single->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->actingAs($user)
        ->deleteJson(route('admin.roles.bulk-delete'), ['doc_nums' => [$first->doc_num, $second->doc_num]])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.deleted', 2);

    $deletedRoles = Role::withTrashed()
        ->whereIn('id', [$single->id, $first->id, $second->id])
        ->get();

    expect(Role::query()->whereIn('id', [$single->id, $first->id, $second->id])->count())->toBe(0);
    expect($deletedRoles)->toHaveCount(3);
    expect($deletedRoles->every(fn (Role $role): bool => $role->deleted_at !== null))->toBeTrue();
    expect($deletedRoles->every(fn (Role $role): bool => (int) $role->deleted_by === $user->id))->toBeTrue();
    expect($deletedRoles->every(fn (Role $role): bool => (int) $role->updated_by === $user->id))->toBeTrue();
    expect($deletedRoles->every(fn (Role $role): bool => $role->updated_at?->toDateTimeString() === $previousUpdatedAt->toDateTimeString()))->toBeTrue();
    expect($deletedRoles->every(fn (Role $role): bool => $role->restored_by === null && $role->restored_at === null))->toBeTrue();
});

test('roles datatable exposes doc num URLs and does not expose internal id controls', function () {
    protectedRoleFixture();

    $user = userWithPermissions(['roles.view', 'roles.clone', 'roles.edit', 'roles.delete']);
    $role = Role::query()->create(['name' => 'manager', 'notes' => 'Factory role', 'guard_name' => 'web', 'doc_number' => 1, 'doc_num' => 'Role-00001', 'created_by' => $user->id, 'updated_by' => $user->id]);

    $response = $this->actingAs($user)
        ->getJson(route('admin.roles.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'manager'],
        ]))
        ->assertOk();

    $row = $response->json('data.0');

    expect($row['doc_num'])->toContain('Role-00001');
    expect($row['notes'])->toContain('dt-ellipsis-content');
    expect($row['notes'])->toContain('title="Factory role"');
    expect($row['created_by'])->toContain('title="'.e($user->name).'"');
    expect($row['updated_by'])->toContain('title="'.e($user->name).'"');
    expect($row)->not->toHaveKey('guard_name');
    expect($row)->not->toHaveKey('permissions_count');
    expect($row)->not->toHaveKey('permissions_summary');
    expect($row)->not->toHaveKey('id');
    expect($row['checkbox'])->toContain('value="Role-00001"');
    expect($row['checkbox'])->toContain('data-doc-num="Role-00001"');
    expect($row['checkbox'])->toContain('js-record-select');
    expect($row['checkbox'])->toContain('type="checkbox"');
    expect($row['checkbox'])->not->toContain('value="'.$role->id.'"');
    expect($row['doc_num'])->toContain('/admin/roles/Role-00001');
    expect($row['actions'])->toContain('/admin/roles/Role-00001');
    expect($row['actions'])->toContain('/admin/roles/Role-00001/clone');
    expect(route('admin.roles.clone', $role))->toBe(url('/admin/roles/Role-00001/clone'));
    expect($row['actions'])->toContain('data-doc-num="Role-00001"');
    expect($row['actions'])->toContain('js-edit-record');
    expect($row['actions'])->toContain('js-clone-record');
    expect($row['actions'])->toContain(__('common.actions.clone_record'));
    expect($row['actions'])->toContain('dropdown-menu');
    expect($row['actions'])->not->toContain('/admin/roles/'.$role->id);
});

test('role mutations write activity with public document properties', function () {
    protectedRoleFixture();

    $user = userWithPermissions(['roles.create', 'roles.edit', 'roles.delete', 'roles.document_number.control']);
    Permission::findOrCreate('users.view', 'web');

    $this->actingAs($user)
        ->postJson(route('admin.roles.store'), [
            'submit_action' => 'save',
            'name' => 'manager',
            'permissions' => ['users.view'],
        ])
        ->assertOk();

    $role = Role::query()->where('name', 'manager')->firstOrFail();
    $createActivity = Activity::query()->where('action', 'roles.create')->firstOrFail();

    $createProperties = $createActivity->properties->toArray();

    expect(data_get($createProperties, 'record.doc_num'))->toBe('Role-00001');
    expect(data_get($createProperties, 'record.label'))->toBe('manager');
    expect(data_get($createProperties, 'meta.permissions_count'))->toBe(1);
    expect(data_get($createProperties, 'meta.submit_action'))->toBe('save_new');
    expect($createActivity->properties->has('role_id'))->toBeFalse();
    expect($createActivity->properties->has('id'))->toBeFalse();
    expect(Activity::query()->where('action', 'roles.permissions.sync')->exists())->toBeFalse();

    $this->actingAs($user)
        ->putJson(route('admin.roles.update', $role->doc_num), [
            'submit_action' => 'save_edit',
            'name' => 'manager-updated',
            'doc_number' => 2,
            'permissions' => [],
        ])
        ->assertOk();

    $updateActivity = Activity::query()->where('action', 'roles.update')->firstOrFail();
    $permissionsActivity = Activity::query()->where('action', 'roles.permissions.sync')->firstOrFail();
    $docNumberActivity = Activity::query()->where('action', 'roles.doc_number.changed')->firstOrFail();

    $updateProperties = $updateActivity->properties->toArray();

    expect(data_get($updateProperties, 'record.doc_num'))->toBe('Role-00002');
    expect(data_get($updateProperties, 'record.label'))->toBe('manager-updated');
    expect(data_get($updateProperties, 'changes.name.old'))->toBe('manager');
    expect(data_get($updateProperties, 'changes.name.new'))->toBe('manager-updated');
    expect(data_get($updateProperties, 'changes.doc_number.old'))->toBe(1);
    expect(data_get($updateProperties, 'changes.doc_number.new'))->toBe(2);
    expect(data_get($updateProperties, 'meta.submit_action'))->toBe('save');
    expect($updateActivity->properties->has('role_id'))->toBeFalse();
    expect($updateActivity->properties->has('old_doc_num'))->toBeFalse();
    expect($updateActivity->properties->has('new_doc_num'))->toBeFalse();
    expect($updateActivity->properties->has('old_role_name'))->toBeFalse();
    expect($updateActivity->properties->has('new_role_name'))->toBeFalse();
    expect($updateActivity->properties->has('permissions_count'))->toBeFalse();
    expect($updateActivity->properties->has('old_doc_number'))->toBeFalse();
    expect($permissionsActivity->properties->get('doc_num'))->toBe('Role-00002');
    expect($permissionsActivity->properties->get('role_name'))->toBe('manager-updated');
    expect($permissionsActivity->properties->get('added_permissions'))->toBe([]);
    expect($permissionsActivity->properties->get('removed_permissions'))->toBe(['users.view']);
    expect($permissionsActivity->properties->get('permissions_count'))->toBe(0);
    $docNumberProperties = $docNumberActivity->properties->toArray();

    expect(data_get($docNumberProperties, 'changes.doc_num.old'))->toBe('Role-00001');
    expect(data_get($docNumberProperties, 'changes.doc_num.new'))->toBe('Role-00002');
    expect(data_get($docNumberProperties, 'changes.doc_number.old'))->toBe(1);
    expect(data_get($docNumberProperties, 'changes.doc_number.new'))->toBe(2);
    expect(data_get($docNumberProperties, 'record.label'))->toBe('manager-updated');

    $role->refresh();

    $this->actingAs($user)
        ->deleteJson(route('admin.roles.destroy', $role->doc_num))
        ->assertOk();

    $first = Role::query()->create(['name' => 'first', 'guard_name' => 'web', 'doc_number' => 3, 'doc_num' => 'Role-00003']);
    $second = Role::query()->create(['name' => 'second', 'guard_name' => 'web', 'doc_number' => 4, 'doc_num' => 'Role-00004']);

    $this->actingAs($user)
        ->deleteJson(route('admin.roles.bulk-delete'), ['doc_nums' => [$first->doc_num, $second->doc_num]])
        ->assertOk();

    $deleteActivity = Activity::query()->where('action', 'roles.delete')->firstOrFail();
    $bulkActivity = Activity::query()->where('action', 'roles.bulk_delete')->firstOrFail();

    expect(data_get($deleteActivity->properties->toArray(), 'record.doc_num'))->toBe('Role-00002');
    expect(data_get($deleteActivity->properties->toArray(), 'record.label'))->toBe('manager-updated');
    expect($deleteActivity->properties->has('role_id'))->toBeFalse();
    expect($deleteActivity->properties->has('doc_number'))->toBeFalse();
    expect(data_get($bulkActivity->properties->toArray(), 'bulk.doc_nums'))->toBe([$first->doc_num, $second->doc_num]);
    expect(data_get($bulkActivity->properties->toArray(), 'bulk.count'))->toBe(2);
    expect($bulkActivity->properties->has('deleted_count'))->toBeFalse();
    expect($bulkActivity->properties->has('role_id'))->toBeFalse();
});
