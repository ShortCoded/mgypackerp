<?php

use App\Http\Controllers\PushSubscriptionController;
use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\BranchController;
use Modules\Core\Http\Controllers\CalendarController;
use Modules\Core\Http\Controllers\ChatController;
use Modules\Core\Http\Controllers\CompanyController;
use Modules\Core\Http\Controllers\CurrencyController;
use Modules\Core\Http\Controllers\ExcelImportController;
use Modules\Core\Http\Controllers\FileManagerController;
use Modules\Core\Http\Controllers\FinancialPeriodController;
use Modules\Core\Http\Controllers\ItemLookupController;
use Modules\Core\Http\Controllers\MyBoardController;
use Modules\Core\Http\Controllers\MyBoardTableController;
use Modules\Core\Http\Controllers\NavigationSearchController;
use Modules\Core\Http\Controllers\NotificationController;
use Modules\Core\Http\Controllers\OpenDocumentsController;
use Modules\Core\Http\Controllers\OperatingContextController;
use Modules\Core\Http\Controllers\ProductComponentController;
use Modules\Core\Http\Controllers\ProductController;
use Modules\Core\Http\Controllers\ProductDataReportController;
use Modules\Core\Http\Controllers\ProductLookupInlineController;
use Modules\Core\Http\Controllers\PublicArchiveController;
use Modules\Core\Http\Controllers\PublicTaskBoardDisplayController;
use Modules\Core\Http\Controllers\PwaSettingsController;
use Modules\Core\Http\Controllers\QuickTaskController;
use Modules\Core\Http\Controllers\Select2\BranchRefrigeratorSelect2Controller;
use Modules\Core\Http\Controllers\Select2\BranchSelect2Controller;
use Modules\Core\Http\Controllers\Select2\CompanyLocationSelect2Controller;
use Modules\Core\Http\Controllers\Select2\CompanyLocationSelectedController;
use Modules\Core\Http\Controllers\Select2\CompanySelect2Controller;
use Modules\Core\Http\Controllers\Select2\FinancialPeriodSelect2Controller;
use Modules\Core\Http\Controllers\Select2\FinishedProductSelect2Controller;
use Modules\Core\Http\Controllers\Select2\ItemLookupSelect2Controller;
use Modules\Core\Http\Controllers\Select2\ItemUnitSelect2Controller;
use Modules\Core\Http\Controllers\Select2\LocationInlineController;
use Modules\Core\Http\Controllers\Select2\ProductRawMaterialSelect2Controller;
use Modules\Core\Http\Controllers\Select2\TaskBoardSelect2Controller;
use Modules\Core\Http\Controllers\Select2\UserSelect2Controller;
use Modules\Core\Http\Controllers\SessionController;
use Modules\Core\Http\Controllers\TaskBoardController;
use Modules\Core\Models\UserTask;

Route::get('/session/status', [SessionController::class, 'status'])->name('session.status');
Route::post('/session/touch', [SessionController::class, 'touch'])->middleware('auth')->name('session.touch');
Route::get('/manifest.webmanifest', [PwaSettingsController::class, 'manifest'])->name('pwa.manifest');
Route::get('/pwa-service-worker.js', [PwaSettingsController::class, 'serviceWorker'])->name('pwa.service-worker');
Route::get('/offline', [PwaSettingsController::class, 'offline'])->name('pwa.offline');

Route::prefix('public/archive')
    ->as('public.archive.')
    ->where(['token' => '[A-Za-z0-9]{40,120}'])
    ->group(function (): void {
        Route::get('/files/{token}', [PublicArchiveController::class, 'file'])
            ->name('files.show');
        Route::get('/files/{token}/preview', [PublicArchiveController::class, 'previewFile'])
            ->name('files.preview');
        Route::get('/files/{token}/download', [PublicArchiveController::class, 'downloadFile'])
            ->name('files.download');
        Route::get('/folders/{token}', [PublicArchiveController::class, 'folder'])
            ->name('folders.show');
        Route::get('/folders/{token}/files/{file:doc_num}/preview', [PublicArchiveController::class, 'previewFolderFile'])
            ->name('folders.files.preview');
        Route::get('/folders/{token}/files/{file:doc_num}/download', [PublicArchiveController::class, 'downloadFolderFile'])
            ->name('folders.files.download');
    });

Route::prefix('task-boards/display')
    ->as('public.task-boards.')
    ->where(['publicToken' => '[A-Za-z0-9]{40,120}'])
    ->controller(PublicTaskBoardDisplayController::class)
    ->group(function (): void {
        Route::get('/{publicToken}', 'show')
            ->name('display');
        Route::get('/{publicToken}/users', 'userDisplay')
            ->name('user-display');
        Route::post('/{publicToken}/access', 'authenticate')
            ->middleware('throttle:10,1')
            ->name('display.access');
        Route::get('/{publicToken}/data', 'data')
            ->middleware('throttle:120,1')
            ->name('display-data');
        Route::get('/{publicToken}/users/data', 'userDisplayData')
            ->middleware('throttle:120,1')
            ->name('user-display-data');
        Route::post('/{publicToken}/change-status', 'changeStatus')
            ->middleware('throttle:30,1')
            ->name('display.change-status');
        Route::get('/{publicToken}/attachments/{attachment:public_uuid}', 'showAttachment')
            ->name('display.attachment');
        Route::get('/{publicToken}/attachments/{attachment:public_uuid}/download', 'downloadAttachment')
            ->name('display.attachment.download');
        Route::get('/{publicToken}/user-tasks/{userTask:doc_num}/attachments/{file:doc_num}', 'showUserTaskAttachment')
            ->withoutScopedBindings()
            ->name('display.user-task-attachment');
        Route::get('/{publicToken}/user-tasks/{userTask:doc_num}/attachments/{file:doc_num}/download', 'downloadUserTaskAttachment')
            ->withoutScopedBindings()
            ->name('display.user-task-attachment.download');
    });

Route::middleware('auth')
    ->prefix('admin')
    ->as('admin.')
    ->group(function (): void {
        Route::get('/select2/companies', CompanySelect2Controller::class)
            ->name('select2.companies');
        Route::get('/select2/branches', BranchSelect2Controller::class)
            ->name('select2.branches');
        Route::get('/select2/financial-periods', FinancialPeriodSelect2Controller::class)
            ->name('select2.financial-periods');
        Route::get('/select2/currencies', [CurrencyController::class, 'select2'])
            ->name('select2.currencies');
        Route::get('/select2/users', UserSelect2Controller::class)
            ->name('select2.users');
        Route::get('/select2/task-boards', TaskBoardSelect2Controller::class)
            ->name('select2.task-boards');
        Route::get('/select2/item-units', ItemUnitSelect2Controller::class)
            ->name('select2.item-units');
        Route::get('/select2/branch-refrigerators', BranchRefrigeratorSelect2Controller::class)
            ->name('select2.branch-refrigerators');
        foreach (['item-sizes', 'item-colors', 'item-decals', 'item-models', 'item-categories', 'item-groups', 'item-origin-countries'] as $lookup) {
            Route::get("/select2/{$lookup}", ItemLookupSelect2Controller::class)
                ->defaults('lookup', $lookup)
                ->name("select2.{$lookup}");
        }
        Route::get('/select2/component-products', ProductRawMaterialSelect2Controller::class)
            ->name('select2.component-products');
        Route::get('/select2/raw-material-products', ProductRawMaterialSelect2Controller::class)
            ->name('select2.raw-material-products');
        Route::get('/select2/finished-products', FinishedProductSelect2Controller::class)
            ->name('select2.finished-products');
        Route::get('/select2/countries', [CompanyLocationSelect2Controller::class, 'countries'])
            ->name('select2.countries');
        Route::get('/select2/governorates', [CompanyLocationSelect2Controller::class, 'governorates'])
            ->name('select2.governorates');
        Route::get('/select2/cities', [CompanyLocationSelect2Controller::class, 'cities'])
            ->name('select2.cities');
        Route::get('/select2/areas', [CompanyLocationSelect2Controller::class, 'areas'])
            ->name('select2.areas');
        Route::post('/select2/inline/locations/{type}', LocationInlineController::class)
            ->whereIn('type', ['countries', 'governorates', 'cities', 'areas'])
            ->name('select2.inline.locations.store');

        Route::get('/notifications/poll', [NotificationController::class, 'poll'])
            ->name('notifications.poll');
        Route::post('/notifications/{notification:public_uuid}/read', [NotificationController::class, 'read'])
            ->name('notifications.read');
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])
            ->name('notifications.read-all');
        Route::post('/notifications/push-subscriptions', [PushSubscriptionController::class, 'store'])
            ->name('notifications.push-subscriptions.store');
        Route::delete('/notifications/push-subscriptions', [PushSubscriptionController::class, 'destroy'])
            ->name('notifications.push-subscriptions.destroy');

        Route::get('/navigation-search', [NavigationSearchController::class, 'index'])
            ->name('navigation-search');
        Route::post('/navigation-search/recent', [NavigationSearchController::class, 'storeRecent'])
            ->name('navigation-search.recent.store');
        Route::delete('/navigation-search/recent', [NavigationSearchController::class, 'clearRecent'])
            ->name('navigation-search.recent.clear');

        Route::prefix('reports/products-data')
            ->name('reports.products-data.')
            ->controller(ProductDataReportController::class)
            ->group(function (): void {
                Route::get('/', 'index')
                    ->middleware('can:reports.products_data.view')
                    ->name('index');
                Route::get('/data', 'data')
                    ->middleware('can:reports.products_data.view')
                    ->name('data');
                Route::get('/filter-options/products', 'filterProducts')
                    ->middleware('can:reports.products_data.view')
                    ->name('filter-options.products');
                Route::get('/filter-options/components', 'filterComponents')
                    ->middleware('can:reports.products_data.view')
                    ->name('filter-options.components');
                Route::get('/export/excel', 'exportExcel')
                    ->middleware('can:reports.products_data.export')
                    ->name('export.excel');
                Route::get('/export/csv', 'exportCsv')
                    ->middleware('can:reports.products_data.export')
                    ->name('export.csv');
                Route::get('/export/pdf', 'exportPdf')
                    ->middleware('can:reports.products_data.pdf')
                    ->name('export.pdf');
            });

        Route::get('/operating-context/options', [OperatingContextController::class, 'options'])
            ->name('operating-context.options');
        Route::post('/operating-context/select', [OperatingContextController::class, 'select'])
            ->name('operating-context.select');
        Route::post('/operating-context/clear', [OperatingContextController::class, 'clear'])
            ->name('operating-context.clear');

        Route::get('/settings/pwa', [PwaSettingsController::class, 'index'])
            ->middleware('can:settings.pwa.view')
            ->name('settings.pwa');
        Route::post('/settings/pwa', [PwaSettingsController::class, 'update'])
            ->middleware('can:settings.pwa.update')
            ->name('settings.pwa.update');

        Route::prefix('tools/open-documents')
            ->name('tools.open-documents.')
            ->controller(OpenDocumentsController::class)
            ->group(function (): void {
                Route::get('/', 'index')
                    ->middleware('can:tools.open_documents.view')
                    ->name('index');
                Route::post('/', 'store')
                    ->middleware('can:tools.open_documents.execute')
                    ->name('store');
            });

        Route::prefix('tools/team-board')
            ->name('tools.team-board.')
            ->group(function (): void {
                Route::get('/', [MyBoardController::class, 'teamBoard'])
                    ->name('index');
                Route::get('/tasks', [MyBoardController::class, 'teamTasksData'])
                    ->middleware('can:my_board.tasks.view_all')
                    ->name('tasks.data');
                Route::get('/notes', [MyBoardController::class, 'teamNotesData'])
                    ->middleware('can:my_board.notes.view_all')
                    ->name('notes.data');

                Route::get('/tasks/create', [MyBoardTableController::class, 'createTask'])
                    ->middleware(['can:my_board.tasks.view_all', 'can:my_board.create'])
                    ->name('tasks.create');
                Route::post('/tasks', [MyBoardTableController::class, 'storeTask'])
                    ->middleware(['can:my_board.tasks.view_all', 'can:my_board.create'])
                    ->name('tasks.store');
                Route::get('/tasks/{userTask:doc_num}/attachments/{file:doc_num}', [MyBoardTableController::class, 'showTaskAttachment'])
                    ->middleware('can:my_board.tasks.view_all')
                    ->withoutScopedBindings()
                    ->withTrashed()
                    ->name('tasks.attachments.show');
                Route::get('/tasks/{userTask:doc_num}/attachments/{file:doc_num}/download', [MyBoardTableController::class, 'downloadTaskAttachment'])
                    ->middleware('can:my_board.tasks.view_all')
                    ->withoutScopedBindings()
                    ->withTrashed()
                    ->name('tasks.attachments.download');
                Route::delete('/tasks/{userTask:doc_num}/attachments/{file:doc_num}', [MyBoardTableController::class, 'destroyTaskAttachment'])
                    ->middleware(['can:my_board.tasks.view_all', 'can:my_board.edit', 'can:quick_tasks.manage_attachments'])
                    ->withoutScopedBindings()
                    ->name('tasks.attachments.destroy');
                Route::get('/tasks/{userTask:doc_num}', [MyBoardTableController::class, 'showTask'])
                    ->middleware('can:my_board.tasks.view_all')
                    ->withTrashed()
                    ->name('tasks.show');
                Route::get('/tasks/{userTask:doc_num}/edit', [MyBoardTableController::class, 'editTask'])
                    ->middleware(['can:my_board.tasks.view_all', 'can:my_board.edit'])
                    ->name('tasks.edit');
                Route::post('/tasks/{userTask:doc_num}/clone', [MyBoardTableController::class, 'cloneTask'])
                    ->middleware(['can:my_board.tasks.view_all', 'can:my_board.clone'])
                    ->name('tasks.clone');
                Route::patch('/tasks/{userTask}/restore', [MyBoardTableController::class, 'restoreTask'])
                    ->middleware(['can:my_board.tasks.view_all', 'can:my_board.restore'])
                    ->name('tasks.restore');
                Route::put('/tasks/{userTask:doc_num}', [MyBoardTableController::class, 'updateTask'])
                    ->middleware(['can:my_board.tasks.view_all', 'can:my_board.edit'])
                    ->name('tasks.update');
                Route::patch('/tasks/{userTask:doc_num}/status', [MyBoardTableController::class, 'updateTaskStatus'])
                    ->middleware(['can:my_board.tasks.view_all', 'can:my_board.reorder'])
                    ->name('tasks.status');
                Route::delete('/tasks/{userTask:doc_num}', [MyBoardTableController::class, 'destroyTask'])
                    ->middleware(['can:my_board.tasks.view_all', 'can:my_board.delete'])
                    ->name('tasks.destroy');

                Route::get('/notes/create', [MyBoardTableController::class, 'createNote'])
                    ->middleware(['can:my_board.notes.view_all', 'can:my_board.create'])
                    ->name('notes.create');
                Route::post('/notes', [MyBoardTableController::class, 'storeNote'])
                    ->middleware(['can:my_board.notes.view_all', 'can:my_board.create'])
                    ->name('notes.store');
                Route::get('/notes/{userTask:doc_num}', [MyBoardTableController::class, 'showNote'])
                    ->middleware('can:my_board.notes.view_all')
                    ->withTrashed()
                    ->name('notes.show');
                Route::get('/notes/{userTask:doc_num}/edit', [MyBoardTableController::class, 'editNote'])
                    ->middleware(['can:my_board.notes.view_all', 'can:my_board.edit'])
                    ->name('notes.edit');
                Route::post('/notes/{userTask:doc_num}/clone', [MyBoardTableController::class, 'cloneNote'])
                    ->middleware(['can:my_board.notes.view_all', 'can:my_board.clone'])
                    ->name('notes.clone');
                Route::patch('/notes/{userTask}/restore', [MyBoardTableController::class, 'restoreNote'])
                    ->middleware(['can:my_board.notes.view_all', 'can:my_board.restore'])
                    ->name('notes.restore');
                Route::put('/notes/{userTask:doc_num}', [MyBoardTableController::class, 'updateNote'])
                    ->middleware(['can:my_board.notes.view_all', 'can:my_board.edit'])
                    ->name('notes.update');
                Route::patch('/notes/{userTask:doc_num}/status', [MyBoardTableController::class, 'updateNoteStatus'])
                    ->middleware(['can:my_board.notes.view_all', 'can:my_board.reorder'])
                    ->name('notes.status');
                Route::delete('/notes/{userTask:doc_num}', [MyBoardTableController::class, 'destroyNote'])
                    ->middleware(['can:my_board.notes.view_all', 'can:my_board.delete'])
                    ->name('notes.destroy');

                Route::delete('/bulk-delete', [MyBoardTableController::class, 'bulkDelete'])
                    ->middleware('can:my_board.delete')
                    ->name('bulk-delete');
                Route::patch('/bulk-active-state', [MyBoardTableController::class, 'bulkActiveState'])
                    ->middleware('can:my_board.edit')
                    ->name('bulk-active-state');
                Route::patch('/bulk-restore', [MyBoardTableController::class, 'bulkRestore'])
                    ->middleware('can:my_board.restore')
                    ->name('bulk-restore');
            });
        Route::redirect('/tools/users-tasks-report', '/admin/tools/team-board')
            ->name('tools.users-tasks-report.index');

        Route::prefix('quick-tasks')
            ->name('quick-tasks.')
            ->group(function (): void {
                Route::get('/attachments/{attachment:public_uuid}', [QuickTaskController::class, 'showAttachment'])
                    ->middleware('can:quick_tasks.view')
                    ->name('attachments.show');
                Route::get('/attachments/{attachment:public_uuid}/download', [QuickTaskController::class, 'downloadAttachment'])
                    ->middleware('can:quick_tasks.view')
                    ->name('attachments.download');
                Route::delete('/attachments/{attachment:public_uuid}', fn () => redirect()->route('admin.tools.team-board.index', [], 303))
                    ->middleware('can:quick_tasks.manage_attachments')
                    ->name('attachments.destroy');

                Route::redirect('/', '/admin/tools/team-board')
                    ->middleware('can:quick_tasks.view')
                    ->name('index');
                Route::get('/data', fn () => redirect()->route('admin.tools.team-board.tasks.data', request()->query()))
                    ->middleware('can:quick_tasks.view')
                    ->name('data');
                Route::get('/create', fn () => redirect()->route('admin.tools.team-board.tasks.create', request()->query()))
                    ->middleware('can:quick_tasks.create')
                    ->name('create');
                Route::post('/', fn () => redirect()->route('admin.tools.team-board.index', [], 303))
                    ->middleware('can:quick_tasks.create')
                    ->name('store');
                Route::delete('/bulk-delete', fn () => redirect()->route('admin.tools.team-board.index', [], 303))
                    ->middleware('can:quick_tasks.delete')
                    ->name('bulk-delete');
                Route::patch('/bulk-restore', fn () => redirect()->route('admin.tools.team-board.index', [], 303))
                    ->middleware('can:quick_tasks.restore')
                    ->name('bulk-restore');
                Route::patch('/{quickTask}/status', fn () => redirect()->route('admin.tools.team-board.index', [], 303))
                    ->middleware('can:quick_tasks.change_status')
                    ->name('change-status');
                Route::patch('/{quickTask}/restore', fn () => redirect()->route('admin.tools.team-board.index', [], 303))
                    ->middleware('can:quick_tasks.restore')
                    ->name('restore');
                Route::get('/{quickTask}', fn () => redirect()->route('admin.tools.team-board.index'))
                    ->middleware('can:quick_tasks.view')
                    ->name('show');
                Route::get('/{quickTask}/edit', fn () => redirect()->route('admin.tools.team-board.index'))
                    ->middleware('can:quick_tasks.update')
                    ->name('edit');
                Route::put('/{quickTask}', fn () => redirect()->route('admin.tools.team-board.index', [], 303))
                    ->middleware('can:quick_tasks.update')
                    ->name('update');
                Route::delete('/{quickTask}', fn () => redirect()->route('admin.tools.team-board.index', [], 303))
                    ->middleware('can:quick_tasks.delete')
                    ->name('destroy');
            });

        Route::prefix('task-boards')
            ->name('task-boards.')
            ->controller(TaskBoardController::class)
            ->group(function (): void {
                Route::get('/', 'index')
                    ->middleware('can:task_boards.view')
                    ->name('index');
                Route::get('/data', 'data')
                    ->middleware('can:task_boards.view')
                    ->name('data');
                Route::get('/create', 'create')
                    ->middleware('can:task_boards.create')
                    ->name('create');
                Route::post('/', 'store')
                    ->name('store');
                Route::delete('/bulk-delete', 'bulkDelete')
                    ->middleware('can:task_boards.delete')
                    ->name('bulk-delete');
                Route::patch('/bulk-activate', 'bulkActivate')
                    ->middleware('can:task_boards.bulk_activate')
                    ->name('bulk-activate');
                Route::patch('/bulk-deactivate', 'bulkDeactivate')
                    ->middleware('can:task_boards.bulk_deactivate')
                    ->name('bulk-deactivate');
                Route::patch('/bulk-restore', 'bulkRestore')
                    ->middleware('can:task_boards.restore')
                    ->name('bulk-restore');
                Route::patch('/{taskBoard}/regenerate-public-url', 'regeneratePublicUrl')
                    ->middleware('can:task_boards.regenerate_public_url')
                    ->name('regenerate-public-url');
                Route::patch('/{taskBoard}/restore', 'restore')
                    ->middleware('can:task_boards.restore')
                    ->name('restore');
                Route::get('/{taskBoard}', 'show')
                    ->middleware('can:task_boards.view')
                    ->name('show');
                Route::get('/{taskBoard}/edit', 'edit')
                    ->middleware('can:task_boards.update')
                    ->name('edit');
                Route::put('/{taskBoard}', 'update')
                    ->middleware('can:task_boards.update')
                    ->name('update');
                Route::delete('/{taskBoard}', 'destroy')
                    ->middleware('can:task_boards.delete')
                    ->name('destroy');
            });

        Route::get('/calendar', [CalendarController::class, 'index'])
            ->middleware('can:calendar.view')
            ->name('calendar.index');
        Route::get('/calendar/events', [CalendarController::class, 'events'])
            ->middleware('can:calendar.view')
            ->name('calendar.events');
        Route::post('/calendar/events', [CalendarController::class, 'store'])
            ->middleware('can:calendar.create')
            ->name('calendar.events.store');
        Route::get('/calendar/events/{event:public_uuid}', [CalendarController::class, 'show'])
            ->middleware('can:calendar.view')
            ->name('calendar.events.show');
        Route::put('/calendar/events/{event:public_uuid}', [CalendarController::class, 'update'])
            ->middleware('can:calendar.edit')
            ->name('calendar.events.update');
        Route::patch('/calendar/events/{event:public_uuid}/move', [CalendarController::class, 'move'])
            ->middleware('can:calendar.edit')
            ->name('calendar.events.move');
        Route::patch('/calendar/events/{event:public_uuid}/status', [CalendarController::class, 'status'])
            ->middleware('can:calendar.complete')
            ->name('calendar.events.status');
        Route::delete('/calendar/events/{event:public_uuid}', [CalendarController::class, 'destroy'])
            ->middleware('can:calendar.delete')
            ->name('calendar.events.destroy');

        Route::prefix('currencies')
            ->name('currencies.')
            ->controller(CurrencyController::class)
            ->group(function (): void {
                Route::get('/', 'index')->middleware('can:currencies.view')->name('index');
                Route::get('/data', 'data')->middleware('can:currencies.view')->name('data');
                Route::get('/create', 'create')->middleware('can:currencies.create')->name('create');
                Route::post('/', 'store')->name('store');
                Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:currencies.delete')->name('bulk-delete');
                Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:currencies.document_number_settings.update')->name('document-number-settings.update');
                Route::patch('/{currency}/restore', 'restore')->middleware('can:currencies.restore')->name('restore');
                Route::get('/{currency}/clone', 'clone')->middleware('can:currencies.clone')->name('clone');
                Route::get('/{currency}', 'show')->withTrashed()->middleware('can:currencies.view')->name('show');
                Route::get('/{currency}/edit', 'edit')->middleware('can:currencies.edit')->name('edit');
                Route::put('/{currency}', 'update')->middleware('can:currencies.edit')->name('update');
                Route::delete('/{currency}', 'destroy')->middleware('can:currencies.delete')->name('destroy');
            });

        Route::prefix('chat')
            ->name('chat.')
            ->controller(ChatController::class)
            ->group(function (): void {
                Route::get('/', 'index')
                    ->middleware('can:chat.view')
                    ->name('index');
                Route::get('/conversations', 'conversations')
                    ->middleware('can:chat.view')
                    ->name('conversations');
                Route::post('/conversations', 'storeConversation')
                    ->middleware('can:chat.create')
                    ->name('conversations.store');
                Route::get('/conversations/{conversation:public_uuid}', 'show')
                    ->middleware('can:chat.view')
                    ->name('conversations.show');
                Route::get('/conversations/{conversation:public_uuid}/info', 'info')
                    ->middleware('can:chat.view')
                    ->name('conversations.info');
                Route::get('/conversations/{conversation:public_uuid}/messages', 'messages')
                    ->middleware('can:chat.view')
                    ->name('messages.poll');
                Route::post('/conversations/{conversation:public_uuid}/messages', 'storeMessage')
                    ->middleware('can:chat.send')
                    ->name('messages.store');
                Route::post('/conversations/{conversation:public_uuid}/read', 'read')
                    ->middleware('can:chat.view')
                    ->name('messages.read');
                Route::post('/conversations/{conversation:public_uuid}/mute', 'mute')
                    ->middleware('can:chat.view')
                    ->name('conversations.mute');
                Route::post('/messages/{message:public_uuid}/forward', 'forward')
                    ->middleware(['can:chat.send', 'can:chat.create'])
                    ->name('messages.forward');
                Route::get('/attachments/{attachment:public_uuid}', 'attachment')
                    ->middleware('can:chat.view')
                    ->name('attachments.show');
            });

        $itemLookupRoutes = [
            'item-units' => 'item_units',
            'item-sizes' => 'item_sizes',
            'item-colors' => 'item_colors',
            'item-decals' => 'item_decals',
            'item-models' => 'item_models',
            'item-categories' => 'item_categories',
            'item-groups' => 'item_groups',
            'item-origin-countries' => 'item_origin_countries',
        ];

        foreach ($itemLookupRoutes as $uri => $resource) {
            Route::prefix($uri)
                ->name("{$uri}.")
                ->controller(ItemLookupController::class)
                ->group(function () use ($resource): void {
                    Route::get('/', 'index')
                        ->defaults('itemLookup', $resource)
                        ->middleware("can:{$resource}.view")
                        ->name('index');
                    Route::get('/data', 'data')
                        ->defaults('itemLookup', $resource)
                        ->middleware("can:{$resource}.view")
                        ->name('data');
                    Route::get('/create', 'create')
                        ->defaults('itemLookup', $resource)
                        ->middleware("can:{$resource}.create")
                        ->name('create');
                    Route::post('/', 'store')
                        ->defaults('itemLookup', $resource)
                        ->name('store');
                    Route::delete('/bulk-delete', 'bulkDelete')
                        ->defaults('itemLookup', $resource)
                        ->middleware("can:{$resource}.delete")
                        ->name('bulk-delete');
                    Route::put('/document-number-settings', 'updateDocumentNumberSettings')
                        ->defaults('itemLookup', $resource)
                        ->middleware("can:{$resource}.document_number_settings.update")
                        ->name('document-number-settings.update');
                    Route::patch('/{record}/restore', 'restore')
                        ->defaults('itemLookup', $resource)
                        ->middleware("can:{$resource}.restore")
                        ->name('restore');
                    Route::get('/{record}/clone', 'clone')
                        ->defaults('itemLookup', $resource)
                        ->middleware("can:{$resource}.clone")
                        ->name('clone');
                    Route::get('/{record}', 'show')
                        ->defaults('itemLookup', $resource)
                        ->middleware("can:{$resource}.view")
                        ->name('show');
                    Route::get('/{record}/edit', 'edit')
                        ->defaults('itemLookup', $resource)
                        ->middleware("can:{$resource}.edit")
                        ->name('edit');
                    Route::put('/{record}', 'update')
                        ->defaults('itemLookup', $resource)
                        ->middleware("can:{$resource}.edit")
                        ->name('update');
                    Route::delete('/{record}', 'destroy')
                        ->defaults('itemLookup', $resource)
                        ->middleware("can:{$resource}.delete")
                        ->name('destroy');
                });
        }

        Route::prefix('raw-materials')
            ->name('raw-materials.')
            ->controller(ProductController::class)
            ->group(function (): void {
                Route::get('/', 'index')
                    ->middleware('can:raw_materials.view')
                    ->name('index');
                Route::get('/data', 'data')
                    ->middleware('can:raw_materials.view')
                    ->name('data');
                Route::get('/create', 'create')
                    ->middleware('can:raw_materials.create')
                    ->name('create');
                Route::post('/', 'store')
                    ->name('store');
                Route::delete('/bulk-delete', 'bulkDelete')
                    ->middleware('can:raw_materials.delete')
                    ->name('bulk-delete');
                Route::put('/document-number-settings', 'updateDocumentNumberSettings')
                    ->middleware('can:raw_materials.document_number_settings.update')
                    ->name('document-number-settings.update');
                Route::patch('/{product}/restore', 'restore')
                    ->middleware('can:raw_materials.restore')
                    ->name('restore');
                Route::get('/{product}/clone', 'clone')
                    ->middleware('can:raw_materials.clone')
                    ->name('clone');
                Route::get('/{product}', 'show')
                    ->withTrashed()
                    ->middleware('can:raw_materials.view')
                    ->name('show');
                Route::get('/{product}/edit', 'edit')
                    ->middleware('can:raw_materials.edit')
                    ->name('edit');
                Route::put('/{product}', 'update')
                    ->middleware('can:raw_materials.edit')
                    ->name('update');
                Route::delete('/{product}', 'destroy')
                    ->middleware('can:raw_materials.delete')
                    ->name('destroy');
            });

        Route::prefix('packaging-materials')
            ->name('packaging-materials.')
            ->controller(ProductController::class)
            ->group(function (): void {
                Route::get('/', 'index')
                    ->middleware('can:packaging_materials.view')
                    ->name('index');
                Route::get('/data', 'data')
                    ->middleware('can:packaging_materials.view')
                    ->name('data');
                Route::get('/create', 'create')
                    ->middleware('can:packaging_materials.create')
                    ->name('create');
                Route::post('/', 'store')
                    ->name('store');
                Route::delete('/bulk-delete', 'bulkDelete')
                    ->middleware('can:packaging_materials.delete')
                    ->name('bulk-delete');
                Route::put('/document-number-settings', 'updateDocumentNumberSettings')
                    ->middleware('can:packaging_materials.document_number_settings.update')
                    ->name('document-number-settings.update');
                Route::patch('/{product}/restore', 'restore')
                    ->middleware('can:packaging_materials.restore')
                    ->name('restore');
                Route::get('/{product}/clone', 'clone')
                    ->middleware('can:packaging_materials.clone')
                    ->name('clone');
                Route::get('/{product}', 'show')
                    ->withTrashed()
                    ->middleware('can:packaging_materials.view')
                    ->name('show');
                Route::get('/{product}/edit', 'edit')
                    ->middleware('can:packaging_materials.edit')
                    ->name('edit');
                Route::put('/{product}', 'update')
                    ->middleware('can:packaging_materials.edit')
                    ->name('update');
                Route::delete('/{product}', 'destroy')
                    ->middleware('can:packaging_materials.delete')
                    ->name('destroy');
            });

        Route::prefix('products/import')
            ->name('products.import.')
            ->controller(ExcelImportController::class)
            ->group(function (): void {
                Route::get('/', 'index')->defaults('excelImportModule', 'products')->middleware('can:products.import')->name('index');
                Route::get('/template', 'template')->defaults('excelImportModule', 'products')->middleware('can:products.import')->name('template');
                Route::post('/upload', 'store')->defaults('excelImportModule', 'products')->middleware('can:products.import')->name('store');
                Route::get('/{batch:public_uuid}', 'show')->defaults('excelImportModule', 'products')->middleware('can:products.import')->name('show');
                Route::get('/{batch:public_uuid}/errors', 'errorWorkbook')->defaults('excelImportModule', 'products')->middleware('can:products.import')->name('errors');
                Route::post('/{batch:public_uuid}/replace', 'replace')->defaults('excelImportModule', 'products')->middleware('can:products.import')->name('replace');
                Route::post('/{batch:public_uuid}/confirm', 'confirm')->defaults('excelImportModule', 'products')->middleware('can:products.import')->name('confirm');
                Route::post('/{batch:public_uuid}/cancel', 'cancel')->defaults('excelImportModule', 'products')->middleware('can:products.import')->name('cancel');
            });

        Route::prefix('products')
            ->name('products.')
            ->controller(ProductController::class)
            ->group(function (): void {
                Route::get('/', 'index')
                    ->middleware('can:products.view')
                    ->name('index');
                Route::get('/data', 'data')
                    ->middleware('can:products.view')
                    ->name('data');
                Route::get('/create', 'create')
                    ->middleware('can:products.create')
                    ->name('create');
                Route::post('/', 'store')
                    ->name('store');
                Route::delete('/bulk-delete', 'bulkDelete')
                    ->middleware('can:products.delete')
                    ->name('bulk-delete');
                Route::put('/document-number-settings', 'updateDocumentNumberSettings')
                    ->middleware('can:products.document_number_settings.update')
                    ->name('document-number-settings.update');
                Route::post('/lookups/{lookup}', [ProductLookupInlineController::class, 'store'])
                    ->whereIn('lookup', ['item-units', 'item-sizes', 'item-colors', 'item-decals', 'item-models', 'item-categories', 'item-groups', 'item-origin-countries'])
                    ->name('lookups.store');
                Route::get('/{product}/image', 'image')
                    ->name('image');
                Route::get('/{product}/components', [ProductComponentController::class, 'index'])
                    ->middleware('can:products.view')
                    ->name('components.index');
                Route::post('/{product}/components', [ProductComponentController::class, 'store'])
                    ->middleware('can:products.edit')
                    ->name('components.store');
                Route::put('/{product}/components/{component}', [ProductComponentController::class, 'update'])
                    ->middleware('can:products.edit')
                    ->name('components.update');
                Route::delete('/{product}/components/{component}', [ProductComponentController::class, 'destroy'])
                    ->middleware('can:products.edit')
                    ->name('components.destroy');
                Route::patch('/{product}/restore', 'restore')
                    ->middleware('can:products.restore')
                    ->name('restore');
                Route::get('/{product}/clone', 'clone')
                    ->middleware('can:products.clone')
                    ->name('clone');
                Route::get('/{product}', 'show')
                    ->withTrashed()
                    ->middleware('can:products.view')
                    ->name('show');
                Route::get('/{product}/edit', 'edit')
                    ->middleware('can:products.edit')
                    ->name('edit');
                Route::put('/{product}', 'update')
                    ->middleware('can:products.edit')
                    ->name('update');
                Route::delete('/{product}', 'destroy')
                    ->middleware('can:products.delete')
                    ->name('destroy');
            });

        Route::get('/my-board', [MyBoardController::class, 'index'])
            ->middleware('can:my_board.view')
            ->name('my-board.index');
        Route::get('/my-board/data', [MyBoardController::class, 'data'])
            ->middleware('can:my_board.view')
            ->name('my-board.data');
        Route::get('/my-board/all-tasks', [MyBoardController::class, 'allTasks'])
            ->middleware('can:my_board.tasks.view_all')
            ->name('my-board.all-tasks');
        Route::get('/my-board/all-notes', [MyBoardController::class, 'allNotes'])
            ->middleware('can:my_board.notes.view_all')
            ->name('my-board.all-notes');
        Route::post('/my-board', [MyBoardController::class, 'store'])
            ->middleware('can:my_board.create')
            ->name('my-board.store');
        Route::post('/my-board/lists', [MyBoardController::class, 'storeList'])
            ->middleware('can:my_board.lists.create')
            ->name('my-board.lists.store');
        Route::patch('/my-board/lists/reorder', [MyBoardController::class, 'reorderLists'])
            ->middleware('can:my_board.lists.reorder')
            ->name('my-board.lists.reorder');
        Route::put('/my-board/lists/{boardList:doc_num}', [MyBoardController::class, 'updateList'])
            ->middleware('can:my_board.lists.edit')
            ->name('my-board.lists.update');
        Route::delete('/my-board/lists/{boardList:doc_num}', [MyBoardController::class, 'destroyList'])
            ->middleware('can:my_board.lists.delete')
            ->name('my-board.lists.destroy');
        Route::prefix('/my-board/table')
            ->name('my-board.')
            ->group(function (): void {
                Route::redirect('/', '/admin/tools/team-board')
                    ->middleware('can:my_board.view')
                    ->name('table');
                Route::get('/tasks', fn () => redirect()->route('admin.tools.team-board.tasks.data', request()->query()))
                    ->middleware('can:my_board.view')
                    ->name('tasks.datatable');
                Route::get('/tasks/create', fn () => redirect()->route('admin.tools.team-board.tasks.create', request()->query()))
                    ->middleware('can:my_board.create')
                    ->name('tasks.create');
                Route::post('/tasks', fn () => redirect()->route('admin.tools.team-board.tasks.store', [], 307))
                    ->middleware('can:my_board.create')
                    ->name('tasks.store');
                Route::delete('/bulk-delete', fn () => redirect()->route('admin.tools.team-board.bulk-delete', [], 307))
                    ->middleware('can:my_board.delete')
                    ->name('table.bulk-delete');
                Route::patch('/bulk-active-state', fn () => redirect()->route('admin.tools.team-board.bulk-active-state', [], 307))
                    ->middleware('can:my_board.edit')
                    ->name('table.bulk-active-state');
                Route::patch('/bulk-restore', fn () => redirect()->route('admin.tools.team-board.bulk-restore', [], 307))
                    ->middleware('can:my_board.restore')
                    ->name('table.bulk-restore');
                Route::get('/tasks/{userTask:doc_num}', fn (UserTask $userTask) => redirect()->route('admin.tools.team-board.tasks.show', $userTask->doc_num))
                    ->middleware('can:my_board.view')
                    ->withTrashed()
                    ->name('tasks.show');
                Route::get('/tasks/{userTask:doc_num}/edit', fn (UserTask $userTask) => redirect()->route('admin.tools.team-board.tasks.edit', $userTask->doc_num))
                    ->middleware('can:my_board.edit')
                    ->name('tasks.edit');
                Route::post('/tasks/{userTask:doc_num}/clone', fn (UserTask $userTask) => redirect()->route('admin.tools.team-board.tasks.clone', $userTask->doc_num, 307))
                    ->middleware('can:my_board.clone')
                    ->name('tasks.clone');
                Route::patch('/tasks/{userTask}/restore', fn (string $userTask) => redirect()->route('admin.tools.team-board.tasks.restore', ['userTask' => $userTask], 307))
                    ->middleware('can:my_board.restore')
                    ->name('tasks.restore');
                Route::put('/tasks/{userTask:doc_num}', fn (UserTask $userTask) => redirect()->route('admin.tools.team-board.tasks.update', $userTask->doc_num, 307))
                    ->middleware('can:my_board.edit')
                    ->name('tasks.update');
                Route::patch('/tasks/{userTask:doc_num}/status', fn (UserTask $userTask) => redirect()->route('admin.tools.team-board.tasks.status', $userTask->doc_num, 307))
                    ->middleware('can:my_board.reorder')
                    ->name('tasks.status');
                Route::delete('/tasks/{userTask:doc_num}', fn (UserTask $userTask) => redirect()->route('admin.tools.team-board.tasks.destroy', $userTask->doc_num, 307))
                    ->middleware('can:my_board.delete')
                    ->name('tasks.destroy');
                Route::get('/notes', fn () => redirect()->route('admin.tools.team-board.notes.data', request()->query()))
                    ->middleware('can:my_board.view')
                    ->name('notes.datatable');
                Route::get('/notes/create', fn () => redirect()->route('admin.tools.team-board.notes.create', request()->query()))
                    ->middleware('can:my_board.create')
                    ->name('notes.create');
                Route::post('/notes', fn () => redirect()->route('admin.tools.team-board.notes.store', [], 307))
                    ->middleware('can:my_board.create')
                    ->name('notes.store');
                Route::get('/notes/{userTask:doc_num}', fn (UserTask $userTask) => redirect()->route('admin.tools.team-board.notes.show', $userTask->doc_num))
                    ->middleware('can:my_board.view')
                    ->withTrashed()
                    ->name('notes.show');
                Route::get('/notes/{userTask:doc_num}/edit', fn (UserTask $userTask) => redirect()->route('admin.tools.team-board.notes.edit', $userTask->doc_num))
                    ->middleware('can:my_board.edit')
                    ->name('notes.edit');
                Route::post('/notes/{userTask:doc_num}/clone', fn (UserTask $userTask) => redirect()->route('admin.tools.team-board.notes.clone', $userTask->doc_num, 307))
                    ->middleware('can:my_board.clone')
                    ->name('notes.clone');
                Route::patch('/notes/{userTask}/restore', fn (string $userTask) => redirect()->route('admin.tools.team-board.notes.restore', ['userTask' => $userTask], 307))
                    ->middleware('can:my_board.restore')
                    ->name('notes.restore');
                Route::put('/notes/{userTask:doc_num}', fn (UserTask $userTask) => redirect()->route('admin.tools.team-board.notes.update', $userTask->doc_num, 307))
                    ->middleware('can:my_board.edit')
                    ->name('notes.update');
                Route::patch('/notes/{userTask:doc_num}/status', fn (UserTask $userTask) => redirect()->route('admin.tools.team-board.notes.status', $userTask->doc_num, 307))
                    ->middleware('can:my_board.reorder')
                    ->name('notes.status');
                Route::delete('/notes/{userTask:doc_num}', fn (UserTask $userTask) => redirect()->route('admin.tools.team-board.notes.destroy', $userTask->doc_num, 307))
                    ->middleware('can:my_board.delete')
                    ->name('notes.destroy');
            });
        Route::get('/my-board/{userTask:doc_num}', [MyBoardController::class, 'show'])
            ->name('my-board.show');
        Route::post('/my-board/{userTask:doc_num}/comments', [MyBoardController::class, 'storeComment'])
            ->middleware('can:my_board.comments.create')
            ->name('my-board.comments.store');
        Route::delete('/my-board/{userTask:doc_num}/comments/{comment}', [MyBoardController::class, 'destroyComment'])
            ->name('my-board.comments.destroy');
        Route::put('/my-board/{userTask:doc_num}', [MyBoardController::class, 'update'])
            ->middleware('can:my_board.edit')
            ->name('my-board.update');
        Route::patch('/my-board/{userTask:doc_num}/move', [MyBoardController::class, 'move'])
            ->middleware('can:my_board.reorder')
            ->name('my-board.move');
        Route::delete('/my-board/{userTask:doc_num}', [MyBoardController::class, 'destroy'])
            ->middleware('can:my_board.delete')
            ->name('my-board.destroy');

        Route::get('/file-manager/picker/items', [FileManagerController::class, 'pickerItems'])
            ->middleware('can:file_manager.view')
            ->name('file-manager.picker.items');
        Route::post('/file-manager/picker/files', [FileManagerController::class, 'pickerUpload'])
            ->middleware('can:file_manager.upload')
            ->name('file-manager.picker.files.store');
        Route::post('/file-manager/picker/folders', [FileManagerController::class, 'pickerStoreFolder'])
            ->middleware('can:file_manager.folders.create')
            ->name('file-manager.picker.folders.store');
        Route::get('/file-manager/picker/files/{file:doc_num}', [FileManagerController::class, 'pickerFile'])
            ->middleware('can:file_manager.view')
            ->name('file-manager.picker.files.show');

        Route::get('/file-manager', [FileManagerController::class, 'index'])
            ->middleware('can:file_manager.view')
            ->name('file-manager.index');
        Route::get('/file-manager/data', [FileManagerController::class, 'data'])
            ->middleware('can:file_manager.view')
            ->name('file-manager.data');
        Route::get('/file-manager/folder-options', [FileManagerController::class, 'folderOptions'])
            ->middleware('can:file_manager.move')
            ->name('file-manager.folder-options');
        Route::post('/file-manager/move', [FileManagerController::class, 'move'])
            ->middleware('can:file_manager.move')
            ->name('file-manager.move');
        Route::post('/file-manager/bulk-move', [FileManagerController::class, 'bulkMove'])
            ->middleware('can:file_manager.move')
            ->name('file-manager.bulk-move');
        Route::get('/file-manager/folders/{folder:doc_num}', [FileManagerController::class, 'showFolder'])
            ->middleware('can:file_manager.view')
            ->name('file-manager.folder.show');
        Route::get('/file-manager/folders/{folder:doc_num}/public-link', [FileManagerController::class, 'showFolderPublicLink'])
            ->middleware('can:file_manager.public_links.view')
            ->name('file-manager.folders.public-link.show');
        Route::post('/file-manager/folders/{folder:doc_num}/public-link', [FileManagerController::class, 'createFolderPublicLink'])
            ->middleware('can:file_manager.public_links.create')
            ->name('file-manager.folders.public-link.store');
        Route::delete('/file-manager/folders/{folder:doc_num}/public-link', [FileManagerController::class, 'revokeFolderPublicLink'])
            ->middleware('can:file_manager.public_links.revoke')
            ->name('file-manager.folders.public-link.destroy');
        Route::post('/file-manager/folders', [FileManagerController::class, 'storeFolder'])
            ->middleware('can:file_manager.folders.create')
            ->name('file-manager.folders.store');
        Route::put('/file-manager/folders/{folder:doc_num}', [FileManagerController::class, 'updateFolder'])
            ->middleware('can:file_manager.folders.rename')
            ->name('file-manager.folders.update');
        Route::patch('/file-manager/folders/{folder:doc_num}/picker-visibility', [FileManagerController::class, 'updateFolderPickerVisibility'])
            ->middleware('can:file_manager.update_picker_visibility')
            ->name('file-manager.folders.picker-visibility.update');
        Route::patch('/file-manager/folders/{folder}/restore', [FileManagerController::class, 'restoreFolder'])
            ->middleware('can:file_manager.folders.restore')
            ->name('file-manager.folders.restore');
        Route::delete('/file-manager/folders/{folder:doc_num}', [FileManagerController::class, 'destroyFolder'])
            ->middleware('can:file_manager.folders.delete')
            ->name('file-manager.folders.destroy');
        Route::post('/file-manager/files', [FileManagerController::class, 'store'])
            ->middleware('can:file_manager.upload')
            ->name('file-manager.files.store');
        Route::post('/file-manager/folders/{folder:doc_num}/files', [FileManagerController::class, 'storeInFolder'])
            ->middleware('can:file_manager.upload')
            ->name('file-manager.folder.files.store');
        Route::post('/file-manager/bulk-download', [FileManagerController::class, 'bulkDownload'])
            ->middleware('can:file_manager.download')
            ->name('file-manager.bulk-download');
        Route::delete('/file-manager/bulk-delete', [FileManagerController::class, 'bulkDelete'])
            ->middleware('can:file_manager.delete')
            ->name('file-manager.bulk-delete');
        Route::patch('/file-manager/bulk-restore', [FileManagerController::class, 'bulkRestore'])
            ->middleware('can:file_manager.restore')
            ->name('file-manager.bulk-restore');
        Route::put('/file-manager/document-number-settings', [FileManagerController::class, 'updateDocumentNumberSettings'])
            ->middleware('can:file_manager.document_number_settings.update')
            ->name('file-manager.document-number-settings.update');
        Route::get('/file-manager/files/{file:doc_num}/download', [FileManagerController::class, 'download'])
            ->middleware('can:file_manager.download')
            ->name('file-manager.files.download');
        Route::get('/file-manager/files/{file:doc_num}/preview', [FileManagerController::class, 'preview'])
            ->middleware('can:file_manager.view')
            ->name('file-manager.files.preview');
        Route::get('/file-manager/files/{file:doc_num}/public-link', [FileManagerController::class, 'showFilePublicLink'])
            ->middleware('can:file_manager.public_links.view')
            ->name('file-manager.files.public-link.show');
        Route::post('/file-manager/files/{file:doc_num}/public-link', [FileManagerController::class, 'createFilePublicLink'])
            ->middleware('can:file_manager.public_links.create')
            ->name('file-manager.files.public-link.store');
        Route::delete('/file-manager/files/{file:doc_num}/public-link', [FileManagerController::class, 'revokeFilePublicLink'])
            ->middleware('can:file_manager.public_links.revoke')
            ->name('file-manager.files.public-link.destroy');
        Route::patch('/file-manager/files/{file:doc_num}/picker-visibility', [FileManagerController::class, 'updateFilePickerVisibility'])
            ->middleware('can:file_manager.update_picker_visibility')
            ->name('file-manager.files.picker-visibility.update');
        Route::patch('/file-manager/files/{file}/restore', [FileManagerController::class, 'restoreFile'])
            ->middleware('can:file_manager.restore')
            ->name('file-manager.files.restore');
        Route::delete('/file-manager/files/{file:doc_num}', [FileManagerController::class, 'destroy'])
            ->middleware('can:file_manager.delete')
            ->name('file-manager.files.destroy');

        Route::get('/companies', [CompanyController::class, 'index'])
            ->middleware('can:companies.view')
            ->name('companies.index');
        Route::get('/companies/data', [CompanyController::class, 'data'])
            ->middleware('can:companies.view')
            ->name('companies.data');
        Route::get('/companies/create', [CompanyController::class, 'create'])
            ->middleware('can:companies.create')
            ->name('companies.create');
        Route::post('/companies', [CompanyController::class, 'store'])
            ->name('companies.store');
        Route::delete('/companies/bulk-delete', [CompanyController::class, 'bulkDelete'])
            ->middleware('can:companies.delete')
            ->name('companies.bulk-delete');
        Route::put('/companies/document-number-settings', [CompanyController::class, 'updateDocumentNumberSettings'])
            ->middleware('can:companies.document_number_settings.update')
            ->name('companies.document-number-settings.update');
        Route::get('/companies/{company:doc_num}/location-selected', CompanyLocationSelectedController::class)
            ->withoutScopedBindings()
            ->name('companies.location-selected');
        Route::get('/companies/{company:doc_num}/clone', [CompanyController::class, 'clone'])
            ->middleware('can:companies.clone')
            ->name('companies.clone');
        Route::patch('/companies/{company:doc_num}/restore', [CompanyController::class, 'restore'])
            ->middleware('can:companies.restore')
            ->name('companies.restore');
        Route::get('/companies/{company:doc_num}', [CompanyController::class, 'show'])
            ->middleware('can:companies.view')
            ->name('companies.show');
        Route::get('/companies/{company:doc_num}/edit', [CompanyController::class, 'edit'])
            ->middleware('can:companies.edit')
            ->name('companies.edit');
        Route::put('/companies/{company:doc_num}', [CompanyController::class, 'update'])
            ->middleware('can:companies.edit')
            ->name('companies.update');
        Route::delete('/companies/{company:doc_num}', [CompanyController::class, 'destroy'])
            ->middleware('can:companies.delete')
            ->name('companies.destroy');
        Route::get('/branches', [BranchController::class, 'index'])
            ->middleware('can:branches.view')
            ->name('branches.index');
        Route::get('/branches/data', [BranchController::class, 'data'])
            ->middleware('can:branches.view')
            ->name('branches.data');
        Route::get('/branches/create', [BranchController::class, 'create'])
            ->middleware('can:branches.create')
            ->name('branches.create');
        Route::post('/branches', [BranchController::class, 'store'])
            ->name('branches.store');
        Route::delete('/branches/bulk-delete', [BranchController::class, 'bulkDelete'])
            ->middleware('can:branches.delete')
            ->name('branches.bulk-delete');
        Route::put('/branches/document-number-settings', [BranchController::class, 'updateDocumentNumberSettings'])
            ->middleware('can:branches.document_number_settings.update')
            ->name('branches.document-number-settings.update');
        Route::patch('/branches/{branch:doc_num}/restore', [BranchController::class, 'restore'])
            ->middleware('can:branches.restore')
            ->name('branches.restore');
        Route::get('/branches/{branch:doc_num}/clone', [BranchController::class, 'clone'])
            ->middleware('can:branches.clone')
            ->name('branches.clone');
        Route::get('/branches/{branch:doc_num}', [BranchController::class, 'show'])
            ->withTrashed()
            ->middleware('can:branches.view')
            ->name('branches.show');
        Route::get('/branches/{branch:doc_num}/edit', [BranchController::class, 'edit'])
            ->middleware('can:branches.edit')
            ->name('branches.edit');
        Route::put('/branches/{branch:doc_num}', [BranchController::class, 'update'])
            ->middleware('can:branches.edit')
            ->name('branches.update');
        Route::delete('/branches/{branch:doc_num}', [BranchController::class, 'destroy'])
            ->middleware('can:branches.delete')
            ->name('branches.destroy');

        Route::get('/financial-periods', [FinancialPeriodController::class, 'index'])
            ->middleware('can:financial_periods.view')
            ->name('financial-periods.index');
        Route::get('/financial-periods/data', [FinancialPeriodController::class, 'data'])
            ->middleware('can:financial_periods.view')
            ->name('financial-periods.data');
        Route::get('/financial-periods/create', [FinancialPeriodController::class, 'create'])
            ->middleware('can:financial_periods.create')
            ->name('financial-periods.create');
        Route::post('/financial-periods', [FinancialPeriodController::class, 'store'])
            ->name('financial-periods.store');
        Route::delete('/financial-periods/bulk-delete', [FinancialPeriodController::class, 'bulkDelete'])
            ->middleware('can:financial_periods.delete')
            ->name('financial-periods.bulk-delete');
        Route::put('/financial-periods/document-number-settings', [FinancialPeriodController::class, 'updateDocumentNumberSettings'])
            ->middleware('can:financial_periods.document_number_settings.update')
            ->name('financial-periods.document-number-settings.update');
        Route::patch('/financial-periods/{financialPeriod:doc_num}/restore', [FinancialPeriodController::class, 'restore'])
            ->middleware('can:financial_periods.restore')
            ->name('financial-periods.restore');
        Route::get('/financial-periods/{financialPeriod:doc_num}/clone', [FinancialPeriodController::class, 'clone'])
            ->middleware('can:financial_periods.clone')
            ->name('financial-periods.clone');
        Route::get('/financial-periods/{financialPeriod:doc_num}', [FinancialPeriodController::class, 'show'])
            ->withTrashed()
            ->middleware('can:financial_periods.view')
            ->name('financial-periods.show');
        Route::get('/financial-periods/{financialPeriod:doc_num}/edit', [FinancialPeriodController::class, 'edit'])
            ->middleware('can:financial_periods.edit')
            ->name('financial-periods.edit');
        Route::put('/financial-periods/{financialPeriod:doc_num}', [FinancialPeriodController::class, 'update'])
            ->middleware('can:financial_periods.edit')
            ->name('financial-periods.update');
        Route::delete('/financial-periods/{financialPeriod:doc_num}', [FinancialPeriodController::class, 'destroy'])
            ->middleware('can:financial_periods.delete')
            ->name('financial-periods.destroy');

    });
