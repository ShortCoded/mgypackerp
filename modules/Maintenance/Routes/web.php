<?php

use Illuminate\Support\Facades\Route;
use Modules\Maintenance\Http\Controllers\MaintenanceController;
use Modules\Maintenance\Http\Controllers\MaintenancePlanController;

Route::middleware('auth')->prefix('admin/maintenance')->as('admin.maintenance.')->group(function (): void {
    Route::get('/plans', [MaintenancePlanController::class, 'index'])->middleware('can:maintenance.plans.view')->name('plans.index');
    Route::post('/plans', [MaintenancePlanController::class, 'store'])->name('plans.store');
    Route::post('/plans/{maintenancePlan}/approve', [MaintenancePlanController::class, 'approve'])->middleware('can:maintenance.plans.approve')->name('plans.approve');
    Route::post('/plans/{maintenancePlan}/generate', [MaintenancePlanController::class, 'generate'])->middleware('can:maintenance.plans.generate')->name('plans.generate');
    Route::post('/plans/{maintenancePlan}/readings', [MaintenancePlanController::class, 'recordReading'])->name('plans.readings.store');
    Route::post('/plan-dues/{maintenancePlanDue}/convert', [MaintenancePlanController::class, 'convert'])->middleware('can:maintenance.plans.execute')->name('plans.dues.convert');
    Route::get('/requests', [MaintenanceController::class, 'requests'])->middleware('can:maintenance.requests.view')->name('requests.index');
    Route::get('/requests/create', [MaintenanceController::class, 'createRequest'])->middleware('can:maintenance.requests.create')->name('requests.create');
    Route::post('/requests', [MaintenanceController::class, 'storeRequest'])->name('requests.store');
    Route::delete('/requests/bulk-delete', [MaintenanceController::class, 'bulkDeleteRequests'])->middleware('can:maintenance.requests.delete')->name('requests.bulk-delete');
    Route::get('/requests/{maintenanceRequest}/edit', [MaintenanceController::class, 'editRequest'])->middleware('can:maintenance.requests.edit')->name('requests.edit');
    Route::put('/requests/{maintenanceRequest}', [MaintenanceController::class, 'updateRequest'])->name('requests.update');
    Route::delete('/requests/{maintenanceRequest}', [MaintenanceController::class, 'destroyRequest'])->middleware('can:maintenance.requests.delete')->name('requests.destroy');
    Route::patch('/requests/{maintenanceRequest}/restore', [MaintenanceController::class, 'restoreRequest'])->middleware('can:maintenance.requests.restore')->name('requests.restore');
    Route::get('/requests/{maintenanceRequest}', [MaintenanceController::class, 'showRequest'])->middleware('can:maintenance.requests.view')->name('requests.show');
    Route::get('/orders', [MaintenanceController::class, 'orders'])->middleware('can:maintenance.orders.view')->name('orders.index');
    Route::get('/material-requests', [MaintenanceController::class, 'materialRequests'])->middleware('can:maintenance.material_requests.view')->name('material-requests.index');
    Route::get('/material-requests/create', [MaintenanceController::class, 'createMaterialRequest'])->middleware('can:maintenance.material_requests.create')->name('material-requests.create');
    Route::post('/material-requests', [MaintenanceController::class, 'storeMaterialRequest'])->name('material-requests.store');
    Route::delete('/material-requests/bulk-delete', [MaintenanceController::class, 'bulkDeleteMaterialRequests'])->middleware('can:maintenance.material_requests.delete')->name('material-requests.bulk-delete');
    Route::get('/material-requests/{maintenanceMaterialRequest}/edit', [MaintenanceController::class, 'editMaterialRequest'])->middleware('can:maintenance.material_requests.edit')->name('material-requests.edit');
    Route::put('/material-requests/{maintenanceMaterialRequest}', [MaintenanceController::class, 'updateMaterialRequest'])->name('material-requests.update');
    Route::delete('/material-requests/{maintenanceMaterialRequest}', [MaintenanceController::class, 'destroyMaterialRequest'])->middleware('can:maintenance.material_requests.delete')->name('material-requests.destroy');
    Route::patch('/material-requests/{maintenanceMaterialRequest}/restore', [MaintenanceController::class, 'restoreMaterialRequest'])->middleware('can:maintenance.material_requests.restore')->name('material-requests.restore');
    Route::get('/material-requests/{maintenanceMaterialRequest}', [MaintenanceController::class, 'showMaterialRequest'])->middleware('can:maintenance.material_requests.view')->name('material-requests.show');
    Route::post('/material-requests/{maintenanceMaterialRequest}/approve', [MaintenanceController::class, 'approveMaterialRequest'])->middleware('can:maintenance.material_requests.approve')->name('material-requests.approve');
    Route::post('/material-requests/{maintenanceMaterialRequest}/issue', [MaintenanceController::class, 'issueMaterialRequest'])->middleware('can:maintenance.material_requests.issue')->name('material-requests.issue');
    Route::post('/material-requests/{maintenanceMaterialRequest}/return', [MaintenanceController::class, 'returnMaterialRequest'])->middleware('can:maintenance.material_requests.return')->name('material-requests.return');
    Route::get('/expenses', [MaintenanceController::class, 'expenses'])->middleware('can:maintenance.expenses.view')->name('expenses.index');
    Route::get('/expenses/create', [MaintenanceController::class, 'createExpense'])->middleware('can:maintenance.expenses.create')->name('expenses.create');
    Route::post('/expenses', [MaintenanceController::class, 'storeExpense'])->name('expenses.store');
    Route::delete('/expenses/bulk-delete', [MaintenanceController::class, 'bulkDeleteExpenses'])->middleware('can:maintenance.expenses.delete')->name('expenses.bulk-delete');
    Route::get('/expenses/{productionExpenseRequest}/edit', [MaintenanceController::class, 'editExpense'])->middleware('can:maintenance.expenses.edit')->name('expenses.edit');
    Route::put('/expenses/{productionExpenseRequest}', [MaintenanceController::class, 'updateExpense'])->name('expenses.update');
    Route::delete('/expenses/{productionExpenseRequest}', [MaintenanceController::class, 'destroyExpense'])->middleware('can:maintenance.expenses.delete')->name('expenses.destroy');
    Route::patch('/expenses/{productionExpenseRequest}/restore', [MaintenanceController::class, 'restoreExpense'])->middleware('can:maintenance.expenses.restore')->name('expenses.restore');
    Route::get('/expenses/{productionExpenseRequest}', [MaintenanceController::class, 'showExpense'])->middleware('can:maintenance.expenses.view')->name('expenses.show');
    Route::post('/expenses/{productionExpenseRequest}/approve', [MaintenanceController::class, 'approveExpense'])->middleware('can:maintenance.expenses.approve')->name('expenses.approve');
    Route::post('/expenses/{productionExpenseRequest}/pay', [MaintenanceController::class, 'payExpense'])->middleware('can:maintenance.expenses.pay')->name('expenses.pay');
    Route::post('/expenses/{productionExpenseRequest}/reverse', [MaintenanceController::class, 'reverseExpense'])->middleware('can:maintenance.expenses.reverse')->name('expenses.reverse');
    Route::get('/reports', [MaintenanceController::class, 'reports'])->middleware('can:maintenance.reports.view')->name('reports.index');
    Route::get('/reports/export.xlsx', [MaintenanceController::class, 'exportReport'])->middleware('can:maintenance.reports.export')->name('reports.export');
    Route::get('/reports/print', [MaintenanceController::class, 'printReport'])->middleware('can:maintenance.reports.export')->name('reports.print');
    Route::get('/select2/{lookup}', [MaintenanceController::class, 'select2'])
        ->middleware('can:maintenance.orders.view')
        ->whereIn('lookup', ['assets', 'molds', 'maintainables', 'suppliers'])
        ->name('select2');
    Route::get('/orders/export.xlsx', [MaintenanceController::class, 'exportOrders'])->middleware('can:maintenance.orders.export')->name('orders.export');
    Route::get('/orders/create', [MaintenanceController::class, 'createOrder'])->middleware('can:maintenance.orders.create')->name('orders.create');
    Route::post('/orders', [MaintenanceController::class, 'storeOrder'])->name('orders.store');
    Route::delete('/orders/bulk-delete', [MaintenanceController::class, 'bulkDeleteOrders'])->middleware('can:maintenance.orders.delete')->name('orders.bulk-delete');
    Route::get('/orders/{maintenanceWorkOrder}/edit', [MaintenanceController::class, 'editOrder'])->middleware('can:maintenance.orders.edit')->name('orders.edit');
    Route::put('/orders/{maintenanceWorkOrder}', [MaintenanceController::class, 'updateOrder'])->name('orders.update');
    Route::delete('/orders/{maintenanceWorkOrder}', [MaintenanceController::class, 'destroyOrder'])->middleware('can:maintenance.orders.delete')->name('orders.destroy');
    Route::patch('/orders/{maintenanceWorkOrder}/restore', [MaintenanceController::class, 'restoreOrder'])->middleware('can:maintenance.orders.restore')->name('orders.restore');
    Route::get('/orders/{maintenanceWorkOrder}', [MaintenanceController::class, 'showOrder'])->middleware('can:maintenance.orders.view')->name('orders.show');
    Route::get('/orders/{maintenanceWorkOrder}/print', [MaintenanceController::class, 'printOrder'])->middleware('can:maintenance.orders.print')->name('orders.print');
    Route::post('/orders/{maintenanceWorkOrder}/approve', [MaintenanceController::class, 'approve'])->middleware('can:maintenance.orders.approve')->name('orders.approve');
    Route::post('/orders/{maintenanceWorkOrder}/start', [MaintenanceController::class, 'start'])->middleware('can:maintenance.orders.start')->name('orders.start');
    Route::post('/orders/{maintenanceWorkOrder}/events/{eventAction}', [MaintenanceController::class, 'recordEvent'])
        ->whereIn('eventAction', ['pause', 'resume', 'external-dispatch', 'external-receive'])
        ->name('orders.events.store');
    Route::get('/orders/{maintenanceWorkOrder}/complete', [MaintenanceController::class, 'completeForm'])->middleware('can:maintenance.orders.complete')->name('orders.complete-form');
    Route::post('/orders/{maintenanceWorkOrder}/complete', [MaintenanceController::class, 'complete'])->name('orders.complete');
    Route::post('/orders/{maintenanceWorkOrder}/close', [MaintenanceController::class, 'close'])->middleware('can:maintenance.orders.close')->name('orders.close');
});
