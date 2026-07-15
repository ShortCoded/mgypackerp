<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\QuickTask;
use Modules\Core\Models\QuickTaskAttachment;
use Modules\Core\Models\TaskBoard;
use Modules\Core\Services\ArchiveFileService;
use Modules\Core\Services\ArchiveFolderService;
use Modules\Core\Services\OperatingContextService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function quickTasksActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function quickTasksAdminActor(array $permissions): User
{
    $user = quickTasksActor($permissions);

    /** @var Role $role */
    $role = Role::unguarded(fn (): Role => Role::query()->updateOrCreate([
        'id' => 1,
    ], [
        'name' => 'admin',
        'guard_name' => 'web',
        'doc_number' => 1,
        'doc_num' => 'Role-00001',
    ]));

    $user->assignRole($role);

    return $user->refresh();
}

/**
 * @return array{company: Company, branch: Branch, period: FinancialPeriod, session: array<string, mixed>}
 */
function quickTasksContext(object $test, ?Company $company = null): array
{
    static $number = 15000;

    $number++;
    $company ??= Company::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Company-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'name' => 'Quick Tasks Company '.$number,
        'status' => 'active',
        'is_main' => ! Company::query()->where('is_main', true)->exists(),
    ]);

    $branch = Branch::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Branch-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Quick Tasks Branch '.$number,
        'type' => Branch::TypeAdministrative,
        'status' => 'active',
    ]);

    $period = FinancialPeriod::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Period-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Quick Tasks Period '.$number,
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
    ]);

    $session = [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ];

    $test->withSession($session);

    return compact('company', 'branch', 'period', 'session');
}

function quickTasksRecord(Company $company, Branch $branch, User $actor, array $overrides = []): QuickTask
{
    static $number = 17000;

    $number++;

    return QuickTask::query()->create([
        'company_id' => $company->getKey(),
        'branch_id' => $branch->getKey(),
        'doc_number' => $number,
        'doc_num' => 'QT-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'title' => 'Quick task '.$number,
        'summary' => 'Operational note '.$number,
        'details' => 'Operational details '.$number,
        'status' => QuickTask::StatusNew,
        'priority' => QuickTask::PriorityNormal,
        'created_by' => $actor->getKey(),
        ...$overrides,
    ]);
}

function quickTasksBoard(Company $company, Branch $branch, User $actor, array $overrides = []): TaskBoard
{
    static $number = 18000;

    $number++;

    return TaskBoard::query()->create([
        'company_id' => $company->getKey(),
        'branch_id' => $branch->getKey(),
        'doc_number' => $number,
        'doc_num' => 'TB-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'name' => 'Task Board '.$number,
        'description' => 'Display board '.$number,
        'is_active' => true,
        'is_public' => false,
        'requires_password' => false,
        'display_theme' => TaskBoard::DisplayThemeLight,
        'public_token' => Str::random(64),
        'created_by' => $actor->getKey(),
        ...$overrides,
    ]);
}

function quickTasksArchiveFileForCompany(Company $company, UploadedFile $file): ArchiveFile
{
    $root = app(ArchiveFolderService::class)->generalRoot();

    $files = app(ArchiveFileService::class)->upload(
        files: [$file],
        attachable: $company,
        module: 'core',
        recordType: 'company',
        recordDocNum: $company->doc_num,
        folder: $root,
    );

    return $files[0];
}

function quickTasksFindMenuNode(array $nodes, string $label): ?array
{
    foreach ($nodes as $node) {
        if (($node['label'] ?? null) === $label) {
            return $node;
        }

        $match = quickTasksFindMenuNode($node['children'] ?? [], $label);

        if ($match !== null) {
            return $match;
        }
    }

    return null;
}

test('quick tasks permissions and tools menu entries are registered from menu config', function (): void {
    $permissions = app(PermissionRegistryService::class)->all();
    $toolsMenu = require base_path('config/menu/tools.php');
    $quickTasksNode = quickTasksFindMenuNode($toolsMenu, 'quick_tasks');
    $managementNode = quickTasksFindMenuNode($toolsMenu, 'quick_tasks_management');
    $taskBoardsNode = quickTasksFindMenuNode($toolsMenu, 'task_boards');

    expect($permissions)
        ->toContain('quick_tasks.view')
        ->toContain('quick_tasks.create')
        ->toContain('quick_tasks.update')
        ->toContain('quick_tasks.delete')
        ->toContain('quick_tasks.restore')
        ->toContain('quick_tasks.change_status')
        ->toContain('quick_tasks.start')
        ->toContain('quick_tasks.mark_ready')
        ->toContain('quick_tasks.mark_done')
        ->toContain('quick_tasks.manage_attachments')
        ->toContain('task_boards.view')
        ->toContain('task_boards.create')
        ->toContain('task_boards.update')
        ->toContain('task_boards.delete')
        ->toContain('task_boards.view_trashed')
        ->toContain('task_boards.restore')
        ->toContain('task_boards.bulk_activate')
        ->toContain('task_boards.bulk_deactivate')
        ->toContain('task_boards.display')
        ->toContain('task_boards.public_settings')
        ->toContain('task_boards.regenerate_public_url')
        ->and($taskBoardsNode)->not->toBeNull()
        ->and($taskBoardsNode['route'])->toBe('admin.task-boards.index')
        ->and($taskBoardsNode['permission'])->toBe('task_boards.view')
        ->and($quickTasksNode)->not->toBeNull()
        ->and($managementNode)->not->toBeNull()
        ->and($managementNode['route'])->toBe('admin.quick-tasks.index')
        ->and($managementNode['permission'])->toBe('quick_tasks.view');
});

test('management page renders for permitted users', function (): void {
    $actor = quickTasksActor(['quick_tasks.view', 'quick_tasks.create', 'quick_tasks.delete', 'quick_tasks.restore']);
    $context = quickTasksContext($this);

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->get(route('admin.quick-tasks.index'))
        ->assertOk()
        ->assertSee(__('quick_tasks.management_title'))
        ->assertSee('quick-tasks-table', false);
});

test('tasks can be created with file manager attachments in the active operating context', function (): void {
    Storage::fake('local');

    $actor = quickTasksActor([
        'quick_tasks.view',
        'quick_tasks.create',
        'quick_tasks.update',
        'quick_tasks.manage_attachments',
        'file_manager.view',
    ]);
    $assignee = User::factory()->create();
    $context = quickTasksContext($this);
    $archiveFile = quickTasksArchiveFileForCompany(
        $context['company'],
        UploadedFile::fake()->create('cutting-note.pdf', 64, 'application/pdf'),
    );

    $response = $this->actingAs($actor)
        ->withSession($context['session'])
        ->post(route('admin.quick-tasks.store'), [
            'title' => 'Prepare showroom sample',
            'summary' => 'Move sample to display area',
            'details' => 'Attach the latest cutting note before moving.',
            'status' => QuickTask::StatusNew,
            'priority' => QuickTask::PriorityHigh,
            'assigned_to_doc_num' => $assignee->doc_num,
            'attachment_file_doc_nums' => [$archiveFile->doc_num],
        ], ['Accept' => 'application/json']);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonMissingPath('data.id')
        ->assertJsonMissingPath('data.company_id');

    $task = QuickTask::query()->with('attachments')->firstOrFail();
    $attachment = $task->attachments->first();

    expect($task->company_id)->toBe($context['company']->getKey())
        ->and($task->branch_id)->toBe($context['branch']->getKey())
        ->and($task->created_by)->toBe($actor->getKey())
        ->and($task->assigned_to)->toBe($assignee->getKey())
        ->and($task->doc_num)->toStartWith('QT-')
        ->and($attachment)->not->toBeNull()
        ->and($attachment->archive_file_id)->toBe($archiveFile->getKey())
        ->and($attachment->original_name)->toBe('cutting-note.pdf')
        ->and($attachment->public_uuid)->not->toBeEmpty();

    Storage::disk('local')->assertExists($attachment->path);

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->get(route('admin.quick-tasks.attachments.show', $attachment->public_uuid))
        ->assertOk();

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->deleteJson(route('admin.quick-tasks.attachments.destroy', $attachment->public_uuid))
        ->assertOk()
        ->assertJsonPath('success', true);

    Storage::disk('local')->assertExists($archiveFile->path);
    expect(QuickTaskAttachment::query()->whereKey($attachment->getKey())->exists())->toBeFalse();
});

test('datatable scopes active tasks to the current company and branch', function (): void {
    $actor = quickTasksActor(['quick_tasks.view', 'quick_tasks.change_status']);
    $context = quickTasksContext($this);
    $otherContext = quickTasksContext($this);

    $new = quickTasksRecord($context['company'], $context['branch'], $actor, ['status' => QuickTask::StatusNew]);
    $inProgress = quickTasksRecord($context['company'], $context['branch'], $actor, ['status' => QuickTask::StatusInProgress]);
    $ready = quickTasksRecord($context['company'], $context['branch'], $actor, ['status' => QuickTask::StatusReady]);
    $done = quickTasksRecord($context['company'], $context['branch'], $actor, ['status' => QuickTask::StatusDone]);
    $cancelled = quickTasksRecord($context['company'], $context['branch'], $actor, ['status' => QuickTask::StatusCancelled]);
    $deleted = quickTasksRecord($context['company'], $context['branch'], $actor, ['status' => QuickTask::StatusNew]);
    $deleted->delete();
    $other = quickTasksRecord($otherContext['company'], $otherContext['branch'], $actor, ['status' => QuickTask::StatusNew]);

    $datatable = $this->actingAs($actor)
        ->withSession($context['session'])
        ->getJson(route('admin.quick-tasks.data', ['draw' => 1, 'start' => 0, 'length' => 10]));

    $datatable->assertOk();

    $rows = $datatable->json('data');
    $encodedRows = json_encode($rows, JSON_THROW_ON_ERROR);

    expect($rows[0])
        ->toHaveKeys([
            'checkbox',
            'doc_num',
            'title',
            'summary',
            'task_board',
            'status',
            'priority',
            'assigned_to',
            'attachments_count',
            'created_by',
            'created_at',
            'updated_by',
            'updated_at',
            'actions',
        ])
        ->not->toHaveKeys(['id', 'company_id', 'branch_id', 'task_board_id', 'assigned_to_id', 'created_by_id', 'updated_by_id', 'deleted_by_id'])
        ->and($encodedRows)->toContain($new->doc_num)
        ->and($encodedRows)->not->toContain($other->doc_num);
});

test('status changes move tasks through the board and done tasks disappear', function (): void {
    $actor = quickTasksActor([
        'quick_tasks.view',
        'quick_tasks.change_status',
        'quick_tasks.start',
        'quick_tasks.mark_ready',
        'quick_tasks.mark_done',
    ]);
    $context = quickTasksContext($this);
    $task = quickTasksRecord($context['company'], $context['branch'], $actor, ['status' => QuickTask::StatusNew]);

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->patchJson(route('admin.quick-tasks.change-status', $task->doc_num), ['status' => QuickTask::StatusInProgress])
        ->assertOk()
        ->assertJsonPath('data.status', QuickTask::StatusInProgress);

    expect($task->refresh()->status)->toBe(QuickTask::StatusInProgress);

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->patchJson(route('admin.quick-tasks.change-status', $task->doc_num), ['status' => QuickTask::StatusReady])
        ->assertOk()
        ->assertJsonPath('data.status', QuickTask::StatusReady);

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->patchJson(route('admin.quick-tasks.change-status', $task->doc_num), ['status' => QuickTask::StatusDone])
        ->assertOk()
        ->assertJsonPath('data.status', QuickTask::StatusDone);
});

test('specific status transition permissions are enforced', function (): void {
    $actor = quickTasksActor(['quick_tasks.view', 'quick_tasks.change_status']);
    $context = quickTasksContext($this);
    $task = quickTasksRecord($context['company'], $context['branch'], $actor, ['status' => QuickTask::StatusReady]);

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->patchJson(route('admin.quick-tasks.change-status', $task->doc_num), ['status' => QuickTask::StatusDone])
        ->assertForbidden();

    expect($task->refresh()->status)->toBe(QuickTask::StatusReady);

    Permission::findOrCreate('quick_tasks.mark_done', 'web');
    $actor->givePermissionTo('quick_tasks.mark_done');

    $this->actingAs($actor->refresh())
        ->withSession($context['session'])
        ->patchJson(route('admin.quick-tasks.change-status', $task->doc_num), ['status' => QuickTask::StatusDone])
        ->assertOk()
        ->assertJsonPath('data.status', QuickTask::StatusDone);
});

test('attachments can be deleted and soft-deleted tasks can be restored', function (): void {
    Storage::fake('local');

    $actor = quickTasksActor(['quick_tasks.view', 'quick_tasks.update', 'quick_tasks.delete', 'quick_tasks.restore', 'quick_tasks.manage_attachments']);
    $context = quickTasksContext($this);
    $task = quickTasksRecord($context['company'], $context['branch'], $actor);
    $path = 'quick-tasks/'.$context['company']->getKey().'/'.$task->doc_num.'/note.txt';

    Storage::disk('local')->put($path, 'Attachment body');

    $attachment = QuickTaskAttachment::query()->create([
        'quick_task_id' => $task->getKey(),
        'disk' => 'local',
        'path' => $path,
        'original_name' => 'note.txt',
        'mime_type' => 'text/plain',
        'size' => 15,
        'uploaded_by' => $actor->getKey(),
    ]);

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->deleteJson(route('admin.quick-tasks.attachments.destroy', $attachment->public_uuid))
        ->assertOk()
        ->assertJsonPath('success', true);

    Storage::disk('local')->assertMissing($path);
    expect(QuickTaskAttachment::query()->whereKey($attachment->getKey())->exists())->toBeFalse();

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->deleteJson(route('admin.quick-tasks.destroy', $task->doc_num))
        ->assertOk();

    expect($task->refresh()->trashed())->toBeTrue();

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->patchJson(route('admin.quick-tasks.restore', $task->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($task->refresh()->trashed())->toBeFalse()
        ->and($task->restored_by)->toBe($actor->getKey())
        ->and($task->restored_at)->not->toBeNull();

    $bulkTask = quickTasksRecord($context['company'], $context['branch'], $actor);

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->deleteJson(route('admin.quick-tasks.destroy', $bulkTask->doc_num))
        ->assertOk();

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->patchJson(route('admin.quick-tasks.bulk-restore'), [
            'doc_nums' => [$bulkTask->doc_num],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($bulkTask->refresh()->trashed())->toBeFalse()
        ->and($bulkTask->restored_by)->toBe($actor->getKey())
        ->and($bulkTask->restored_at)->not->toBeNull();
});

test('quick task form renders ERP sections summernote ajax board select and document picker', function (): void {
    $actor = quickTasksActor([
        'quick_tasks.view',
        'quick_tasks.create',
        'quick_tasks.update',
        'quick_tasks.manage_attachments',
        'file_manager.view',
        'file_manager.upload',
        'file_manager.folders.create',
        'task_boards.view',
    ]);
    $context = quickTasksContext($this);
    $board = quickTasksBoard($context['company'], $context['branch'], $actor);
    $board->users()->attach($actor->getKey());
    $task = quickTasksRecord($context['company'], $context['branch'], $actor, [
        'task_board_id' => $board->getKey(),
        'details' => '<p>Prepare <strong>rich</strong> task notes.</p>',
    ]);

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->get(route('admin.quick-tasks.create'))
        ->assertOk()
        ->assertSee(__('quick_tasks.sections.details'))
        ->assertSee(__('quick_tasks.sections.attachments'))
        ->assertSee('js-quick-task-rich-editor', false)
        ->assertSee('vendors/summernote/summernote-bs5.min.js', false)
        ->assertSee(route('admin.select2.task-boards'), false)
        ->assertSee('data-file-picker', false)
        ->assertSee('data-picker-accept="document"', false)
        ->assertSee('id="file-picker-modal"', false);

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->get(route('admin.quick-tasks.edit', $task->doc_num))
        ->assertOk()
        ->assertSee($board->doc_num)
        ->assertSee('Prepare &lt;strong&gt;rich&lt;/strong&gt; task notes.', false)
        ->assertSee(__('quick_tasks.sections.audit_info'));

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->get(route('admin.quick-tasks.show', $task->doc_num))
        ->assertOk()
        ->assertSee(__('quick_tasks.view'))
        ->assertSee('<strong>rich</strong>', false)
        ->assertDontSee('js-quick-task-rich-editor', false);
});

test('quick task board select2 returns active assigned boards only', function (): void {
    $actor = quickTasksActor(['quick_tasks.create', 'task_boards.view']);
    $context = quickTasksContext($this);
    $active = quickTasksBoard($context['company'], $context['branch'], $actor, ['name' => 'Assigned Active Board']);
    $inactive = quickTasksBoard($context['company'], $context['branch'], $actor, [
        'name' => 'Assigned Inactive Board',
        'is_active' => false,
    ]);
    $hidden = quickTasksBoard($context['company'], $context['branch'], $actor, ['name' => 'Hidden Active Board']);

    $active->users()->attach($actor->getKey());
    $inactive->users()->attach($actor->getKey());

    $response = $this->actingAs($actor)
        ->withSession($context['session'])
        ->getJson(route('admin.select2.task-boards', [
            'q' => 'Board',
            'page' => 1,
        ]));

    $response->assertOk();

    $results = json_encode($response->json('results'), JSON_THROW_ON_ERROR);

    expect($results)
        ->toContain($active->doc_num)
        ->not->toContain($inactive->doc_num)
        ->not->toContain($hidden->doc_num);
});

test('public task board display uses unique token urls and returns only safe active board tasks', function (): void {
    Storage::fake('public');

    $actor = quickTasksAdminActor([
        'quick_tasks.view',
        'task_boards.view',
        'task_boards.display',
        'task_boards.public_settings',
        'task_boards.regenerate_public_url',
    ]);
    $context = quickTasksContext($this);
    $logoPath = 'company-logos/display-company.svg';

    Storage::disk('public')->put($logoPath, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"></svg>');
    $context['company']->forceFill([
        'name' => 'Task Boards Display Company',
        'logo' => $logoPath,
    ])->save();

    $board = quickTasksBoard($context['company'], $context['branch'], $actor, [
        'is_public' => true,
        'display_theme' => TaskBoard::DisplayThemeDark,
    ]);
    $otherBoard = quickTasksBoard($context['company'], $context['branch'], $actor, ['is_public' => true]);
    $new = quickTasksRecord($context['company'], $context['branch'], $actor, [
        'task_board_id' => $board->getKey(),
        'status' => QuickTask::StatusNew,
    ]);
    $ready = quickTasksRecord($context['company'], $context['branch'], $actor, [
        'task_board_id' => $board->getKey(),
        'status' => QuickTask::StatusReady,
    ]);
    $done = quickTasksRecord($context['company'], $context['branch'], $actor, [
        'task_board_id' => $board->getKey(),
        'status' => QuickTask::StatusDone,
    ]);
    $other = quickTasksRecord($context['company'], $context['branch'], $actor, [
        'task_board_id' => $otherBoard->getKey(),
        'status' => QuickTask::StatusNew,
    ]);

    expect($board->public_token)->not->toBe($otherBoard->public_token)
        ->and(route('public.task-boards.display', $board->public_token))->toContain($board->public_token);

    $this->get(route('public.task-boards.display', $board->public_token))
        ->assertOk()
        ->assertSee($board->name)
        ->assertSee($context['company']->name)
        ->assertSee(Storage::disk('public')->url($logoPath), false)
        ->assertSee('data-default-theme="dark"', false)
        ->assertSee(__('task_boards.public.light_mode'))
        ->assertSee('task-board-display', false);

    $response = $this->getJson(route('public.task-boards.display-data', $board->public_token));

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('board.display_theme', TaskBoard::DisplayThemeDark)
        ->assertJsonMissingPath('board.id');

    $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);

    expect($encoded)
        ->toContain($new->doc_num)
        ->toContain($ready->doc_num)
        ->not->toContain($done->doc_num)
        ->not->toContain($other->doc_num)
        ->not->toContain('company_id')
        ->not->toContain('branch_id')
        ->not->toContain('role_id')
        ->not->toContain('public_password_hash');
});

test('task board forms render ERP sections display settings and audit metadata', function (): void {
    $actor = quickTasksAdminActor([
        'task_boards.view',
        'task_boards.create',
        'task_boards.update',
        'task_boards.delete',
        'task_boards.view_trashed',
        'task_boards.restore',
        'task_boards.display',
        'task_boards.public_settings',
        'task_boards.regenerate_public_url',
    ]);
    $context = quickTasksContext($this);
    $board = quickTasksBoard($context['company'], $context['branch'], $actor, [
        'is_public' => true,
        'display_theme' => TaskBoard::DisplayThemeDark,
    ]);

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->get(route('admin.task-boards.create'))
        ->assertOk()
        ->assertSee(__('task_boards.create'))
        ->assertSee(__('task_boards.sections.basic_data'))
        ->assertSee(__('task_boards.sections.assignment'))
        ->assertSee(__('task_boards.sections.display_settings'))
        ->assertSee('name="display_theme"', false)
        ->assertSee(__('task_boards.display_themes.light'));

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->get(route('admin.task-boards.edit', $board->doc_num))
        ->assertOk()
        ->assertSee(__('task_boards.edit'))
        ->assertSee($board->displayUrl(), false)
        ->assertSee('value="dark"', false)
        ->assertSee('id="user_doc_nums"', false)
        ->assertSee('id="role_doc_nums"', false);

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->get(route('admin.task-boards.show', $board->doc_num))
        ->assertOk()
        ->assertSee(__('task_boards.view'))
        ->assertSee(__('task_boards.display_themes.dark'))
        ->assertSee(__('task_boards.sections.audit_info'));

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->deleteJson(route('admin.task-boards.destroy', $board->doc_num))
        ->assertOk();

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->get(route('admin.task-boards.show', $board->doc_num))
        ->assertOk()
        ->assertSee(__('common.fields.deleted_by'))
        ->assertSee(__('task_boards.actions.restore'))
        ->assertDontSee(__('task_boards.actions.regenerate_url'));
});

test('task board management datatable supports filters bulk actions soft delete and restore', function (): void {
    $actor = quickTasksAdminActor([
        'task_boards.view',
        'task_boards.delete',
        'task_boards.view_trashed',
        'task_boards.restore',
        'task_boards.bulk_activate',
        'task_boards.bulk_deactivate',
        'task_boards.display',
        'task_boards.regenerate_public_url',
    ]);
    $context = quickTasksContext($this);
    $active = quickTasksBoard($context['company'], $context['branch'], $actor, ['is_public' => true]);
    $inactive = quickTasksBoard($context['company'], $context['branch'], $actor, ['is_active' => false]);
    $otherContext = quickTasksContext($this);
    $other = quickTasksBoard($otherContext['company'], $otherContext['branch'], $actor);

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->get(route('admin.task-boards.index'))
        ->assertOk()
        ->assertSee(__('task_boards.records.active'))
        ->assertSee(__('task_boards.records.inactive'))
        ->assertSee(__('task_boards.records.trashed'))
        ->assertSee(__('task_boards.actions.delete_selected'))
        ->assertSee(__('task_boards.actions.activate_selected'))
        ->assertSee(__('task_boards.actions.deactivate_selected'))
        ->assertSee(__('task_boards.actions.restore_selected'))
        ->assertSee('select_all_records', false)
        ->assertSee('bulk_action_select', false);

    $activeResponse = $this->actingAs($actor)
        ->withSession($context['session'])
        ->getJson(route('admin.task-boards.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'record_filter' => 'active',
        ]));

    $activeResponse->assertOk();

    $activeRows = $activeResponse->json('data');
    $activeRowsJson = json_encode($activeRows, JSON_THROW_ON_ERROR);

    expect($activeRows[0])
        ->toHaveKeys(['checkbox', 'doc_num', 'public_status', 'access_code_status', 'assignments', 'tasks_count', 'operational_status', 'created_by', 'updated_by', 'actions'])
        ->not->toHaveKeys(['id', 'company_id', 'branch_id', 'public_token'])
        ->and($activeRowsJson)->toContain($active->doc_num)
        ->and($activeRowsJson)->not->toContain($inactive->doc_num)
        ->and($activeRowsJson)->not->toContain($other->doc_num);

    $inactiveResponse = $this->actingAs($actor)
        ->withSession($context['session'])
        ->getJson(route('admin.task-boards.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'record_filter' => 'inactive',
        ]));

    expect(json_encode($inactiveResponse->json('data'), JSON_THROW_ON_ERROR))
        ->toContain($inactive->doc_num)
        ->not->toContain($active->doc_num);

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->patchJson(route('admin.task-boards.bulk-deactivate'), [
            'doc_nums' => [$active->doc_num],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($active->refresh()->is_active)->toBeFalse()
        ->and($active->updated_by)->toBe($actor->getKey());

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->patchJson(route('admin.task-boards.bulk-activate'), [
            'doc_nums' => [$active->doc_num],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($active->refresh()->is_active)->toBeTrue();

    $oldToken = $active->public_token;

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->deleteJson(route('admin.task-boards.bulk-delete'), [
            'doc_nums' => [$active->doc_num],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $active->refresh();

    expect($active->trashed())->toBeTrue()
        ->and($active->deleted_by)->toBe($actor->getKey());

    $this->getJson(route('public.task-boards.display-data', $oldToken))
        ->assertNotFound();

    $trashedResponse = $this->actingAs($actor)
        ->withSession($context['session'])
        ->getJson(route('admin.task-boards.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'record_filter' => 'trashed',
        ]));

    $trashedRows = $trashedResponse->json('data');
    $trashedRowsJson = json_encode($trashedRows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    expect($trashedRows[0])
        ->toHaveKeys(['deleted_by', 'deleted_at'])
        ->not->toHaveKeys(['id', 'company_id', 'branch_id', 'public_token'])
        ->and($trashedRowsJson)->toContain($active->doc_num)
        ->and($trashedRowsJson)->toContain(__('task_boards.actions.restore'));

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->patchJson(route('admin.task-boards.bulk-restore'), [
            'doc_nums' => [$active->doc_num],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $active->refresh();

    expect($active->trashed())->toBeFalse()
        ->and($active->restored_by)->toBe($actor->getKey())
        ->and($active->restored_at)->not->toBeNull()
        ->and($active->public_token)->toBe($oldToken);
});

test('public task board access code is required, hashed, and stored in session per board', function (): void {
    $actor = quickTasksAdminActor(['task_boards.view', 'task_boards.display', 'task_boards.public_settings']);
    $context = quickTasksContext($this);
    $board = quickTasksBoard($context['company'], $context['branch'], $actor, [
        'is_public' => true,
        'requires_password' => true,
        'public_password_hash' => Hash::make('7319'),
    ]);

    expect($board->public_password_hash)->not->toBe('7319')
        ->and(Hash::check('7319', $board->public_password_hash))->toBeTrue();

    $this->get(route('public.task-boards.display', $board->public_token))
        ->assertOk()
        ->assertSee(__('task_boards.public.requires_access_code'));

    $this->getJson(route('public.task-boards.display-data', $board->public_token))
        ->assertForbidden()
        ->assertJsonPath('message', __('task_boards.public.requires_access_code'));

    $this->post(route('public.task-boards.display.access', $board->public_token), [
        'access_code' => 'wrong',
    ])->assertSessionHasErrors('access_code');

    $this->post(route('public.task-boards.display.access', $board->public_token), [
        'access_code' => '7319',
    ])->assertRedirect(route('public.task-boards.display', $board->public_token));

    $this->getJson(route('public.task-boards.display-data', $board->public_token))
        ->assertOk()
        ->assertJsonPath('success', true);
});

test('task board access code can be cleared without exposing or replacing the saved code', function (): void {
    $actor = quickTasksAdminActor([
        'task_boards.view',
        'task_boards.update',
        'task_boards.display',
        'task_boards.public_settings',
    ]);
    $context = quickTasksContext($this);
    $board = quickTasksBoard($context['company'], $context['branch'], $actor, [
        'is_public' => true,
        'requires_password' => true,
        'public_password_hash' => Hash::make('7319'),
    ]);

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->putJson(route('admin.task-boards.update', $board->doc_num), [
            'name' => $board->name,
            'description' => $board->description,
            'is_active' => true,
            'is_public' => true,
            'requires_password' => true,
            'clear_access_code' => true,
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $board->refresh();

    expect($board->requires_password)->toBeFalse()
        ->and($board->public_password_hash)->toBeNull();

    $this->get(route('public.task-boards.display', $board->public_token))
        ->assertOk()
        ->assertSee('task-board-display', false)
        ->assertDontSee(__('task_boards.public.requires_access_code'));
});

test('updating task board details without public settings permission preserves public display settings', function (): void {
    $actor = quickTasksActor(['task_boards.view', 'task_boards.update']);
    $context = quickTasksContext($this);
    $board = quickTasksBoard($context['company'], $context['branch'], $actor, [
        'is_public' => true,
        'requires_password' => true,
        'public_password_hash' => Hash::make('7319'),
    ]);
    $board->users()->attach($actor->getKey());

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->putJson(route('admin.task-boards.update', $board->doc_num), [
            'name' => 'Updated monitor title',
            'description' => 'Updated description',
            'is_active' => true,
            'user_doc_nums' => [$actor->doc_num],
            'role_doc_nums' => [],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $board->refresh();

    expect($board->name)->toBe('Updated monitor title')
        ->and($board->is_public)->toBeTrue()
        ->and($board->requires_password)->toBeTrue()
        ->and(Hash::check('7319', (string) $board->public_password_hash))->toBeTrue();
});

test('private inactive and deleted task board displays are unavailable publicly', function (): void {
    $actor = quickTasksAdminActor(['task_boards.view', 'task_boards.display', 'task_boards.public_settings']);
    $context = quickTasksContext($this);

    $private = quickTasksBoard($context['company'], $context['branch'], $actor, ['is_public' => false]);
    $inactive = quickTasksBoard($context['company'], $context['branch'], $actor, ['is_public' => true, 'is_active' => false]);
    $deleted = quickTasksBoard($context['company'], $context['branch'], $actor, ['is_public' => true]);
    $deleted->delete();

    foreach ([$private, $inactive, $deleted] as $board) {
        $this->get(route('public.task-boards.display', $board->public_token))
            ->assertOk()
            ->assertSee(__('task_boards.public.unavailable'));

        $this->getJson(route('public.task-boards.display-data', $board->public_token))
            ->assertNotFound()
            ->assertJsonPath('message', __('task_boards.public.unavailable'));
    }
});

test('task board public url regeneration invalidates old display token', function (): void {
    $actor = quickTasksAdminActor([
        'task_boards.view',
        'task_boards.display',
        'task_boards.public_settings',
        'task_boards.regenerate_public_url',
    ]);
    $context = quickTasksContext($this);
    $board = quickTasksBoard($context['company'], $context['branch'], $actor, ['is_public' => true]);
    $oldToken = $board->public_token;

    $response = $this->actingAs($actor)
        ->withSession($context['session'])
        ->patchJson(route('admin.task-boards.regenerate-public-url', $board->doc_num));

    $response
        ->assertOk()
        ->assertJsonPath('success', true);

    $board->refresh();

    expect($board->public_token)->not->toBe($oldToken)
        ->and($response->json('data.display_url'))->toContain($board->public_token);

    $this->getJson(route('public.task-boards.display-data', $oldToken))
        ->assertNotFound();
});

test('authenticated task board assignments protect quick task table and mutations', function (): void {
    $actor = quickTasksActor([
        'quick_tasks.view',
        'quick_tasks.create',
        'quick_tasks.update',
        'quick_tasks.delete',
        'quick_tasks.change_status',
        'task_boards.view',
    ]);
    $context = quickTasksContext($this);
    $visibleBoard = quickTasksBoard($context['company'], $context['branch'], $actor);
    $hiddenBoard = quickTasksBoard($context['company'], $context['branch'], $actor);
    $visibleBoard->users()->attach($actor->getKey());

    $visibleTask = quickTasksRecord($context['company'], $context['branch'], $actor, [
        'task_board_id' => $visibleBoard->getKey(),
    ]);
    $hiddenTask = quickTasksRecord($context['company'], $context['branch'], $actor, [
        'task_board_id' => $hiddenBoard->getKey(),
    ]);

    $datatable = $this->actingAs($actor)
        ->withSession($context['session'])
        ->getJson(route('admin.quick-tasks.data', ['draw' => 1, 'start' => 0, 'length' => 10]));

    $rows = json_encode($datatable->json('data'), JSON_THROW_ON_ERROR);

    expect($rows)
        ->toContain($visibleTask->doc_num)
        ->not->toContain($hiddenTask->doc_num);

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->patchJson(route('admin.quick-tasks.change-status', $hiddenTask->doc_num), ['status' => QuickTask::StatusInProgress])
        ->assertNotFound();

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->postJson(route('admin.quick-tasks.store'), [
            'title' => 'Visible display task',
            'status' => QuickTask::StatusNew,
            'priority' => QuickTask::PriorityNormal,
            'task_board_doc_num' => $visibleBoard->doc_num,
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->postJson(route('admin.quick-tasks.store'), [
            'title' => 'Hidden display task',
            'status' => QuickTask::StatusNew,
            'priority' => QuickTask::PriorityNormal,
            'task_board_doc_num' => $hiddenBoard->doc_num,
        ])
        ->assertUnprocessable();
});
