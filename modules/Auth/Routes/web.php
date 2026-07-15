<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\ActivityLogReportController;
use Modules\Auth\Http\Controllers\AuthenticatedSessionController;
use Modules\Auth\Http\Controllers\AuthLogReportController;
use Modules\Auth\Http\Controllers\AuthSessionReportController;
use Modules\Auth\Http\Controllers\CsrfTokenController;
use Modules\Auth\Http\Controllers\LockScreenController;
use Modules\Auth\Http\Controllers\NewPasswordController;
use Modules\Auth\Http\Controllers\PasswordResetLinkController;
use Modules\Auth\Http\Controllers\ProfileController;
use Modules\Auth\Http\Controllers\RoleController;
use Modules\Auth\Http\Controllers\Select2\RoleSelect2Controller;
use Modules\Auth\Http\Controllers\Select2\UserSelectedRolesController;
use Modules\Auth\Http\Controllers\UserController;

Route::get('/auth/csrf-token', CsrfTokenController::class)
    ->middleware('throttle:30,1')
    ->name('auth.csrf-token');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');

    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.store');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::get('/lock-screen', [LockScreenController::class, 'show'])->name('lock-screen.show');

Route::middleware('auth')->group(function (): void {
    Route::post('/lock-screen', [LockScreenController::class, 'store'])->name('lock-screen.store');
    Route::post('/lock-screen/unlock', [LockScreenController::class, 'unlock'])->name('lock-screen.unlock');

    Route::get('/profile', [ProfileController::class, 'show'])
        ->middleware('can:profile.view')
        ->name('profile.show');
    Route::get('/profile/edit', [ProfileController::class, 'edit'])
        ->middleware('can:profile.edit')
        ->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])
        ->middleware('can:profile.edit')
        ->name('profile.update');
    Route::put('/profile/default-context', [ProfileController::class, 'updateDefaultContext'])
        ->middleware('can:profile.edit')
        ->name('profile.default-context.update');
    Route::delete('/profile/default-context', [ProfileController::class, 'destroyDefaultContext'])
        ->middleware('can:profile.edit')
        ->name('profile.default-context.destroy');
    Route::put('/password', [ProfileController::class, 'updatePassword'])
        ->middleware('can:profile.password.update')
        ->name('password.update');
});

Route::middleware('auth')
    ->prefix('admin')
    ->as('admin.')
    ->group(function (): void {
        Route::get('/select2/roles', RoleSelect2Controller::class)
            ->name('select2.roles');
        Route::get('/select2/users/{user:doc_num}/roles/selected', UserSelectedRolesController::class)
            ->middleware(['can:users.edit', 'can:users.roles.manage'])
            ->name('select2.users.roles.selected');

        Route::get('/activity-logs', [ActivityLogReportController::class, 'index'])
            ->middleware('can:activity.logs.view')
            ->name('activity-logs.index');
        Route::get('/activity-logs/data', [ActivityLogReportController::class, 'data'])
            ->middleware('can:activity.logs.view')
            ->name('activity-logs.data');
        Route::get('/activity-logs/filter-options/users', [ActivityLogReportController::class, 'filterUsers'])
            ->middleware('can:activity.logs.view')
            ->name('activity-logs.filter-options.users');
        Route::get('/activity-logs/filter-options/companies', [ActivityLogReportController::class, 'filterCompanies'])
            ->middleware('can:activity.logs.view')
            ->name('activity-logs.filter-options.companies');
        Route::get('/activity-logs/filter-options/areas', [ActivityLogReportController::class, 'filterAreas'])
            ->middleware('can:activity.logs.view')
            ->name('activity-logs.filter-options.areas');
        Route::get('/activity-logs/filter-options/actions', [ActivityLogReportController::class, 'filterActions'])
            ->middleware('can:activity.logs.view')
            ->name('activity-logs.filter-options.actions');
        Route::get('/activity-logs/filter-options/statuses', [ActivityLogReportController::class, 'filterStatuses'])
            ->middleware('can:activity.logs.view')
            ->name('activity-logs.filter-options.statuses');
        Route::get('/activity-logs/export/excel', [ActivityLogReportController::class, 'exportExcel'])
            ->middleware('can:activity.logs.export')
            ->name('activity-logs.export.excel');
        Route::get('/activity-logs/export/csv', [ActivityLogReportController::class, 'exportCsv'])
            ->middleware('can:activity.logs.export')
            ->name('activity-logs.export.csv');
        Route::get('/activity-logs/export/pdf', [ActivityLogReportController::class, 'exportPdf'])
            ->middleware('can:activity.logs.pdf')
            ->name('activity-logs.export.pdf');
        Route::get('/activity-logs/{activityLog}/details', [ActivityLogReportController::class, 'details'])
            ->middleware('can:activity.logs.details')
            ->whereUuid('activityLog')
            ->name('activity-logs.details');

        Route::get('/auth-logs', [AuthLogReportController::class, 'index'])
            ->middleware('can:auth.logs.view')
            ->name('auth-logs.index');
        Route::get('/auth-logs/data', [AuthLogReportController::class, 'data'])
            ->middleware('can:auth.logs.view')
            ->name('auth-logs.data');
        Route::get('/auth-logs/filter-options/users', [AuthLogReportController::class, 'filterUsers'])
            ->middleware('can:auth.logs.view')
            ->name('auth-logs.filter-options.users');
        Route::get('/auth-logs/filter-options/events', [AuthLogReportController::class, 'filterEvents'])
            ->middleware('can:auth.logs.view')
            ->name('auth-logs.filter-options.events');
        Route::get('/auth-logs/filter-options/statuses', [AuthLogReportController::class, 'filterStatuses'])
            ->middleware('can:auth.logs.view')
            ->name('auth-logs.filter-options.statuses');
        Route::get('/auth-logs/filter-options/failure-reasons', [AuthLogReportController::class, 'filterFailureReasons'])
            ->middleware('can:auth.logs.view')
            ->name('auth-logs.filter-options.failure-reasons');
        Route::get('/auth-logs/filter-options/devices', [AuthLogReportController::class, 'filterDevices'])
            ->middleware('can:auth.logs.view')
            ->name('auth-logs.filter-options.devices');
        Route::get('/auth-logs/filter-options/browsers', [AuthLogReportController::class, 'filterBrowsers'])
            ->middleware('can:auth.logs.view')
            ->name('auth-logs.filter-options.browsers');
        Route::get('/auth-logs/filter-options/operating-systems', [AuthLogReportController::class, 'filterOperatingSystems'])
            ->middleware('can:auth.logs.view')
            ->name('auth-logs.filter-options.operating-systems');
        Route::get('/auth-logs/filter-options/countries', [AuthLogReportController::class, 'filterCountries'])
            ->middleware('can:auth.logs.view')
            ->name('auth-logs.filter-options.countries');
        Route::get('/auth-logs/filter-options/cities', [AuthLogReportController::class, 'filterCities'])
            ->middleware('can:auth.logs.view')
            ->name('auth-logs.filter-options.cities');
        Route::get('/auth-logs/filter-options/guards', [AuthLogReportController::class, 'filterGuards'])
            ->middleware('can:auth.logs.view')
            ->name('auth-logs.filter-options.guards');
        Route::get('/auth-logs/export/excel', [AuthLogReportController::class, 'exportExcel'])
            ->middleware('can:auth.logs.export')
            ->name('auth-logs.export.excel');
        Route::get('/auth-logs/export/csv', [AuthLogReportController::class, 'exportCsv'])
            ->middleware('can:auth.logs.export')
            ->name('auth-logs.export.csv');
        Route::get('/auth-logs/export/pdf', [AuthLogReportController::class, 'exportPdf'])
            ->middleware('can:auth.logs.pdf')
            ->name('auth-logs.export.pdf');
        Route::get('/auth-logs/{authLog}/details', [AuthLogReportController::class, 'details'])
            ->middleware('can:auth.logs.details')
            ->whereUuid('authLog')
            ->name('auth-logs.details');

        Route::get('/auth-sessions', [AuthSessionReportController::class, 'index'])
            ->middleware('can:auth.sessions.view')
            ->name('auth-sessions.index');
        Route::get('/auth-sessions/data', [AuthSessionReportController::class, 'data'])
            ->middleware('can:auth.sessions.view')
            ->name('auth-sessions.data');
        Route::get('/auth-sessions/filter-options/users', [AuthSessionReportController::class, 'filterUsers'])
            ->middleware('can:auth.sessions.view')
            ->name('auth-sessions.filter-options.users');
        Route::get('/auth-sessions/filter-options/presence-statuses', [AuthSessionReportController::class, 'filterPresenceStatuses'])
            ->middleware('can:auth.sessions.view')
            ->name('auth-sessions.filter-options.presence-statuses');
        Route::get('/auth-sessions/filter-options/account-statuses', [AuthSessionReportController::class, 'filterAccountStatuses'])
            ->middleware('can:auth.sessions.view')
            ->name('auth-sessions.filter-options.account-statuses');
        Route::get('/auth-sessions/filter-options/devices', [AuthSessionReportController::class, 'filterDevices'])
            ->middleware('can:auth.sessions.view')
            ->name('auth-sessions.filter-options.devices');
        Route::get('/auth-sessions/filter-options/browsers', [AuthSessionReportController::class, 'filterBrowsers'])
            ->middleware('can:auth.sessions.view')
            ->name('auth-sessions.filter-options.browsers');
        Route::get('/auth-sessions/filter-options/operating-systems', [AuthSessionReportController::class, 'filterOperatingSystems'])
            ->middleware('can:auth.sessions.view')
            ->name('auth-sessions.filter-options.operating-systems');
        Route::get('/auth-sessions/filter-options/offline-reasons', [AuthSessionReportController::class, 'filterOfflineReasons'])
            ->middleware('can:auth.sessions.view')
            ->name('auth-sessions.filter-options.offline-reasons');
        Route::get('/auth-sessions/export/excel', [AuthSessionReportController::class, 'exportExcel'])
            ->middleware('can:auth.sessions.export')
            ->name('auth-sessions.export.excel');
        Route::get('/auth-sessions/export/csv', [AuthSessionReportController::class, 'exportCsv'])
            ->middleware('can:auth.sessions.export')
            ->name('auth-sessions.export.csv');
        Route::get('/auth-sessions/export/pdf', [AuthSessionReportController::class, 'exportPdf'])
            ->middleware('can:auth.sessions.pdf')
            ->name('auth-sessions.export.pdf');
        Route::get('/auth-sessions/{session}/details', [AuthSessionReportController::class, 'details'])
            ->middleware('can:auth.sessions.details')
            ->whereUuid('session')
            ->name('auth-sessions.details');
        Route::post('/auth-sessions/{session}/force-logout', [AuthSessionReportController::class, 'forceLogout'])
            ->middleware('can:auth.sessions.force_logout')
            ->whereUuid('session')
            ->name('auth-sessions.force-logout');

        Route::get('/users', [UserController::class, 'index'])
            ->middleware('can:users.view')
            ->name('users.index');
        Route::get('/users/data', [UserController::class, 'data'])
            ->middleware('can:users.view')
            ->name('users.data');
        Route::get('/users/create', [UserController::class, 'create'])
            ->middleware('can:users.create')
            ->name('users.create');
        Route::post('/users', [UserController::class, 'store'])
            ->name('users.store');
        Route::delete('/users/bulk-delete', [UserController::class, 'bulkDelete'])
            ->middleware('can:users.delete')
            ->name('users.bulk-delete');
        Route::put('/users/document-number-settings', [UserController::class, 'updateDocumentNumberSettings'])
            ->middleware('can:users.document_number_settings.update')
            ->name('users.document-number-settings.update');
        Route::patch('/users/{user:doc_num}/restore', [UserController::class, 'restore'])
            ->middleware('can:users.restore')
            ->name('users.restore');
        Route::get('/users/{user:doc_num}/clone', [UserController::class, 'clone'])
            ->middleware('can:users.clone')
            ->name('users.clone');
        Route::get('/users/{user:doc_num}', [UserController::class, 'show'])
            ->withTrashed()
            ->middleware('can:users.view')
            ->name('users.show');
        Route::get('/users/{user:doc_num}/edit', [UserController::class, 'edit'])
            ->middleware('can:users.edit')
            ->name('users.edit');
        Route::put('/users/{user:doc_num}', [UserController::class, 'update'])
            ->middleware('can:users.edit')
            ->name('users.update');
        Route::delete('/users/{user:doc_num}', [UserController::class, 'destroy'])
            ->middleware('can:users.delete')
            ->name('users.destroy');

        Route::get('/roles', [RoleController::class, 'index'])
            ->middleware('can:roles.view')
            ->name('roles.index');
        Route::get('/roles/data', [RoleController::class, 'data'])
            ->middleware('can:roles.view')
            ->name('roles.data');
        Route::get('/roles/create', [RoleController::class, 'create'])
            ->middleware('can:roles.create')
            ->name('roles.create');
        Route::post('/roles', [RoleController::class, 'store'])
            ->name('roles.store');
        Route::delete('/roles/bulk-delete', [RoleController::class, 'bulkDelete'])
            ->middleware('can:roles.delete')
            ->name('roles.bulk-delete');
        Route::put('/roles/document-number-settings', [RoleController::class, 'updateDocumentNumberSettings'])
            ->middleware('can:roles.document_number_settings.update')
            ->name('roles.document-number-settings.update');
        Route::patch('/roles/{role:doc_num}/restore', [RoleController::class, 'restore'])
            ->middleware('can:roles.restore')
            ->name('roles.restore');
        Route::get('/roles/{role:doc_num}/clone', [RoleController::class, 'clone'])
            ->middleware('can:roles.clone')
            ->name('roles.clone');
        Route::get('/roles/{role:doc_num}', [RoleController::class, 'show'])
            ->withTrashed()
            ->middleware('can:roles.view')
            ->name('roles.show');
        Route::get('/roles/{role:doc_num}/edit', [RoleController::class, 'edit'])
            ->middleware('can:roles.edit')
            ->name('roles.edit');
        Route::put('/roles/{role:doc_num}', [RoleController::class, 'update'])
            ->middleware('can:roles.edit')
            ->name('roles.update');
        Route::delete('/roles/{role:doc_num}', [RoleController::class, 'destroy'])
            ->middleware('can:roles.delete')
            ->name('roles.destroy');
    });
