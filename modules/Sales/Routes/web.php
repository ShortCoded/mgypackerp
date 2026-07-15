<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Sales\Http\Controllers\CustomerController;
use Modules\Sales\Http\Controllers\ProjectStructureController;
use Modules\Sales\Http\Controllers\ProjectStructureModelController;
use Modules\Sales\Http\Controllers\QuotationController;
use Modules\Sales\Services\SalesSelect2Service;

Route::middleware('auth')
    ->prefix('admin/sales')
    ->as('admin.sales.')
    ->group(function (): void {
        Route::get('/select2/customer-groups', function (Request $request, SalesSelect2Service $select2) {
            abort_unless(
                (bool) $request->user()?->can('customers.view')
                || (bool) $request->user()?->can('customers.create')
                || (bool) $request->user()?->can('customers.edit'),
                403
            );

            return response()->json($select2->customerGroups($request));
        })->name('select2.customer-groups');
        Route::get('/select2/customers', [QuotationController::class, 'customers'])
            ->name('select2.customers');
        Route::get('/select2/quotation-products', [QuotationController::class, 'products'])
            ->name('select2.quotation-products');
        Route::get('/select2/project-structures', [ProjectStructureController::class, 'select2'])
            ->name('select2.project-structures');

        Route::prefix('customers')->name('customers.')->controller(CustomerController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:customers.view')->name('index');
            Route::get('/data', 'data')->middleware('can:customers.view')->name('data');
            Route::get('/create', 'create')->middleware('can:customers.create')->name('create');
            Route::post('/account-groups', 'storeAccountGroup')->middleware('can:accounts.create')->name('account-groups.store');
            Route::post('/', 'store')->name('store');
            Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:customers.delete')->name('bulk-delete');
            Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:customers.document_number_settings.update')->name('document-number-settings.update');
            Route::patch('/{customer}/restore', 'restore')->middleware('can:customers.restore')->name('restore');
            Route::get('/{customer}/clone', 'clone')->middleware('can:customers.clone')->name('clone');
            Route::get('/{customer}', 'show')->withTrashed()->middleware('can:customers.view')->name('show');
            Route::get('/{customer}/edit', 'edit')->middleware('can:customers.edit')->name('edit');
            Route::put('/{customer}', 'update')->middleware('can:customers.edit')->name('update');
            Route::delete('/{customer}', 'destroy')->middleware('can:customers.delete')->name('destroy');
        });

        Route::prefix('project-structures')->name('project-structures.')->controller(ProjectStructureController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:project_structures.view')->name('index');
            Route::get('/data', 'data')->middleware('can:project_structures.view')->name('data');
            Route::get('/select2', 'select2')->name('select2');
            Route::get('/tree', 'tree')->middleware('can:project_structures.tree.view')->name('tree');
            Route::get('/create', 'create')->middleware('can:project_structures.create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:project_structures.delete')->name('bulk-delete');
            Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:project_structures.document_number_settings.update')->name('document-number-settings.update');
            Route::patch('/{projectStructure}/restore', 'restore')->middleware('can:project_structures.restore')->name('restore');
            Route::get('/{projectStructure}/clone', 'clone')->middleware('can:project_structures.clone')->name('clone');
            Route::get('/{projectStructure}', 'show')->middleware('can:project_structures.view')->name('show');
            Route::get('/{projectStructure}/edit', 'edit')->middleware('can:project_structures.edit')->name('edit');
            Route::put('/{projectStructure}', 'update')->middleware('can:project_structures.edit')->name('update');
            Route::delete('/{projectStructure}', 'destroy')->middleware('can:project_structures.delete')->name('destroy');
        });

        Route::prefix('project-structure-models')->name('project-structure-models.')->controller(ProjectStructureModelController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:project_structure_models.view')->name('index');
            Route::get('/data', 'data')->middleware('can:project_structure_models.view')->name('data');
            Route::get('/create', 'create')->middleware('can:project_structure_models.create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:project_structure_models.delete')->name('bulk-delete');
            Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:project_structure_models.document_number_settings.update')->name('document-number-settings.update');
            Route::patch('/{projectStructureModel}/restore', 'restore')->middleware('can:project_structure_models.restore')->name('restore');
            Route::get('/{projectStructureModel}/clone', 'clone')->middleware('can:project_structure_models.clone')->name('clone');
            Route::get('/{projectStructureModel}', 'show')->middleware('can:project_structure_models.view')->name('show');
            Route::get('/{projectStructureModel}/edit', 'edit')->middleware('can:project_structure_models.edit')->name('edit');
            Route::put('/{projectStructureModel}', 'update')->middleware('can:project_structure_models.edit')->name('update');
            Route::delete('/{projectStructureModel}', 'destroy')->middleware('can:project_structure_models.delete')->name('destroy');
        });

        Route::prefix('quotations')->name('quotations.')->controller(QuotationController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:quotations.view')->name('index');
            Route::get('/data', 'data')->middleware('can:quotations.view')->name('data');
            Route::get('/create', 'create')->middleware('can:quotations.create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:quotations.delete')->name('bulk-delete');
            Route::patch('/bulk-restore', 'bulkRestore')->middleware('can:quotations.restore')->name('bulk-restore');
            Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:quotations.document_number_settings.update')->name('document-number-settings.update');
            Route::delete('/attachments/{attachment:public_uuid}', 'destroyAttachment')->middleware('can:quotations.attachments.manage')->name('attachments.destroy');
            Route::patch('/{quotation}/restore', 'restore')->middleware('can:quotations.restore')->name('restore');
            Route::get('/{quotation}/clone', 'clone')->middleware('can:quotations.clone')->name('clone');
            Route::post('/{quotation}/revisions', 'createRevision')->middleware('can:quotations.revisions.create')->name('revisions.create');
            Route::get('/{quotation}/revisions/{revision:revision_code}', 'showRevision')->middleware('can:quotations.revisions.view')->name('revisions.show');
            Route::post('/{quotation}/mark-sent', 'markSent')->middleware('can:quotations.mark_sent')->name('mark-sent');
            Route::post('/{quotation}/accept', 'accept')->middleware('can:quotations.accept')->name('accept');
            Route::post('/{quotation}/reject', 'reject')->middleware('can:quotations.reject')->name('reject');
            Route::post('/{quotation}/cancel', 'cancel')->middleware('can:quotations.cancel')->name('cancel');
            Route::get('/{quotation}', 'show')->withTrashed()->middleware('can:quotations.view')->name('show');
            Route::get('/{quotation}/edit', 'edit')->middleware('can:quotations.edit')->name('edit');
            Route::put('/{quotation}', 'update')->middleware('can:quotations.edit')->name('update');
            Route::delete('/{quotation}', 'destroy')->middleware('can:quotations.delete')->name('destroy');
        });
    });
