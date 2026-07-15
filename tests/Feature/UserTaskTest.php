<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Core\Models\BoardList;
use Modules\Core\Models\MyBoardTaskComment;
use Modules\Core\Models\MyBoardTaskView;
use Modules\Core\Models\UserTask;
use Modules\Core\Services\MenuService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function userTaskActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function userTaskAdminActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $role = Role::query()->whereKey(1)->first();

    if (! $role instanceof Role) {
        $role = Role::query()->create([
            'name' => 'admin',
            'guard_name' => 'web',
            'doc_number' => 1,
            'doc_num' => 'Role-00001',
        ]);
    }

    $role->givePermissionTo($permissions);

    $user = User::factory()->create();
    $user->assignRole($role);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($role->getKey())->toBe(1);

    return $user;
}

function userTaskPayload(array $overrides = []): array
{
    return [
        'title' => 'Prepare weekly agenda',
        'description' => 'Review the open actions before the weekly meeting.',
        'type' => UserTask::TypeTask,
        'status' => UserTask::StatusTodo,
        'priority' => UserTask::PriorityNormal,
        'color' => 'primary',
        ...$overrides,
    ];
}

function userTaskDataTableParams(array $overrides = []): array
{
    $columns = collect([
        'checkbox',
        'doc_num',
        'title',
        'description_text',
        'board_list',
        'status',
        'priority',
        'color',
        'assignees',
        'due_at',
        'completed_at',
        'created_by',
        'created_at',
        'updated_by',
        'updated_at',
        'actions',
    ])->map(fn (string $column): array => [
        'data' => $column,
        'name' => $column,
        'searchable' => ! in_array($column, ['checkbox', 'actions'], true),
        'orderable' => ! in_array($column, ['checkbox', 'actions'], true),
        'search' => [
            'value' => '',
            'regex' => false,
        ],
    ])->all();

    return [
        'draw' => 1,
        'start' => 0,
        'length' => 25,
        'search' => [
            'value' => '',
            'regex' => false,
        ],
        'order' => [
            [
                'column' => 1,
                'dir' => 'desc',
            ],
        ],
        'columns' => $columns,
        ...$overrides,
    ];
}

function teamBoardReportDataTableParams(string $type, array $overrides = []): array
{
    $columns = $type === UserTask::TypeNote
        ? [
            'doc_num',
            'title',
            'description_text',
            'board_owner',
            'board_list',
            'status',
            'color',
            'created_by',
            'created_at',
            'updated_by',
            'updated_at',
            'comments_count',
            'views_count',
        ]
        : [
            'doc_num',
            'title',
            'description_text',
            'board_owner',
            'assignees',
            'board_list',
            'status',
            'priority',
            'due_at',
            'completed_at',
            'created_by',
            'created_at',
            'updated_by',
            'updated_at',
            'comments_count',
            'views_count',
        ];

    return [
        'draw' => 1,
        'start' => 0,
        'length' => 25,
        'search' => [
            'value' => '',
            'regex' => false,
        ],
        'order' => [
            [
                'column' => $type === UserTask::TypeNote ? 10 : 13,
                'dir' => 'desc',
            ],
        ],
        'columns' => collect($columns)->map(fn (string $column): array => [
            'data' => $column,
            'name' => $column,
            'searchable' => true,
            'orderable' => true,
            'search' => [
                'value' => '',
                'regex' => false,
            ],
        ])->all(),
        ...$overrides,
    ];
}

test('user task permissions are discovered from menu and assigned to admin role', function () {
    $this->seed(PermissionSeeder::class);

    $adminRole = Role::query()
        ->where('name', 'admin')
        ->where('guard_name', 'web')
        ->firstOrFail();

    expect(Permission::query()->where('name', 'my_board.view')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'my_board.reorder')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'my_board.clone')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'my_board.view_trashed')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'my_board.restore')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'my_board.view_any')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'my_board.lists.create')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'my_board.comments.create')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'my_board.comments.delete')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'my_board.tasks.view_all')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'my_board.notes.view_all')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'tasks.assign')->exists())->toBeFalse()
        ->and(Permission::query()->where('name', 'tasks.document_number_settings.update')->exists())->toBeFalse()
        ->and($adminRole->getKey())->toBe(1)
        ->and($adminRole->hasPermissionTo('my_board.assign'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('my_board.clone'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('my_board.view_trashed'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('my_board.restore'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('my_board.tasks.view_all'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('my_board.notes.view_all'))->toBeTrue();
});

test('MyBoard normal user cannot assign a board task to another user', function () {
    $actor = userTaskActor(['my_board.view', 'my_board.create']);
    $assignee = User::factory()->create();

    $this->actingAs($actor)
        ->postJson(route('admin.my-board.store'), userTaskPayload([
            'assignee_doc_nums' => [$assignee->doc_num],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('assignee_doc_nums');
});

test('MyBoard admin user group can view another user board and assign task to multiple users', function () {
    $actor = userTaskAdminActor(['my_board.view', 'my_board.create', 'my_board.assign', 'my_board.view_any']);
    $boardOwner = User::factory()->create();
    $secondAssignee = User::factory()->create();
    $unrelated = User::factory()->create();

    $response = $this->actingAs($actor)
        ->postJson(route('admin.my-board.store'), userTaskPayload([
            'title' => 'Assigned from board',
            'board_user_doc_num' => $boardOwner->doc_num,
            'assignee_doc_nums' => [$boardOwner->doc_num, $secondAssignee->doc_num],
        ]))
        ->assertCreated()
        ->json();

    $task = UserTask::query()->where('doc_num', $response['data']['item']['doc_num'])->firstOrFail();

    expect($task->assigned_to)->toBe($boardOwner->id)
        ->and($task->created_by)->toBe($actor->id)
        ->and($task->assignees()->pluck('users.id')->sort()->values()->all())->toBe([$boardOwner->id, $secondAssignee->id]);

    $this->actingAs($actor)
        ->getJson(route('admin.my-board.data', ['board_user_doc_num' => $boardOwner->doc_num]))
        ->assertOk()
        ->assertSee($task->doc_num);

    $this->actingAs($actor)
        ->getJson(route('admin.my-board.data', ['board_user_doc_num' => $secondAssignee->doc_num]))
        ->assertOk()
        ->assertSee($task->doc_num);

    $this->actingAs($actor)
        ->getJson(route('admin.my-board.data', ['board_user_doc_num' => $unrelated->doc_num]))
        ->assertOk()
        ->assertDontSee($task->doc_num);
});

test('MyBoard list creation requires permission and blocks deleting lists with items', function () {
    $blocked = userTaskActor(['my_board.view']);

    $this->actingAs($blocked)
        ->postJson(route('admin.my-board.lists.store'), [
            'type' => UserTask::TypeTask,
            'name' => 'Blocked',
            'status' => UserTask::StatusTodo,
            'color' => 'primary',
        ])
        ->assertForbidden();

    $nonAdminWithListPermission = userTaskActor(['my_board.view', 'my_board.lists.create', 'my_board.lists.delete']);

    $this->actingAs($nonAdminWithListPermission)
        ->postJson(route('admin.my-board.lists.store'), [
            'type' => UserTask::TypeTask,
            'name' => 'Blocked for non admin',
            'status' => UserTask::StatusTodo,
            'color' => 'primary',
        ])
        ->assertForbidden();

    $this->actingAs($nonAdminWithListPermission)
        ->get(route('admin.my-board.index'))
        ->assertOk()
        ->assertDontSee('js-board-add-list', false);

    $actor = userTaskAdminActor(['my_board.view', 'my_board.lists.create', 'my_board.lists.delete']);

    $this->actingAs($actor)
        ->get(route('admin.my-board.index'))
        ->assertOk()
        ->assertSee('js-board-add-list', false);

    $response = $this->actingAs($actor)
        ->postJson(route('admin.my-board.lists.store'), [
            'type' => UserTask::TypeTask,
            'name' => 'Review',
            'status' => UserTask::StatusInProgress,
            'color' => 'info',
        ])
        ->assertCreated()
        ->json();

    $list = BoardList::query()->where('doc_num', $response['data']['list']['doc_num'])->firstOrFail();

    UserTask::factory()->create([
        'assigned_to' => $actor->id,
        'created_by' => $actor->id,
        'board_list_id' => $list->id,
        'status' => $list->status,
    ]);

    $this->actingAs($actor)
        ->deleteJson(route('admin.my-board.lists.destroy', $list->doc_num))
        ->assertStatus(409)
        ->assertJsonPath('success', false);
});

test('MyBoard shows own and assigned tasks without exposing internal ids', function () {
    $actor = userTaskActor(['my_board.view']);
    $assigner = User::factory()->create();
    $unrelatedUser = User::factory()->create();

    $own = UserTask::factory()->create([
        'title' => 'Own note',
        'type' => UserTask::TypeNote,
        'created_by' => $actor->id,
        'assigned_to' => $actor->id,
        'assigned_by' => null,
        'status' => UserTask::StatusTodo,
    ]);
    $assigned = UserTask::factory()->create([
        'title' => 'Assigned task',
        'created_by' => $assigner->id,
        'assigned_by' => $assigner->id,
        'assigned_to' => $actor->id,
        'status' => UserTask::StatusInProgress,
    ]);
    $unrelated = UserTask::factory()->create([
        'title' => 'Private unrelated task',
        'created_by' => $unrelatedUser->id,
        'assigned_to' => $unrelatedUser->id,
        'status' => UserTask::StatusTodo,
    ]);

    $response = $this->actingAs($actor)
        ->getJson(route('admin.my-board.data'))
        ->assertOk()
        ->json();

    $json = json_encode($response);

    expect($json)->toContain($own->doc_num)
        ->and($json)->toContain($assigned->doc_num)
        ->and($json)->not->toContain($unrelated->doc_num)
        ->and($json)->not->toContain('"id"')
        ->and($json)->not->toContain('task_id')
        ->and($json)->not->toContain('assigned_to_id');
});

test('MyBoard data loads board lists once for the response', function () {
    $actor = userTaskActor(['my_board.view']);
    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $sql = strtolower((string) $query->sql);

        if (str_contains($sql, 'from "board_lists"') || str_contains($sql, 'from `board_lists`')) {
            $queries[] = $query->sql;
        }
    });

    $this->actingAs($actor)
        ->getJson(route('admin.my-board.data'))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($queries)->toHaveCount(1);
});

test('MyBoard page renders Falcon Kanban shell', function () {
    $actor = userTaskActor(['my_board.view', 'my_board.create']);

    $this->actingAs($actor)
        ->get(route('admin.my-board.index'))
        ->assertOk()
        ->assertSee(__('user_tasks.my_board'))
        ->assertSee('kanban-container', false)
        ->assertSee('kanban-column', false)
        ->assertSee('MyBoardConfig', false)
        ->assertSee('vendors/sortablejs/Sortable.min.js', false)
        ->assertSee('assets/js/modules/Core/my-board.js', false)
        ->assertDontSee('team-board-tab', false)
        ->assertDontSee('data-id=', false);
});

test('MyBoard normal user cannot switch board owners through UI or request parameters', function () {
    $actor = userTaskActor([
        'my_board.view',
        'my_board.create',
        'my_board.edit',
        'my_board.delete',
        'my_board.reorder',
        'my_board.assign',
        'my_board.view_any',
        'my_board.manage_any',
    ]);
    $otherUser = User::factory()->create();

    $own = UserTask::factory()->create([
        'title' => 'Own board item',
        'created_by' => $actor->id,
        'assigned_to' => $actor->id,
        'status' => UserTask::StatusTodo,
    ]);
    $other = UserTask::factory()->create([
        'title' => 'Other board item',
        'created_by' => $otherUser->id,
        'assigned_to' => $otherUser->id,
        'status' => UserTask::StatusTodo,
    ]);

    $this->actingAs($actor)
        ->get(route('admin.my-board.index', ['board_user_doc_num' => $otherUser->doc_num]))
        ->assertOk()
        ->assertDontSee('js-board-user-selector', false);

    $boardResponse = $this->actingAs($actor)
        ->getJson(route('admin.my-board.data', ['board_user_doc_num' => $otherUser->doc_num]))
        ->assertOk()
        ->json();

    $boardJson = json_encode($boardResponse);

    expect($boardJson)->toContain($own->doc_num)
        ->and($boardJson)->not->toContain($other->doc_num);

    $idSpoofResponse = $this->actingAs($actor)
        ->getJson(route('admin.my-board.data', ['user_id' => $otherUser->id]))
        ->assertOk()
        ->json();

    $idSpoofJson = json_encode($idSpoofResponse);

    expect($idSpoofJson)->toContain($own->doc_num)
        ->and($idSpoofJson)->not->toContain($other->doc_num);

    $created = $this->actingAs($actor)
        ->postJson(route('admin.my-board.store'), userTaskPayload([
            'title' => 'Spoofed board target',
            'board_user_doc_num' => $otherUser->doc_num,
        ]))
        ->assertCreated()
        ->json('data.item.doc_num');

    expect(UserTask::query()->where('doc_num', $created)->firstOrFail()->assigned_to)->toBe($actor->id);

    $createdFromOwnerId = $this->actingAs($actor)
        ->postJson(route('admin.my-board.store'), userTaskPayload([
            'title' => 'Spoofed owner target',
            'owner_id' => $otherUser->id,
        ]))
        ->assertCreated()
        ->json('data.item.doc_num');

    expect(UserTask::query()->where('doc_num', $createdFromOwnerId)->firstOrFail()->assigned_to)->toBe($actor->id);

    $this->actingAs($actor)
        ->getJson(route('admin.my-board.show', $other->doc_num))
        ->assertNotFound();

    $this->actingAs($actor)
        ->putJson(route('admin.my-board.update', $other->doc_num), userTaskPayload([
            'title' => 'Blocked update',
        ]))
        ->assertForbidden();

    $this->actingAs($actor)
        ->patchJson(route('admin.my-board.move', $other->doc_num), [
            'status' => UserTask::StatusDone,
        ])
        ->assertForbidden();

    $this->actingAs($actor)
        ->deleteJson(route('admin.my-board.destroy', $other->doc_num))
        ->assertForbidden();
});

test('MyBoard table page renders DataTables screen without changing the Kanban screen', function () {
    $actor = userTaskActor(['my_board.view', 'my_board.create', 'my_board.edit', 'my_board.delete', 'my_board.view_trashed', 'my_board.restore']);

    $this->actingAs($actor)
        ->get(route('admin.my-board.table'))
        ->assertOk()
        ->assertSee(__('user_tasks.my_board_table_title'))
        ->assertSee('erp-datatable-card', false)
        ->assertSee('falcon-data-table', false)
        ->assertSee('erp-datatable-wrapper', false)
        ->assertSee('window.dataTableTranslations', false)
        ->assertSee('my-board-tasks-table', false)
        ->assertSee('my-board-notes-table', false)
        ->assertSee('my-board-table-status-modal', false)
        ->assertSee('btn_add_my_board_item', false)
        ->assertSee('my_board_table_record_filter', false)
        ->assertSee(__('user_tasks.records.active'))
        ->assertSee(__('user_tasks.records.inactive'))
        ->assertSee(__('user_tasks.records.trashed'))
        ->assertSee(__('user_tasks.actions.delete_selected'))
        ->assertSee(__('user_tasks.actions.activate_selected'))
        ->assertSee(__('user_tasks.actions.deactivate_selected'))
        ->assertSee(__('user_tasks.actions.restore_selected'))
        ->assertSee('assets/js/modules/Core/my-board-table.js', false)
        ->assertDontSee('btn_add_task', false)
        ->assertDontSee('btn_add_note', false)
        ->assertDontSee('js-my-board-table-filter-panel', false)
        ->assertDontSee('js-my-board-table-filter', false)
        ->assertDontSee('my_board_table_tasks_status', false)
        ->assertDontSee('my_board_table_tasks_priority', false)
        ->assertDontSee('my_board_table_notes_color', false)
        ->assertDontSee('kanban-container', false)
        ->assertDontSee('vendors/sortablejs/Sortable.min.js', false)
        ->assertDontSee('assets/js/modules/Core/my-board.js', false)
        ->assertDontSee('myBoardTableItemModal', false)
        ->assertDontSee('js-my-board-table-user', false);

    $menu = app(MenuService::class)->getMenu($actor);
    $tools = collect($menu)->firstWhere('label', 'tools');

    expect(collect($tools['children'] ?? [])->pluck('label')->all())->toContain('my_board_table');
});

test('MyBoard table never shows a board user selector', function () {
    $normal = userTaskActor(['my_board.view', 'my_board.view_any', 'my_board.manage_any']);
    $admin = userTaskAdminActor(['my_board.view', 'my_board.view_any']);

    $this->actingAs($normal)
        ->get(route('admin.my-board.table'))
        ->assertOk()
        ->assertDontSee('js-my-board-table-user', false);

    $this->actingAs($admin)
        ->get(route('admin.my-board.table'))
        ->assertOk()
        ->assertDontSee('js-my-board-table-user', false)
        ->assertDontSee(__('user_tasks.attributes.board_owner'));
});

test('MyBoard table datatable uses current-user board scoping and does not expose internal ids', function () {
    $actor = userTaskAdminActor(['my_board.view', 'my_board.view_any', 'my_board.delete']);
    $otherUser = User::factory()->create();

    $matching = UserTask::factory()->create([
        'title' => 'Table visible task',
        'assigned_to' => $actor->id,
        'created_by' => $actor->id,
        'status' => UserTask::StatusTodo,
    ]);
    $unrelated = UserTask::factory()->create([
        'title' => 'Table private task',
        'assigned_to' => $otherUser->id,
        'created_by' => $otherUser->id,
        'status' => UserTask::StatusTodo,
    ]);

    $response = $this->actingAs($actor)
        ->getJson(route('admin.my-board.tasks.datatable', userTaskDataTableParams([
            'board_user_doc_num' => $otherUser->doc_num,
        ])))
        ->assertOk()
        ->json();

    $json = json_encode($response);

    expect($json)->toContain($matching->doc_num)
        ->and($json)->toContain('js-my-board-table-row-checkbox')
        ->and($json)->toContain('btn-reveal')
        ->and($json)->toContain('admin\\/my-board\\/table\\/tasks\\/'.$matching->doc_num)
        ->and($json)->not->toContain($unrelated->doc_num)
        ->and($json)->not->toContain('"id":'.$matching->id)
        ->and($json)->not->toContain('assigned_to_id');
});

test('MyBoard table ignores spoofed board owner filters for normal users', function () {
    $actor = userTaskActor(['my_board.view', 'my_board.view_any']);
    $otherUser = User::factory()->create();

    $own = UserTask::factory()->create([
        'title' => 'Own table item',
        'assigned_to' => $actor->id,
        'created_by' => $actor->id,
    ]);
    $other = UserTask::factory()->create([
        'title' => 'Other table item',
        'assigned_to' => $otherUser->id,
        'created_by' => $otherUser->id,
    ]);

    $this->actingAs($actor)
        ->get(route('admin.my-board.table', ['board_user_doc_num' => $otherUser->doc_num]))
        ->assertOk()
        ->assertDontSee('js-my-board-table-user', false);

    $response = $this->actingAs($actor)
        ->getJson(route('admin.my-board.tasks.datatable', userTaskDataTableParams([
            'board_user_doc_num' => $otherUser->doc_num,
        ])))
        ->assertOk()
        ->json();

    $json = json_encode($response);

    expect($json)->toContain($own->doc_num)
        ->and($json)->not->toContain($other->doc_num);

    $idSpoofResponse = $this->actingAs($actor)
        ->getJson(route('admin.my-board.tasks.datatable', userTaskDataTableParams([
            'user_id' => $otherUser->id,
        ])))
        ->assertOk()
        ->json();

    $idSpoofJson = json_encode($idSpoofResponse);

    expect($idSpoofJson)->toContain($own->doc_num)
        ->and($idSpoofJson)->not->toContain($other->doc_num);
});

test('MyBoard table records selector filters active inactive deleted and all records for tasks and notes', function () {
    $actor = userTaskActor(['my_board.view', 'my_board.view_trashed', 'my_board.restore']);

    $activeTask = UserTask::factory()->create([
        'title' => 'Active table task',
        'type' => UserTask::TypeTask,
        'assigned_to' => $actor->id,
        'created_by' => $actor->id,
        'is_active' => true,
    ]);
    $inactiveTask = UserTask::factory()->create([
        'title' => 'Inactive table task',
        'type' => UserTask::TypeTask,
        'assigned_to' => $actor->id,
        'created_by' => $actor->id,
        'is_active' => false,
    ]);
    $deletedTask = UserTask::factory()->create([
        'title' => 'Deleted table task',
        'type' => UserTask::TypeTask,
        'assigned_to' => $actor->id,
        'created_by' => $actor->id,
        'is_active' => true,
    ]);
    $deletedTask->delete();

    $activeResponse = $this->actingAs($actor)
        ->getJson(route('admin.my-board.tasks.datatable', userTaskDataTableParams()))
        ->assertOk()
        ->json();
    $activeJson = json_encode($activeResponse['data'] ?? []);

    expect($activeJson)->toContain($activeTask->doc_num)
        ->and($activeJson)->not->toContain($inactiveTask->doc_num)
        ->and($activeJson)->not->toContain($deletedTask->doc_num);

    $inactiveResponse = $this->actingAs($actor)
        ->getJson(route('admin.my-board.tasks.datatable', userTaskDataTableParams([
            'record_filter' => 'inactive',
        ])))
        ->assertOk()
        ->json();
    $inactiveJson = json_encode($inactiveResponse['data'] ?? []);

    expect($inactiveJson)->toContain($inactiveTask->doc_num)
        ->and($inactiveJson)->not->toContain($activeTask->doc_num)
        ->and($inactiveJson)->not->toContain($deletedTask->doc_num);

    $deletedResponse = $this->actingAs($actor)
        ->getJson(route('admin.my-board.tasks.datatable', userTaskDataTableParams([
            'record_filter' => 'trashed',
        ])))
        ->assertOk()
        ->json();
    $deletedJson = json_encode($deletedResponse['data'] ?? []);

    expect($deletedJson)->toContain($deletedTask->doc_num)
        ->and($deletedJson)->toContain('js-my-board-table-restore')
        ->and($deletedJson)->not->toContain($activeTask->doc_num)
        ->and($deletedJson)->not->toContain($inactiveTask->doc_num);

    $allResponse = $this->actingAs($actor)
        ->getJson(route('admin.my-board.tasks.datatable', userTaskDataTableParams([
            'record_filter' => 'all',
        ])))
        ->assertOk()
        ->json();
    $allJson = json_encode($allResponse['data'] ?? []);

    expect($allJson)->toContain($activeTask->doc_num)
        ->and($allJson)->toContain($inactiveTask->doc_num)
        ->and($allJson)->toContain($deletedTask->doc_num);

    $activeNote = UserTask::factory()->note()->create([
        'title' => 'Note excluded from inactive filter',
        'assigned_to' => $actor->id,
        'created_by' => $actor->id,
        'is_active' => true,
    ]);
    $inactiveNote = UserTask::factory()->note()->create([
        'title' => 'Only inactive note row',
        'assigned_to' => $actor->id,
        'created_by' => $actor->id,
        'is_active' => false,
    ]);

    $noteResponse = $this->actingAs($actor)
        ->getJson(route('admin.my-board.notes.datatable', userTaskDataTableParams([
            'record_filter' => 'inactive',
        ])))
        ->assertOk()
        ->json();
    $noteJson = json_encode($noteResponse['data'] ?? []);

    expect($noteJson)->toContain($inactiveNote->doc_num)
        ->and($noteJson)->toContain('Only inactive note row')
        ->and($noteJson)->not->toContain('Note excluded from inactive filter')
        ->and($noteJson)->not->toContain('js-my-board-table-status-open');
});

test('MyBoard table bulk delete active state and restore actions are current board scoped', function () {
    $actor = userTaskActor(['my_board.view', 'my_board.edit', 'my_board.delete', 'my_board.view_trashed', 'my_board.restore']);
    $otherUser = User::factory()->create();

    $taskToDelete = UserTask::factory()->create([
        'assigned_to' => $actor->id,
        'created_by' => $actor->id,
    ]);
    $taskToDeactivate = UserTask::factory()->create([
        'assigned_to' => $actor->id,
        'created_by' => $actor->id,
        'is_active' => true,
    ]);
    $taskToActivate = UserTask::factory()->create([
        'assigned_to' => $actor->id,
        'created_by' => $actor->id,
        'is_active' => false,
    ]);
    $taskToRestore = UserTask::factory()->create([
        'assigned_to' => $actor->id,
        'created_by' => $actor->id,
    ]);
    $taskToRestore->delete();
    $otherTask = UserTask::factory()->create([
        'assigned_to' => $otherUser->id,
        'created_by' => $otherUser->id,
    ]);

    $this->actingAs($actor)
        ->deleteJson(route('admin.my-board.table.bulk-delete'), [
            'doc_nums' => [$taskToDelete->doc_num],
            'type' => UserTask::TypeTask,
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(UserTask::withTrashed()->whereKey($taskToDelete->getKey())->firstOrFail()->trashed())->toBeTrue();

    $this->actingAs($actor)
        ->patchJson(route('admin.my-board.table.bulk-active-state'), [
            'doc_nums' => [$taskToDeactivate->doc_num],
            'type' => UserTask::TypeTask,
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->actingAs($actor)
        ->patchJson(route('admin.my-board.table.bulk-active-state'), [
            'doc_nums' => [$taskToActivate->doc_num],
            'type' => UserTask::TypeTask,
            'is_active' => true,
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($taskToDeactivate->refresh()->is_active)->toBeFalse()
        ->and($taskToActivate->refresh()->is_active)->toBeTrue();

    $this->actingAs($actor)
        ->patchJson(route('admin.my-board.table.bulk-restore'), [
            'doc_nums' => [$taskToRestore->doc_num],
            'type' => UserTask::TypeTask,
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($taskToRestore->refresh()->trashed())->toBeFalse()
        ->and($taskToRestore->restored_by)->toBe($actor->id)
        ->and($taskToRestore->restored_at)->not->toBeNull();

    $this->actingAs($actor)
        ->patchJson(route('admin.my-board.table.bulk-active-state'), [
            'doc_nums' => [$otherTask->doc_num],
            'type' => UserTask::TypeTask,
            'is_active' => false,
        ])
        ->assertNotFound();
});

test('MyBoard table duplicate creates a new active current-user task or note without copying audit state', function () {
    $actor = userTaskActor(['my_board.view', 'my_board.clone']);

    $task = UserTask::factory()->create([
        'title' => 'Duplicate this task',
        'description' => '<p>Task body</p>',
        'type' => UserTask::TypeTask,
        'status' => UserTask::StatusInProgress,
        'priority' => UserTask::PriorityHigh,
        'color' => 'danger',
        'assigned_to' => $actor->id,
        'created_by' => $actor->id,
        'is_active' => false,
        'deleted_by' => $actor->id,
        'restored_by' => $actor->id,
        'restored_at' => now(),
    ]);
    $task->assignees()->sync([$actor->id => ['assigned_by' => $actor->id]]);
    $note = UserTask::factory()->note()->create([
        'title' => 'Duplicate this note',
        'description' => '<p>Note body</p>',
        'assigned_to' => $actor->id,
        'created_by' => $actor->id,
        'color' => 'info',
    ]);

    $taskCloneDocNum = $this->actingAs($actor)
        ->postJson(route('admin.my-board.tasks.clone', $task->doc_num))
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->json('data.doc_num');

    $taskClone = UserTask::query()->where('doc_num', $taskCloneDocNum)->firstOrFail();

    expect($taskClone->doc_num)->not->toBe($task->doc_num)
        ->and($taskClone->title)->toBe($task->title)
        ->and($taskClone->description)->toBe($task->description)
        ->and($taskClone->type)->toBe(UserTask::TypeTask)
        ->and($taskClone->assigned_to)->toBe($actor->id)
        ->and($taskClone->is_active)->toBeTrue()
        ->and($taskClone->deleted_by)->toBeNull()
        ->and($taskClone->restored_by)->toBeNull()
        ->and($taskClone->restored_at)->toBeNull()
        ->and($taskClone->trashed())->toBeFalse();

    $noteCloneDocNum = $this->actingAs($actor)
        ->postJson(route('admin.my-board.notes.clone', $note->doc_num))
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->json('data.doc_num');

    $noteClone = UserTask::query()->where('doc_num', $noteCloneDocNum)->firstOrFail();

    expect($noteClone->doc_num)->not->toBe($note->doc_num)
        ->and($noteClone->type)->toBe(UserTask::TypeNote)
        ->and($noteClone->title)->toBe($note->title)
        ->and($noteClone->assigned_to)->toBe($actor->id)
        ->and($noteClone->is_active)->toBeTrue();
});

test('MyBoard table CRUD updates the same board data used by Kanban', function () {
    $actor = userTaskActor(['my_board.view', 'my_board.create', 'my_board.edit', 'my_board.reorder', 'my_board.delete']);

    $this->actingAs($actor)
        ->get(route('admin.my-board.tasks.create'))
        ->assertOk()
        ->assertSee('js-my-board-table-form', false)
        ->assertSee('vendors/summernote/summernote-bs5.min.js', false)
        ->assertSee('js-my-board-rich-editor', false)
        ->assertSee(__('user_tasks.titles.table_create_task'))
        ->assertDontSee('name="board_user_doc_num"', false)
        ->assertDontSee('myBoardTableItemModal', false);

    $created = $this->actingAs($actor)
        ->postJson(route('admin.my-board.tasks.store'), userTaskPayload([
            'title' => 'Created from table',
            'submit_action' => 'save_edit',
        ]))
        ->assertCreated()
        ->assertJsonPath('data.item.type', UserTask::TypeTask)
        ->assertJsonPath('submit_action', 'save_edit')
        ->json('data.item.doc_num');

    $this->actingAs($actor)
        ->get(route('admin.my-board.tasks.show', $created))
        ->assertOk()
        ->assertSee('js-my-board-table-form', false)
        ->assertSee(__('user_tasks.titles.table_view_task'));

    $this->actingAs($actor)
        ->get(route('admin.my-board.tasks.edit', $created))
        ->assertOk()
        ->assertSee('js-my-board-table-form', false)
        ->assertSee(__('user_tasks.titles.table_edit_task'));

    $this->actingAs($actor)
        ->getJson(route('admin.my-board.data'))
        ->assertOk()
        ->assertSee($created);

    $this->actingAs($actor)
        ->patchJson(route('admin.my-board.tasks.status', $created), [
            'status' => UserTask::StatusDone,
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $task = UserTask::query()->where('doc_num', $created)->firstOrFail();

    expect($task->status)->toBe(UserTask::StatusDone)
        ->and($task->completed_at)->not->toBeNull();

    $this->actingAs($actor)
        ->putJson(route('admin.my-board.tasks.update', $created), userTaskPayload([
            'title' => 'Updated from table',
            'status' => UserTask::StatusDone,
            'priority' => UserTask::PriorityHigh,
        ]))
        ->assertOk()
        ->assertJsonPath('data.item.title', 'Updated from table');

    $this->actingAs($actor)
        ->deleteJson(route('admin.my-board.tasks.destroy', $created))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(UserTask::withTrashed()->where('doc_num', $created)->firstOrFail()->trashed())->toBeTrue();
});

test('MyBoard table create ignores spoofed board owner parameters', function () {
    $actor = userTaskAdminActor(['my_board.view', 'my_board.create', 'my_board.assign', 'my_board.view_any', 'my_board.manage_any']);
    $otherUser = User::factory()->create();

    $created = $this->actingAs($actor)
        ->postJson(route('admin.my-board.tasks.store'), userTaskPayload([
            'title' => 'Spoofed table owner',
            'board_user_doc_num' => $otherUser->doc_num,
            'assignee_doc_nums' => [$otherUser->doc_num],
        ]))
        ->assertCreated()
        ->json('data.item.doc_num');

    $task = UserTask::query()->where('doc_num', $created)->firstOrFail();

    expect($task->assigned_to)->toBe($actor->id)
        ->and($task->assignees()->whereKey($actor->id)->exists())->toBeTrue()
        ->and($task->assignees()->whereKey($otherUser->id)->exists())->toBeTrue();
});

test('Team Board is a separate permissioned screen outside the personal board', function () {
    $blocked = userTaskActor(['my_board.view']);

    $this->actingAs($blocked)
        ->get(route('admin.tools.team-board.index'))
        ->assertForbidden();

    $this->actingAs($blocked)
        ->get(route('admin.my-board.index'))
        ->assertOk()
        ->assertDontSee('team-board-tab', false)
        ->assertDontSee('team-all-tasks-tab', false)
        ->assertDontSee('team-all-notes-tab', false);

    $blockedMenu = app(MenuService::class)->getMenu($blocked);
    $blockedTools = collect($blockedMenu)->firstWhere('label', 'tools');

    expect(collect($blockedTools['children'] ?? [])->pluck('label')->all())->not->toContain('team_board');

    $nonAdminWithTeamPermissions = userTaskActor(['my_board.tasks.view_all', 'my_board.notes.view_all']);

    $this->actingAs($nonAdminWithTeamPermissions)
        ->get(route('admin.tools.team-board.index'))
        ->assertForbidden();

    $notesOnly = userTaskAdminActor(['my_board.notes.view_all']);

    $this->actingAs($notesOnly)
        ->get(route('admin.tools.team-board.index'))
        ->assertOk()
        ->assertDontSee('team-board-tasks-table', false)
        ->assertDontSee('js-team-board-filter', false)
        ->assertDontSee('team-board-notes-table', false)
        ->assertDontSee('kanban-container', false)
        ->assertDontSee('assets/js/modules/Core/my-board.js', false);

    $actor = userTaskAdminActor(['my_board.tasks.view_all', 'my_board.notes.view_all']);

    $this->actingAs($actor)
        ->get(route('admin.tools.team-board.index'))
        ->assertOk()
        ->assertSee(__('user_tasks.team_board'))
        ->assertDontSee('team-board-report-tasks-tab', false)
        ->assertDontSee('team-board-report-notes-tab', false)
        ->assertSee('team-board-tasks-table', false)
        ->assertDontSee('team-board-notes-table', false)
        ->assertSee('js-team-board-filter', false)
        ->assertSee('assets/js/modules/Core/team-board-report.js', false)
        ->assertDontSee('js-board-all-tasks', false)
        ->assertDontSee('js-board-all-notes', false)
        ->assertDontSee('kanban-container', false)
        ->assertDontSee('vendors/sortablejs/Sortable.min.js', false)
        ->assertDontSee('assets/js/modules/Core/my-board.js', false)
        ->assertDontSee('tasks-board-tab', false)
        ->assertDontSee('notes-board-tab', false);
});

test('Team Board report datatables are admin-only and apply report filters', function () {
    $boardOwner = User::factory()->create();
    $otherUser = User::factory()->create();
    $actor = userTaskAdminActor(['my_board.tasks.view_all', 'my_board.notes.view_all']);

    $matchingTask = UserTask::factory()->create([
        'title' => 'Visible team report task',
        'type' => UserTask::TypeTask,
        'assigned_to' => $boardOwner->id,
        'created_by' => $actor->id,
        'status' => UserTask::StatusTodo,
    ]);
    $unrelatedTask = UserTask::factory()->create([
        'title' => 'Hidden team report task',
        'type' => UserTask::TypeTask,
        'assigned_to' => $otherUser->id,
        'created_by' => $otherUser->id,
        'status' => UserTask::StatusTodo,
    ]);
    $matchingNote = UserTask::factory()->create([
        'title' => 'Visible team report note',
        'type' => UserTask::TypeNote,
        'assigned_to' => $boardOwner->id,
        'created_by' => $actor->id,
        'status' => UserTask::StatusTodo,
    ]);
    $unrelatedNote = UserTask::factory()->create([
        'title' => 'Hidden team report note',
        'type' => UserTask::TypeNote,
        'assigned_to' => $otherUser->id,
        'created_by' => $otherUser->id,
        'status' => UserTask::StatusTodo,
    ]);

    $taskResponse = $this->actingAs($actor)
        ->getJson(route('admin.tools.team-board.tasks.data', teamBoardReportDataTableParams(UserTask::TypeTask, [
            'board_owner_doc_num' => $boardOwner->doc_num,
        ])))
        ->assertOk()
        ->json();

    $taskJson = json_encode($taskResponse);

    expect($taskJson)->toContain($matchingTask->doc_num)
        ->and($taskJson)->not->toContain($unrelatedTask->doc_num)
        ->and($taskJson)->not->toContain($matchingNote->doc_num)
        ->and($taskJson)->not->toContain('"id":'.$matchingTask->id)
        ->and($taskJson)->not->toContain('btn-reveal')
        ->and($taskJson)->not->toContain('js-my-board-table-row-checkbox');

    $noteResponse = $this->actingAs($actor)
        ->getJson(route('admin.tools.team-board.notes.data', teamBoardReportDataTableParams(UserTask::TypeNote, [
            'board_owner_doc_num' => $boardOwner->doc_num,
        ])))
        ->assertOk()
        ->json();

    $noteJson = json_encode($noteResponse);

    expect($noteJson)->toContain($matchingNote->doc_num)
        ->and($noteJson)->not->toContain($unrelatedNote->doc_num)
        ->and($noteJson)->not->toContain($matchingTask->doc_num)
        ->and($noteJson)->not->toContain('"id":'.$matchingNote->id)
        ->and($noteJson)->not->toContain('btn-reveal')
        ->and($noteJson)->not->toContain('js-my-board-table-row-checkbox');

    $nonAdminWithReportPermissions = userTaskActor(['my_board.tasks.view_all', 'my_board.notes.view_all']);

    $this->actingAs($nonAdminWithReportPermissions)
        ->getJson(route('admin.tools.team-board.tasks.data', teamBoardReportDataTableParams(UserTask::TypeTask)))
        ->assertForbidden();

    $this->actingAs($nonAdminWithReportPermissions)
        ->getJson(route('admin.tools.team-board.notes.data', teamBoardReportDataTableParams(UserTask::TypeNote)))
        ->assertForbidden();
});

test('MyBoard can create personal notes and defaults assignment to the current user', function () {
    $actor = userTaskActor(['my_board.view', 'my_board.create']);

    $response = $this->actingAs($actor)
        ->postJson(route('admin.my-board.store'), userTaskPayload([
            'title' => 'Personal scratch note',
            'type' => UserTask::TypeNote,
        ]))
        ->assertCreated()
        ->assertJsonPath('success', true);

    $task = UserTask::query()->where('doc_num', $response->json('data.item.doc_num'))->firstOrFail();

    expect($task->type)->toBe(UserTask::TypeNote)
        ->and($task->created_by)->toBe($actor->id)
        ->and($task->assigned_to)->toBe($actor->id)
        ->and($task->doc_num)->toStartWith('Task-');
});

test('MyBoard can create notes without priority while tasks still require priority', function () {
    $actor = userTaskActor(['my_board.view', 'my_board.create']);

    $this->actingAs($actor)
        ->postJson(route('admin.my-board.store'), userTaskPayload([
            'title' => 'Note without priority',
            'type' => UserTask::TypeNote,
            'priority' => null,
        ]))
        ->assertCreated()
        ->assertJsonPath('success', true);

    $this->actingAs($actor)
        ->postJson(route('admin.my-board.store'), userTaskPayload([
            'title' => 'Task without priority',
            'type' => UserTask::TypeTask,
            'priority' => null,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('priority');
});

test('MyBoard task details record non creator views and support sanitized comments', function () {
    $creator = userTaskActor(['my_board.view']);
    $viewer = userTaskActor(['my_board.view', 'my_board.comments.create']);

    $task = UserTask::factory()->create([
        'title' => 'Needs review',
        'created_by' => $creator->id,
        'assigned_to' => $viewer->id,
        'assigned_by' => $creator->id,
    ]);
    $task->assignees()->sync([$viewer->id => ['assigned_by' => $creator->id]]);

    $this->actingAs($creator)
        ->getJson(route('admin.my-board.show', $task->doc_num))
        ->assertOk()
        ->assertJsonPath('data.item.viewers', []);

    expect(MyBoardTaskView::query()->count())->toBe(0);

    $this->actingAs($viewer)
        ->getJson(route('admin.my-board.show', $task->doc_num))
        ->assertOk()
        ->assertJsonPath('data.item.viewers.0.doc_num', $viewer->doc_num);

    $this->actingAs($viewer)
        ->getJson(route('admin.my-board.show', $task->doc_num))
        ->assertOk();

    expect(MyBoardTaskView::query()->count())->toBe(1);

    $this->actingAs($viewer)
        ->postJson(route('admin.my-board.comments.store', $task->doc_num), [
            'body_html' => '<p><br></p>',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body_html');

    $blockedCommenter = userTaskActor(['my_board.view']);

    $this->actingAs($blockedCommenter)
        ->postJson(route('admin.my-board.comments.store', $task->doc_num), [
            'body_html' => '<p>Blocked</p>',
        ])
        ->assertForbidden();

    $commentId = $this->actingAs($viewer)
        ->postJson(route('admin.my-board.comments.store', $task->doc_num), [
            'body_html' => '<p>Looks good</p><script>alert("x")</script>',
        ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.comment.body_text', 'Looks good')
        ->json('data.comment.id');

    $comment = MyBoardTaskComment::query()->findOrFail($commentId);

    expect($comment->body_html)->toContain('Looks good')
        ->and($comment->body_html)->not->toContain('<script');

    $this->actingAs($viewer)
        ->deleteJson(route('admin.my-board.comments.destroy', [$task->doc_num, $comment->id]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(MyBoardTaskComment::withTrashed()->findOrFail($comment->id)->trashed())->toBeTrue();
});

test('MyBoard all tasks endpoint requires permission and filters across users', function () {
    $blocked = userTaskActor(['my_board.view']);

    $this->actingAs($blocked)
        ->getJson(route('admin.my-board.all-tasks'))
        ->assertForbidden();

    $this->actingAs($blocked)
        ->getJson(route('admin.my-board.all-notes'))
        ->assertForbidden();

    $nonAdminWithViewAll = userTaskActor(['my_board.view', 'my_board.tasks.view_all']);

    $this->actingAs($nonAdminWithViewAll)
        ->getJson(route('admin.my-board.all-tasks'))
        ->assertForbidden();

    $actor = userTaskAdminActor(['my_board.view', 'my_board.tasks.view_all']);
    $assignee = User::factory()->create();
    $otherAssignee = User::factory()->create();
    $matching = UserTask::factory()->create([
        'title' => 'Visible all task',
        'assigned_to' => $assignee->id,
        'created_by' => $otherAssignee->id,
        'status' => UserTask::StatusTodo,
    ]);
    $other = UserTask::factory()->create([
        'title' => 'Other all task',
        'assigned_to' => $otherAssignee->id,
        'created_by' => $assignee->id,
        'status' => UserTask::StatusInProgress,
    ]);

    $response = $this->actingAs($actor)
        ->getJson(route('admin.my-board.all-tasks', ['assigned_user_doc_num' => $assignee->doc_num]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json();

    $json = json_encode($response);

    expect($json)->toContain($matching->doc_num)
        ->and($json)->toContain($assignee->doc_num)
        ->and($json)->not->toContain($other->doc_num)
        ->and($json)->not->toContain('"id":'.$matching->id);

    $details = $this->actingAs($actor)
        ->getJson(route('admin.my-board.show', $matching->doc_num))
        ->assertOk()
        ->json('data.item');

    expect($details['assigned_to_label'])->toContain($assignee->doc_num)
        ->and($details['assigned_to_label'])->not->toContain((string) $assignee->email);
});

test('MyBoard all notes endpoint requires permission and filters across users', function () {
    $blocked = userTaskActor(['my_board.view']);

    $this->actingAs($blocked)
        ->getJson(route('admin.my-board.all-notes'))
        ->assertForbidden();

    $nonAdminWithViewAll = userTaskActor(['my_board.view', 'my_board.notes.view_all']);

    $this->actingAs($nonAdminWithViewAll)
        ->getJson(route('admin.my-board.all-notes'))
        ->assertForbidden();

    $actor = userTaskAdminActor(['my_board.view', 'my_board.notes.view_all']);
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    $creator = User::factory()->create();
    $matching = UserTask::factory()->create([
        'title' => 'Visible team note',
        'description' => '<p>Shared note body</p>',
        'type' => UserTask::TypeNote,
        'assigned_to' => $owner->id,
        'created_by' => $creator->id,
        'status' => UserTask::StatusTodo,
        'priority' => UserTask::PriorityNormal,
    ]);
    $other = UserTask::factory()->create([
        'title' => 'Other team note',
        'type' => UserTask::TypeNote,
        'assigned_to' => $otherOwner->id,
        'created_by' => $creator->id,
        'priority' => UserTask::PriorityNormal,
    ]);

    $response = $this->actingAs($actor)
        ->getJson(route('admin.my-board.all-notes', ['board_owner_doc_num' => $owner->doc_num]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json();

    $json = json_encode($response);

    expect($json)->toContain($matching->doc_num)
        ->and($json)->toContain('Shared note body')
        ->and($json)->toContain($owner->doc_num)
        ->and($json)->not->toContain($other->doc_num)
        ->and($json)->not->toContain('"id":'.$matching->id);
});

test('user select2 keeps display compact while searching private contact fields', function () {
    $actor = userTaskActor(['my_board.create']);
    $target = User::factory()->create([
        'name' => 'Sarah Selector',
        'username' => 'sarah-private',
        'email' => 'sarah.selector@example.test',
        'phone' => '555123987',
    ]);

    $response = $this->actingAs($actor)
        ->getJson(route('admin.select2.users', ['q' => '555123987']))
        ->assertOk()
        ->json();

    expect($response['results'][0]['id'])->toBe($target->doc_num)
        ->and($response['results'][0]['text'])->toBe("Sarah Selector / {$target->doc_num}")
        ->and($response['results'][0]['text'])->not->toContain('sarah.selector@example.test')
        ->and($response['results'][0]['text'])->not->toContain('555123987')
        ->and($response['results'][0]['text'])->not->toContain('sarah-private');
});

test('MyBoard can update and move allowed items but cannot update unrelated tasks', function () {
    $actor = userTaskActor(['my_board.view', 'my_board.edit', 'my_board.reorder']);
    $other = User::factory()->create();

    $task = UserTask::factory()->create([
        'created_by' => $actor->id,
        'assigned_to' => $actor->id,
        'status' => UserTask::StatusTodo,
        'position' => 0,
    ]);
    $unrelated = UserTask::factory()->create([
        'created_by' => $other->id,
        'assigned_to' => $other->id,
    ]);

    $this->actingAs($actor)
        ->putJson(route('admin.my-board.update', $task->doc_num), userTaskPayload([
            'title' => 'Updated board item',
            'status' => UserTask::StatusTodo,
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->actingAs($actor)
        ->patchJson(route('admin.my-board.move', $task->doc_num), [
            'status' => UserTask::StatusDone,
            'ordered_doc_nums' => [$task->doc_num],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $task->refresh();

    expect($task->title)->toBe('Updated board item')
        ->and($task->status)->toBe(UserTask::StatusDone)
        ->and($task->completed_at)->not->toBeNull();

    $this->actingAs($actor)
        ->putJson(route('admin.my-board.update', $unrelated->doc_num), userTaskPayload([
            'title' => 'Should fail',
        ]))
        ->assertForbidden();
});

test('standalone Task CRUD routes are removed from the active app surface', function () {
    expect(Route::has('admin.tasks.index'))->toBeFalse()
        ->and(Route::has('admin.tasks.store'))->toBeFalse()
        ->and(Route::has('admin.tasks.data'))->toBeFalse();
});
