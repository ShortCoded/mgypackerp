<?php

use Modules\Core\Services\Generators\CrudGenerator;
use Modules\Core\Services\Generators\CrudNameResolver;
use Modules\Core\Services\Generators\FieldParser;
use Modules\Core\Services\Generators\GeneratorFileWriter;
use Modules\Core\Services\Generators\StubRenderer;

test('field parser supports required nullable unique and length tokens', function () {
    $fields = app(FieldParser::class)->parse('name:string:150:required:unique,notes:text:nullable,amount:decimal:10.2,active:boolean:default=1');

    expect($fields)->toHaveCount(4)
        ->and($fields[0]->name)->toBe('name')
        ->and($fields[0]->type)->toBe('string')
        ->and($fields[0]->length)->toBe(150)
        ->and($fields[0]->required)->toBeTrue()
        ->and($fields[0]->unique)->toBeTrue()
        ->and($fields[2]->type)->toBe('decimal')
        ->and($fields[2]->precision)->toBe(10)
        ->and($fields[2]->scale)->toBe(2)
        ->and($fields[3]->type)->toBe('boolean')
        ->and($fields[0]->isDisplayable)->toBeTrue()
        ->and($fields[0]->isLoggable)->toBeTrue()
        ->and($fields[0]->isFillable)->toBeTrue();
});

test('reserved identifier fields are rejected', function (string $field) {
    expect(fn () => app(FieldParser::class)->parse("name:string:required,{$field}:string"))
        ->toThrow(InvalidArgumentException::class, "Field [{$field}] is reserved or sensitive and cannot be generated.");
})->with([
    'id',
    'doc_num',
    'doc_number',
    'created_by',
    'restored_by',
    'restored_at',
    'guard_name',
]);

test('token and password-like fields are rejected', function (string $field) {
    expect(fn () => app(FieldParser::class)->parse("name:string:required,{$field}:string"))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'api_token',
    'access_token',
    'user_password',
    'password_hash',
    'client_secret',
    'secret_key',
    'secret',
]);

test('foreign id fields require manual review and are hidden from public surfaces by default', function () {
    $field = app(FieldParser::class)->parse('customer_id:foreignId:required')[0];

    expect($field->isForeignKey)->toBeTrue()
        ->and($field->isFillable)->toBeTrue()
        ->and($field->isDisplayable)->toBeFalse()
        ->and($field->isLoggable)->toBeFalse()
        ->and($field->requiresManualReview)->toBeTrue();
});

test('name resolver derives lookup names without exposing internal identifiers', function () {
    $names = app(CrudNameResolver::class)->resolve(
        module: 'HR',
        resource: 'Department',
        table: 'hr_departments',
        routePrefix: 'admin.hr.departments',
        urlPrefix: 'admin/hr/departments',
        permissionPrefix: 'hr.departments',
        docKey: 'hr_departments',
        translationKey: 'hr.departments',
        mode: 'lookup',
    );

    expect($names->modelClass)->toBe('HrDepartment')
        ->and($names->controllerClass)->toBe('HrDepartmentController')
        ->and($names->dataTableClass)->toBe('HrDepartmentsDataTable')
        ->and($names->routeParameter)->toBe('department')
        ->and($names->viewFolder)->toBe('resources/views/modules/hr/departments')
        ->and($names->jsPath)->toBe('public/assets/js/modules/HR/departments.js');
});

test('lookup generator command dry run does not create files', function () {
    $path = base_path('modules/HR/Models/HrCodexDryRunLookup.php');

    expect(is_file($path))->toBeFalse();

    $this->artisan('erp:make-lookup-crud', [
        'module' => 'HR',
        'resource' => 'Codex Dry Run Lookup',
        '--table' => 'hr_codex_dry_run_lookups',
        '--route' => 'admin.hr.codex-dry-run-lookups',
        '--url' => 'admin/hr/codex-dry-run-lookups',
        '--permission' => 'hr.codex_dry_run_lookups',
        '--doc-key' => 'hr_codex_dry_run_lookups',
        '--prefix' => 'DryLookup-',
        '--menu' => 'core',
        '--menu-parent' => 'basic_data.hr',
        '--translation' => 'hr.codex_dry_run_lookups',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Dry-run summary')
        ->expectsOutputToContain('modules/HR/Models/HrCodexDryRunLookup.php')
        ->assertSuccessful();

    expect(is_file($path))->toBeFalse();
});

test('full crud generator command dry run does not create files', function () {
    $path = base_path('modules/Core/Models/CodexDryRunBranch.php');

    expect(is_file($path))->toBeFalse();

    $this->artisan('erp:make-crud', [
        'module' => 'Core',
        'resource' => 'Codex Dry Run Branch',
        '--table' => 'codex_dry_run_branches',
        '--route' => 'admin.codex-dry-run-branches',
        '--url' => 'admin/codex-dry-run-branches',
        '--permission' => 'codex_dry_run_branches',
        '--doc-key' => 'codex_dry_run_branches',
        '--prefix' => 'DryBranch-',
        '--menu' => 'core',
        '--menu-parent' => 'basic_data',
        '--translation' => 'codex_dry_run_branches',
        '--fields' => 'name:string:required:unique,type:string:required,address:text:nullable,phone:string:nullable,notes:text:nullable',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Dry-run summary')
        ->expectsOutputToContain('modules/Core/Models/CodexDryRunBranch.php')
        ->assertSuccessful();

    expect(is_file($path))->toBeFalse();
});

test('full crud command rejects reserved fields before writing files', function () {
    $path = base_path('modules/Core/Models/CodexBadReservedField.php');

    expect(is_file($path))->toBeFalse();

    $this->artisan('erp:make-crud', [
        'module' => 'Core',
        'resource' => 'Codex Bad Reserved Field',
        '--table' => 'codex_bad_reserved_fields',
        '--route' => 'admin.codex-bad-reserved-fields',
        '--url' => 'admin/codex-bad-reserved-fields',
        '--permission' => 'codex_bad_reserved_fields',
        '--doc-key' => 'codex_bad_reserved_fields',
        '--prefix' => 'BadReserved-',
        '--menu' => 'core',
        '--menu-parent' => 'basic_data',
        '--translation' => 'codex_bad_reserved_fields',
        '--fields' => 'name:string:required,doc_num:string',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Field [doc_num] is reserved or sensitive and cannot be generated.')
        ->assertFailed();

    expect(is_file($path))->toBeFalse();
});

test('crud generator stubs use shared view field placeholders', function () {
    $datatableStub = file_get_contents(base_path('stubs/erp/crud/datatable.stub'));
    $viewStub = file_get_contents(base_path('stubs/erp/crud/view-form.stub'));

    expect($datatableStub)
        ->toContain('FormatsNullableColumns')
        ->toContain('$this->ellipsisText')
        ->toContain('$this->plainText')
        ->not->toContain('common.messages.not_available')
        ->not->toContain("?: '-'")
        ->not->toContain("?? '-'")
        ->and($viewStub)
        ->toContain('<x-forms.view-field')
        ->not->toContain('common.messages.not_available')
        ->not->toContain("?: '-'")
        ->not->toContain("?? '-'");
});

test('file writer skips existing files without force', function () {
    $path = 'storage/framework/testing/erp-generator/existing.txt';
    $absolutePath = base_path($path);

    if (! is_dir(dirname($absolutePath))) {
        mkdir(dirname($absolutePath), 0755, true);
    }

    file_put_contents($absolutePath, 'original');

    try {
        $writer = new GeneratorFileWriter(dryRun: false, force: false);
        $writer->write($path, 'changed');

        $results = $writer->results();

        expect(file_get_contents($absolutePath))->toBe('original')
            ->and($results['skipped'])->toHaveCount(1)
            ->and($results['skipped'][0]['path'])->toBe($path);
    } finally {
        if (is_file($absolutePath)) {
            unlink($absolutePath);
        }
    }
});

test('file writer overwrites existing files with force', function () {
    $path = 'storage/framework/testing/erp-generator/force.txt';
    $absolutePath = base_path($path);

    if (! is_dir(dirname($absolutePath))) {
        mkdir(dirname($absolutePath), 0755, true);
    }

    file_put_contents($absolutePath, 'original');

    try {
        $writer = new GeneratorFileWriter(dryRun: false, force: true);
        $writer->write($path, 'changed');

        $results = $writer->results();

        expect(file_get_contents($absolutePath))->toBe('changed')
            ->and($results['updated'])->toHaveCount(1)
            ->and($results['updated'][0]['path'])->toBe($path);
    } finally {
        if (is_file($absolutePath)) {
            unlink($absolutePath);
        }
    }
});

test('file writer dry run records generated files without writing them', function () {
    $path = 'storage/framework/testing/erp-generator/dry-run.txt';
    $absolutePath = base_path($path);

    if (is_file($absolutePath)) {
        unlink($absolutePath);
    }

    $writer = new GeneratorFileWriter(dryRun: true, force: false);
    $writer->write($path, 'planned');

    $results = $writer->results();

    expect(is_file($absolutePath))->toBeFalse()
        ->and($results['generated'])->toHaveCount(1)
        ->and($results['generated'][0]['path'])->toBe($path);
});

test('generated javascript handles no changes and guarded delete confirmations', function () {
    $stub = file_get_contents(base_path('stubs/erp/crud/js.stub'));

    expect($stub)->toContain("response.success === false && response.type === 'no_changes'")
        ->and($stub)->toContain('focusCancel: true')
        ->and($stub)->toContain('showCloseButton: true')
        ->and($stub)->toContain('allowEscapeKey: true')
        ->and($stub)->toContain('data: { doc_nums: docNums }');
});

test('full crud generator stubs follow the Units CRUD action and table standard', function () {
    $indexStub = file_get_contents(base_path('stubs/erp/crud/view-index.stub'));
    $formStub = file_get_contents(base_path('stubs/erp/crud/view-form.stub'));
    $actionsStub = file_get_contents(base_path('stubs/erp/crud/partial-form-actions.stub'));
    $rowActionsStub = file_get_contents(base_path('stubs/erp/crud/partial-actions.stub'));
    $checkboxStub = file_get_contents(base_path('stubs/erp/crud/partial-checkbox.stub'));
    $jsStub = file_get_contents(base_path('stubs/erp/crud/js.stub'));
    $dataTableStub = file_get_contents(base_path('stubs/erp/crud/datatable.stub'));
    $controllerStub = file_get_contents(base_path('stubs/erp/crud/controller.stub'));

    expect($indexStub)
        ->toContain('erp-datatable-card')
        ->toContain('id="bulk_actions_bar"')
        ->toContain('id="{{ kebabPlural }}_trash_filter"')
        ->toContain('data-bulk-delete-url')
        ->toContain('js-{{ kebabSingular }}-table')
        ->and($formStub)
        ->toContain('class="js-{{ kebabSingular }}-form"')
        ->toContain('<x-audit-fields-row')
        ->toContain('js-{{ kebabSingular }}-alert')
        ->and($actionsStub)
        ->not->toContain('data-submit-action="save_new"')
        ->toContain('$canEdit && $isCreateLike')
        ->toContain('data-submit-action="save_edit"')
        ->and($rowActionsStub)
        ->toContain('dropstart font-sans-serif')
        ->toContain('js-restore-record')
        ->and($checkboxStub)
        ->toContain('js-record-select')
        ->not->toContain('data-id=')
        ->and($jsStub)
        ->toContain('window.history.replaceState')
        ->toContain('updateUrlsAfterDocNumberChange')
        ->toContain('resetCreateForm($form)')
        ->toContain('Swal.fire')
        ->not->toContain('confirm(')
        ->not->toContain('alert(')
        ->not->toContain('next_doc_number')
        ->and($dataTableStub)
        ->toContain('trash_filter')
        ->toContain('onlyTrashed()')
        ->toContain('withTrashed()')
        ->toContain('->removeColumn(\'id\')')
        ->and($controllerStub)
        ->toContain("if (\$creating && \$action === 'save')")
        ->toContain("return 'save_new';")
        ->toContain("if (\$action === 'save_new' || (! \$creating && \$action === 'save_edit'))");
});

test('foreign id fields are not rendered in generated public datatable form or log properties by default', function () {
    $names = app(CrudNameResolver::class)->resolve(
        module: 'Core',
        resource: 'Codex Foreign Field',
        table: 'codex_foreign_fields',
        routePrefix: 'admin.codex-foreign-fields',
        urlPrefix: 'admin/codex-foreign-fields',
        permissionPrefix: 'codex_foreign_fields',
        docKey: 'codex_foreign_fields',
        translationKey: 'codex_foreign_fields',
    );
    $fields = app(FieldParser::class)->parse('name:string:required,customer_id:foreignId:nullable');
    $method = new ReflectionMethod(CrudGenerator::class, 'variables');
    $method->setAccessible(true);

    $variables = $method->invoke(app(CrudGenerator::class), $names, $fields);
    $dataTable = app(StubRenderer::class)->render('stubs/erp/crud/datatable.stub', $variables);
    $controller = app(StubRenderer::class)->render('stubs/erp/crud/controller.stub', $variables);

    expect($variables['formFields'])->toContain('TODO: Add a safe selector for customer_id')
        ->and($variables['formFields'])->not->toContain('name="customer_id"')
        ->and($dataTable)->not->toContain('codex_foreign_fields.customer_id')
        ->and($dataTable)->not->toContain("data: 'customer_id'")
        ->and($controller)->not->toContain("'customer_id' => \$record->customer_id");
});

test('full crud generator emits canonical activity log properties', function () {
    $names = app(CrudNameResolver::class)->resolve(
        module: 'Core',
        resource: 'Codex Activity Branch',
        table: 'codex_activity_branches',
        routePrefix: 'admin.codex-activity-branches',
        urlPrefix: 'admin/codex-activity-branches',
        permissionPrefix: 'codex_activity_branches',
        docKey: 'codex_activity_branches',
        translationKey: 'codex_activity_branches',
    );
    $fields = app(FieldParser::class)->parse('name:string:required,notes:text:nullable');
    $method = new ReflectionMethod(CrudGenerator::class, 'variables');
    $method->setAccessible(true);

    $variables = $method->invoke(app(CrudGenerator::class), $names, $fields);
    $controller = app(StubRenderer::class)->render('stubs/erp/crud/controller.stub', $variables);
    $form = app(StubRenderer::class)->render('stubs/erp/crud/view-form.stub', $variables);
    $service = app(StubRenderer::class)->render('stubs/erp/crud/service.stub', $variables);
    $model = app(StubRenderer::class)->render('stubs/erp/crud/model.stub', $variables);
    $migration = app(StubRenderer::class)->render('stubs/erp/crud/migration.stub', $variables);
    $routeBlock = app(StubRenderer::class)->render('stubs/erp/crud/route-block.stub', $variables);
    $lookupMigration = app(StubRenderer::class)->render('stubs/erp/lookup-crud/migration.stub', $variables);

    expect($controller)
        ->toContain('use App\\Models\\User;')
        ->toContain('use Modules\\Core\\Services\\ActivityLogProperties;')
        ->toContain('ActivityLogProperties::crudCreated')
        ->toContain('ActivityLogProperties::crudUpdated')
        ->toContain('ActivityLogProperties::crudDeleted')
        ->toContain('ActivityLogProperties::crudRestored')
        ->toContain('ActivityLogProperties::bulkDeleted')
        ->toContain('ActivityLogProperties::settingsUpdated')
        ->toContain("'deleted_by' => \$this->auditUserLabel(\$record->deletedBy)")
        ->toContain("'deleted_at' => \$settings->formatDateTime(\$record->deleted_at, '')")
        ->toContain("'restored_by' => \$this->auditUserLabel(\$record->restoredBy)")
        ->toContain("'restored_at' => \$settings->formatDateTime(\$record->restored_at, '')")
        ->toContain("'restored_by_user_doc_num' => \$user instanceof User ? \$user->doc_num : null")
        ->toContain('private function auditUserLabel(?User $user): ?string')
        ->not->toContain("'id' =>")
        ->and($service)
        ->toContain('use Modules\\Core\\Services\\CrudAuditService;')
        ->toContain('private readonly CrudAuditService $crudAudit')
        ->toContain('$this->crudAudit->clearCreationUpdateAudit($record)')
        ->toContain('$this->crudAudit->saveUpdate($record')
        ->toContain('$this->crudAudit->softDelete($record)')
        ->toContain('$this->crudAudit->restore($record, auth()->id())')
        ->toContain("'changes' => \$changes")
        ->toContain('private function changedValues')
        ->not->toContain('clearCreateUpdateTracking')
        ->and($model)
        ->toContain("'restored_by',")
        ->toContain("'restored_at',")
        ->toContain("'restored_at' => 'datetime'")
        ->toContain('public function restoredBy(): BelongsTo')
        ->and($migration)
        ->toContain("\$table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();")
        ->toContain("\$table->timestamp('restored_at')->nullable();")
        ->and($lookupMigration)
        ->toContain("\$table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();")
        ->toContain("\$table->timestamp('restored_at')->nullable();")
        ->and($routeBlock)
        ->toContain("::class, 'restore'])")
        ->toContain("->name('codex-activity-branches.restore')")
        ->toContain('->withTrashed()')
        ->and($form)
        ->toContain('<x-forms.view-field')
        ->toContain('<x-audit-fields-row')
        ->toContain(':show-deleted="$isView && ($record?->trashed() ?? false)"')
        ->toContain(':show-restored="$isView && ! ($record?->trashed() ?? false) && (($record?->restored_at ?? null) || ($record?->restored_by ?? null))"');
});
