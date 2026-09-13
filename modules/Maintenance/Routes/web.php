<?php

use Illuminate\Support\Facades\Route;
use Modules\Maintenance\Http\Controllers\MaintenanceController;

Route::middleware('auth')->prefix('admin/maintenance')->as('admin.maintenance.')->group(function (): void {
    Route::get('/requests', [MaintenanceController::class, 'requests'])->middleware('can:maintenance.requests.view')->name('requests.index');
    Route::post('/requests', [MaintenanceController::class, 'storeRequest'])->name('requests.store');
    Route::delete('/requests/{maintenanceRequest}', [MaintenanceController::class, 'destroyRequest'])->middleware('can:maintenance.requests.delete')->name('requests.destroy');
    Route::patch('/requests/{maintenanceRequest}/restore', [MaintenanceController::class, 'restoreRequest'])->middleware('can:maintenance.requests.restore')->name('requests.restore');
    Route::get('/orders', [MaintenanceController::class, 'orders'])->middleware('can:maintenance.orders.view')->name('orders.index');
    Route::get('/material-requests', [MaintenanceController::class, 'materialRequests'])->middleware('can:maintenance.material_requests.view')->name('material-requests.index');
    Route::post('/material-requests', [MaintenanceController::class, 'storeMaterialRequest'])->name('material-requests.store');
    Route::post('/material-requests/{maintenanceMaterialRequest}/approve', [MaintenanceController::class, 'approveMaterialRequest'])->middleware('can:maintenance.material_requests.approve')->name('material-requests.approve');
    Route::post('/material-requests/{maintenanceMaterialRequest}/issue', [MaintenanceController::class, 'issueMaterialRequest'])->middleware('can:maintenance.material_requests.issue')->name('material-requests.issue');
    Route::post('/material-requests/{maintenanceMaterialRequest}/return', [MaintenanceController::class, 'returnMaterialRequest'])->middleware('can:maintenance.material_requests.return')->name('material-requests.return');
    Route::get('/expenses', [MaintenanceController::class, 'expenses'])->middleware('can:maintenance.expenses.view')->name('expenses.index');
    Route::post('/expenses', [MaintenanceController::class, 'storeExpense'])->name('expenses.store');
    Route::post('/expenses/{productionExpenseRequest}/approve', [MaintenanceController::class, 'approveExpense'])->middleware('can:maintenance.expenses.approve')->name('expenses.approve');
    Route::post('/expenses/{productionExpenseRequest}/pay', [MaintenanceController::class, 'payExpense'])->middleware('can:maintenance.expenses.pay')->name('expenses.pay');
    Route::post('/expenses/{productionExpenseRequest}/reverse', [MaintenanceController::class, 'reverseExpense'])->middleware('can:maintenance.expenses.reverse')->name('expenses.reverse');
    Route::get('/orders/export.xlsx', [MaintenanceController::class, 'exportOrders'])->middleware('can:maintenance.orders.export')->name('orders.export');
    Route::get('/orders/create', [MaintenanceController::class, 'createOrder'])->middleware('can:maintenance.orders.create')->name('orders.create');
    Route::post('/orders', [MaintenanceController::class, 'storeOrder'])->name('orders.store');
    Route::get('/orders/{maintenanceWorkOrder}', [MaintenanceController::class, 'showOrder'])->middleware('can:maintenance.orders.view')->name('orders.show');
    Route::get('/orders/{maintenanceWorkOrder}/print', [MaintenanceController::class, 'printOrder'])->middleware('can:maintenance.orders.print')->name('orders.print');
    Route::post('/orders/{maintenanceWorkOrder}/approve', [MaintenanceController::class, 'approve'])->middleware('can:maintenance.orders.approve')->name('orders.approve');
    Route::post('/orders/{maintenanceWorkOrder}/start', [MaintenanceController::class, 'start'])->middleware('can:maintenance.orders.start')->name('orders.start');
    Route::get('/orders/{maintenanceWorkOrder}/complete', [MaintenanceController::class, 'completeForm'])->middleware('can:maintenance.orders.complete')->name('orders.complete-form');
    Route::post('/orders/{maintenanceWorkOrder}/complete', [MaintenanceController::class, 'complete'])->name('orders.complete');
    Route::post('/orders/{maintenanceWorkOrder}/close', [MaintenanceController::class, 'close'])->middleware('can:maintenance.orders.close')->name('orders.close');
});
