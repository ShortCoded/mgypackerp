<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Core\Models\ChatConversation;
use Modules\Core\Models\ChatMessage;
use Modules\Core\Models\ChatMessageAttachment;
use Modules\Core\Services\ChatConversationReportService;
use Modules\Core\Services\MenuService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function chatReportActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

/**
 * @return array{sender: User, recipient: User, conversation: ChatConversation, attachment: ChatMessageAttachment}
 */
function conversationReportFixture(object $testCase, string $body = '=CONFIDENTIAL-CHAT-CONTENT'): array
{
    Storage::fake('local');

    $sender = chatReportActor(['chat.view', 'chat.create', 'chat.send']);
    $recipient = chatReportActor(['chat.view', 'chat.send']);
    $conversationUuid = $testCase->actingAs($sender)
        ->postJson(route('admin.chat.conversations.store'), ['user_doc_num' => $recipient->doc_num])
        ->assertOk()
        ->json('data.conversation.id');

    $testCase->actingAs($sender)
        ->post(route('admin.chat.messages.store', $conversationUuid), [
            'body' => $body,
            'attachments' => [
                UploadedFile::fake()->createWithContent('internal-audit.pdf', '%PDF-test-attachment'),
            ],
        ], ['Accept' => 'application/json'])
        ->assertOk();

    $testCase->actingAs($recipient)
        ->postJson(route('admin.chat.messages.read', $conversationUuid))
        ->assertOk();

    return [
        'sender' => $sender,
        'recipient' => $recipient,
        'conversation' => ChatConversation::query()->where('public_uuid', $conversationUuid)->sole(),
        'attachment' => ChatMessageAttachment::query()->sole(),
    ];
}

test('conversation report permissions are discovered and granted to the admin role', function () {
    $this->seed(PermissionSeeder::class);

    $adminRole = Role::query()->where('name', 'admin')->where('guard_name', 'web')->sole();
    $admin = User::factory()->create();
    $admin->assignRole($adminRole);

    foreach (['view', 'export', 'pdf', 'print'] as $action) {
        expect(Permission::query()->where('name', "chat.reports.{$action}")->exists())->toBeTrue()
            ->and($adminRole->hasPermissionTo("chat.reports.{$action}"))->toBeTrue();
    }

    $menuItems = app(MenuService::class)->getMenu($admin);
    $tools = collect($menuItems)->firstWhere('label', 'tools');
    $communicationMenu = collect($tools['children'] ?? [])->firstWhere('label', 'communication');

    expect($tools)->not->toBeNull()
        ->and($communicationMenu)->not->toBeNull()
        ->and(collect($communicationMenu['children'] ?? [])->pluck('label')->all())
        ->toContain('chat', 'chat_report');
});

test('conversation report and protected attachment require report permission', function () {
    $fixture = conversationReportFixture($this);
    $reportViewer = chatReportActor(['chat.reports.view']);
    $exportOnlyUser = chatReportActor(['chat.reports.export']);

    $this->actingAs($fixture['recipient'])
        ->get(route('admin.chat.reports.index'))
        ->assertForbidden();

    $this->actingAs($fixture['recipient'])
        ->get(route('admin.chat.reports.attachments.show', $fixture['attachment']))
        ->assertForbidden();

    $this->actingAs($reportViewer)
        ->get(route('admin.chat.reports.attachments.show', $fixture['attachment']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('x-content-type-options', 'nosniff');

    $fixture['attachment']->forceFill([
        'mime_type' => 'image/svg+xml',
        'original_name' => 'unsafe-inline.svg',
    ])->save();
    $this->actingAs($reportViewer)
        ->get(route('admin.chat.reports.attachments.show', $fixture['attachment']))
        ->assertOk()
        ->assertDownload('unsafe-inline.svg');

    $this->actingAs($exportOnlyUser)
        ->get(route('admin.chat.reports.excel'))
        ->assertForbidden();

    $this->actingAs($exportOnlyUser)
        ->get(route('admin.chat.reports.conversation.excel', $fixture['conversation']))
        ->assertForbidden();
});

test('admin report shows conversation details read state former participants and attachment links', function () {
    $body = 'رسالة مراجعة داخلية شديدة الخصوصية';
    $fixture = conversationReportFixture($this, $body);
    $reportViewer = chatReportActor(['chat.reports.view']);

    DB::table('chat_conversation_user')
        ->where('conversation_id', $fixture['conversation']->getKey())
        ->where('user_id', $fixture['recipient']->getKey())
        ->update(['deleted_at' => now()]);

    $index = $this->actingAs($reportViewer)
        ->get(route('admin.chat.reports.index'))
        ->assertOk()
        ->assertSee(__('chat.report.title'))
        ->assertSee($fixture['sender']->name)
        ->assertSee($fixture['recipient']->name)
        ->assertSee($body)
        ->assertSee('assets/css/modules/Core/chat-report.css', false);

    $show = $this->actingAs($reportViewer)
        ->get(route('admin.chat.reports.show', $fixture['conversation']))
        ->assertOk()
        ->assertSee($body)
        ->assertSee('internal-audit.pdf')
        ->assertSee(__('chat.report.former_participant'))
        ->assertSee(route('admin.chat.reports.attachments.show', $fixture['attachment']), false)
        ->assertSee($fixture['recipient']->name);

    expect($index->getContent())->toContain((string) $fixture['conversation']->public_uuid)
        ->and($show->getContent())->toContain((string) $fixture['attachment']->public_uuid);
});

test('conversation report filters real message content participant attachment and deleted state', function () {
    $fixture = conversationReportFixture($this, 'Needle audit phrase');
    $otherSender = chatReportActor(['chat.view', 'chat.create', 'chat.send']);
    $otherRecipient = chatReportActor(['chat.view']);
    $otherConversationUuid = $this->actingAs($otherSender)
        ->postJson(route('admin.chat.conversations.store'), ['user_doc_num' => $otherRecipient->doc_num])
        ->assertOk()
        ->json('data.conversation.id');
    $this->actingAs($otherSender)
        ->postJson(route('admin.chat.messages.store', $otherConversationUuid), ['body' => 'Different content'])
        ->assertOk();

    $reportViewer = chatReportActor(['chat.reports.view']);

    $this->actingAs($reportViewer)
        ->get(route('admin.chat.reports.index', ['q' => 'Needle audit']))
        ->assertOk()
        ->assertSee('Needle audit phrase')
        ->assertDontSee('Different content');

    $this->actingAs($reportViewer)
        ->get(route('admin.chat.reports.index', ['participant' => $fixture['recipient']->doc_num, 'has_attachments' => '1']))
        ->assertOk()
        ->assertSee((string) $fixture['conversation']->public_uuid)
        ->assertDontSee($otherConversationUuid);

    $fixture['conversation']->delete();

    $this->actingAs($reportViewer)
        ->get(route('admin.chat.reports.index', ['status' => 'deleted']))
        ->assertOk()
        ->assertSee((string) $fixture['conversation']->public_uuid)
        ->assertDontSee($otherConversationUuid);
});

test('conversation report date filters keep row counts and transcript messages consistent', function () {
    $fixture = conversationReportFixture($this, 'Old attachment message');
    $fixture['attachment']->message()->update(['sent_at' => now()->subDays(2)]);

    $this->actingAs($fixture['sender'])
        ->postJson(route('admin.chat.messages.store', $fixture['conversation']), ['body' => 'Current message'])
        ->assertOk();

    $filters = [
        'date_from' => now()->toDateString(),
        'date_to' => now()->toDateString(),
    ];
    $report = app(ChatConversationReportService::class);
    $summary = $report->conversationQuery($filters)
        ->whereKey($fixture['conversation']->getKey())
        ->sole();
    $transcript = $report->transcript([
        ...$filters,
        'conversation_uuid' => $fixture['conversation']->public_uuid,
    ])->sole();

    expect($summary->messages_count)->toBe(1)
        ->and($summary->attachments_count)->toBe(0)
        ->and($transcript->messages_count)->toBe(1)
        ->and($transcript->messages)->toHaveCount(1)
        ->and($transcript->messages->sole())->toBeInstanceOf(ChatMessage::class)
        ->and($transcript->messages->sole()->body)->toBe('Current message');
});

test('all and single conversation exports produce real excel pdf and print output', function () {
    $fixture = conversationReportFixture($this);
    $reportViewer = chatReportActor([
        'chat.reports.view',
        'chat.reports.export',
        'chat.reports.pdf',
        'chat.reports.print',
    ]);

    $allExcel = $this->actingAs($reportViewer)
        ->get(route('admin.chat.reports.excel'))
        ->assertOk()
        ->assertDownload();
    $workbook = IOFactory::load($allExcel->baseResponse->getFile()->getPathname());
    $messagesSheet = $workbook->getSheet(1);

    expect($workbook->getSheetCount())->toBe(2)
        ->and($messagesSheet->getCell('E2')->getValue())->toBe('=CONFIDENTIAL-CHAT-CONTENT')
        ->and($messagesSheet->getCell('E2')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($messagesSheet->getCell('F2')->getValue())->toContain('internal-audit.pdf')
        ->and($messagesSheet->getCell('F2')->getValue())->toContain(route('admin.chat.reports.attachments.show', $fixture['attachment']));

    $this->actingAs($reportViewer)
        ->get(route('admin.chat.reports.conversation.excel', $fixture['conversation']))
        ->assertOk()
        ->assertDownload();

    $allPdf = $this->actingAs($reportViewer)
        ->get(route('admin.chat.reports.pdf'))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $singlePdf = $this->actingAs($reportViewer)
        ->get(route('admin.chat.reports.conversation.pdf', $fixture['conversation']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect($allPdf->getContent())->toStartWith('%PDF-')
        ->and($singlePdf->getContent())->toStartWith('%PDF-');

    $this->actingAs($reportViewer)
        ->get(route('admin.chat.reports.print'))
        ->assertOk()
        ->assertSee('window.print()', false)
        ->assertSee('internal-audit.pdf');

    $this->actingAs($reportViewer)
        ->get(route('admin.chat.reports.conversation.print', $fixture['conversation']))
        ->assertOk()
        ->assertSee('=CONFIDENTIAL-CHAT-CONTENT')
        ->assertSee(route('admin.chat.reports.attachments.show', $fixture['attachment']), false);
});

test('report activity audit omits message text and search query values', function () {
    conversationReportFixture($this, 'DO-NOT-LOG-THIS-MESSAGE');
    $reportViewer = chatReportActor(['chat.reports.view']);

    $this->actingAs($reportViewer)
        ->get(route('admin.chat.reports.index', ['q' => 'DO-NOT-LOG-THIS-MESSAGE']))
        ->assertOk();

    $activity = Activity::query()->where('event', 'chat.report.view')->latest('id')->sole();
    $encoded = json_encode([
        'properties' => $activity->properties?->toArray(),
        'url' => $activity->getAttribute('url'),
        'description' => $activity->description,
    ]);

    expect($encoded)->not->toContain('DO-NOT-LOG-THIS-MESSAGE')
        ->and($activity->properties?->get('scope'))->toBe('all')
        ->and($activity->properties?->get('record_count'))->toBe(1);
});

test('chat screen includes complete mobile layout and accessible conversation controls', function () {
    $actor = chatReportActor(['chat.view', 'chat.create', 'chat.send']);

    $response = $this->actingAs($actor)->get(route('admin.chat.index'))->assertOk();
    $stylesheet = file_get_contents(public_path('assets/css/modules/Core/chat.css'));
    $script = file_get_contents(public_path('assets/js/modules/Core/chat.js'));

    expect($response->getContent())->toContain('assets/css/modules/Core/chat.css')
        ->and($stylesheet)->toContain('100dvh')
        ->and($stylesheet)->toContain('.card-chat.contacts-list-show .chat-sidebar')
        ->and($stylesheet)->toContain('env(safe-area-inset-bottom)')
        ->and($script)->toContain("window.matchMedia('(max-width: 767.98px)')")
        ->and($script)->toContain('tabindex="0"')
        ->and($script)->toContain('syncMobileLayout()');
});
