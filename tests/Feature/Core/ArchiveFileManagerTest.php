<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Core\Http\Middleware\TrackUserActivity;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\ArchiveFolder;
use Modules\Core\Models\ArchivePublicLink;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Services\ArchiveFileService;
use Modules\Core\Services\ArchiveFileUsageService;
use Modules\Core\Services\ArchiveFolderService;
use Modules\Core\Services\OperatingContextService;
use Modules\FixedAssets\Models\FixedAsset;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function coreArchiveActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function coreArchiveOperatingContext(object $test): Company
{
    static $documentNumber = 7300;

    $documentNumber++;
    $company = Company::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Company-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'name' => 'Archive Company '.$documentNumber,
        'status' => 'active',
        'is_main' => ! Company::query()->where('is_main', true)->exists(),
    ]);
    $branch = Branch::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Branch-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Archive Branch '.$documentNumber,
        'type' => 'administrative',
        'status' => 'active',
    ]);
    $period = FinancialPeriod::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Period-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Archive Period '.$documentNumber,
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
    ]);

    $test->withSession([
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ]);

    return $company;
}

function coreArchiveFileForCompany(Company $company, UploadedFile $file): ArchiveFile
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

function coreArchiveSvgImage(string $name = 'vector.svg'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><rect width="1" height="1" fill="#fff"/></svg>',
    );
}

function coreArchiveBmpImage(string $name = 'bitmap.bmp'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        base64_decode('Qk1GAAAAAAAAADYAAAAoAAAAAQAAAAEAAAABABgAAAAAAAQAAAAAAAAAAAAAAAAAAAAAAP///wAA', true) ?: '',
    );
}

beforeEach(function (): void {
    $this->withoutMiddleware(TrackUserActivity::class);

    Storage::fake('local');
    config()->set('archive.disk', 'local');
});

test('archive permissions are discoverable and assigned to admin role', function () {
    $this->seed(PermissionSeeder::class);

    $adminRole = Role::query()
        ->where('name', 'admin')
        ->where('guard_name', 'web')
        ->firstOrFail();

    expect(Permission::query()->where('name', 'file_manager.folders.create')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'file_manager.download')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'file_manager.delete')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'file_manager.view_trashed')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'file_manager.restore')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'file_manager.folders.restore')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'file_manager.update_picker_visibility')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'file_manager.move')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'file_manager.document_number_settings.update')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'file_manager.delete')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'file_manager.public_links.create')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'file_manager.public_links.view')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'file_manager.public_links.revoke')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'companies.files.upload')->exists())->toBeFalse()
        ->and(Permission::query()->where('name', 'companies.files.folders.create')->exists())->toBeFalse()
        ->and(Permission::query()->where('name', 'companies.files.download')->exists())->toBeFalse()
        ->and(Permission::query()->where('name', 'companies.files.delete')->exists())->toBeFalse()
        ->and(Permission::query()->where('name', 'companies.files.public_links.create')->exists())->toBeFalse()
        ->and($adminRole->hasPermissionTo('file_manager.download'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('file_manager.delete'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('file_manager.view_trashed'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('file_manager.restore'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('file_manager.folders.restore'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('file_manager.update_picker_visibility'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('file_manager.move'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('file_manager.document_number_settings.update'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('file_manager.view'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('file_manager.public_links.create'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('file_manager.public_links.view'))->toBeTrue()
        ->and($adminRole->hasPermissionTo('file_manager.public_links.revoke'))->toBeTrue();
});

test('file manager uploader keeps dropzone preview markup hidden until files are selected', function () {
    $actor = coreArchiveActor(['file_manager.view', 'file_manager.upload', 'file_manager.download', 'file_manager.delete', 'file_manager.move', 'file_manager.folders.create', 'file_manager.document_number_settings.update']);

    $content = $this->actingAs($actor)
        ->get(route('admin.file-manager.index'))
        ->assertOk()
        ->assertSee('js-archive-dropzone', false)
        ->assertSee('id="archive-files-table"', false)
        ->assertSee('id="file_manager_select_all"', false)
        ->assertSee('id="file_manager_bulk_actions_bar"', false)
        ->assertSee('id="file_manager_bulk_action_select"', false)
        ->assertSee('id="file_manager_bulk_action_apply"', false)
        ->assertSee('data-bulk-delete-url="'.route('admin.file-manager.bulk-delete').'"', false)
        ->assertSee('data-bulk-move-url="'.route('admin.file-manager.bulk-move').'"', false)
        ->assertSee('data-folder-options-url="'.route('admin.file-manager.folder-options').'"', false)
        ->assertSee('id="archive-move-modal"', false)
        ->assertSee('js-archive-document-number-settings-form', false)
        ->assertSee(route('admin.file-manager.document-number-settings.update'), false)
        ->assertSee(__('archive.delete_selected'))
        ->assertSee(__('archive.move_selected'))
        ->assertSee(__('common.document_number_settings.title'))
        ->assertSee(__('archive.public_links.allow_download_label'))
        ->assertSee(__('common.fields.updated_by'))
        ->assertSee(__('common.fields.updated_at'))
        ->assertSee('data-shortcut-action="file-manager.create-folder"', false)
        ->assertSee('data-shortcut-action="file-manager.upload"', false)
        ->assertSee('js-dropzone-preview-template', false)
        ->assertSee('dz-preview-container dz-preview-multiple m-0 d-flex flex-column"></div>', false)
        ->assertDontSee('<div class="dz-preview dz-preview-multiple', false)
        ->assertDontSee('https://prium.github.io')
        ->getContent();

    $templateStart = strpos($content, '<template class="js-dropzone-preview-template">');
    $templateEnd = strpos($content, '</template>', $templateStart ?: 0);

    expect($templateStart)->not->toBeFalse()
        ->and($templateEnd)->not->toBeFalse();

    $templateHtml = substr($content, $templateStart, $templateEnd - $templateStart);
    $visibleUploaderHtml = substr($content, $templateEnd + strlen('</template>'));

    expect($templateHtml)
        ->toContain('assets/img/generic/image-file-2.png')
        ->toContain('data-dz-thumbnail')
        ->toContain('data-dz-remove')
        ->toContain(__('common.actions.delete'))
        ->toContain('aria-expanded="false"')
        ->not->toContain('aria-expanded="true"')
        ->not->toContain('data-popper-placement')
        ->not->toContain('style=')
        ->not->toContain('dropdown-menu dropdown-menu-end border py-2 show');

    expect($visibleUploaderHtml)
        ->not->toContain('assets/img/generic/image-file-2.png')
        ->not->toContain('data-dz-remove');
});

test('file manager breadcrumbs use dashboard file manager and real folder names only', function () {
    $actor = coreArchiveActor(['file_manager.view', 'file_manager.folders.create']);
    $root = app(ArchiveFolderService::class)->generalRoot();

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.folders.store'), ['name' => 'Contracts'])
        ->assertOk();

    $parent = ArchiveFolder::query()->where('name', 'Contracts')->firstOrFail();

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.folders.store'), [
            'parent_folder' => $parent->doc_num,
            'name' => 'Signed',
        ])
        ->assertOk();

    $child = ArchiveFolder::query()->where('name', 'Signed')->firstOrFail();

    $content = $this->actingAs($actor)
        ->get(route('admin.file-manager.folder.show', $child->doc_num))
        ->assertOk()
        ->assertSee(__('menu.dashboard'))
        ->assertSee(__('archive.file_manager'))
        ->assertSee('Contracts')
        ->assertSee('Signed')
        ->getContent();

    expect($content)
        ->not->toContain('>'.$root->name.'</a>')
        ->not->toContain('>'.$root->name.'</li>');
});

test('file manager document number settings update future archive file and folder numbers', function () {
    $actor = coreArchiveActor([
        'file_manager.view',
        'file_manager.upload',
        'file_manager.folders.create',
        'file_manager.document_number_settings.update',
    ]);

    $this->actingAs($actor)
        ->putJson(route('admin.file-manager.document-number-settings.update'), [
            'archive_files_prefix' => 'Doc-',
            'archive_files_padding' => 3,
            'archive_folders_prefix' => 'Dir-',
            'archive_folders_padding' => 2,
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', __('common.document_number_settings.updated_successfully'))
        ->assertJsonPath('data.archive_files.prefix', 'Doc-')
        ->assertJsonPath('data.archive_files.padding', 3)
        ->assertJsonPath('data.archive_folders.prefix', 'Dir-')
        ->assertJsonPath('data.archive_folders.padding', 2);

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.folders.store'), ['name' => 'Docs'])
        ->assertOk();

    $folder = ArchiveFolder::query()->where('name', 'Docs')->firstOrFail();

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.folder.files.store', $folder->doc_num), [
            'file' => UploadedFile::fake()->create('numbered.pdf', 12, 'application/pdf'),
        ])
        ->assertOk();

    $file = ArchiveFile::query()->where('original_name', 'numbered.pdf')->firstOrFail();
    $activity = Activity::query()->where('action', 'archive.document_number_settings.update')->firstOrFail();

    expect($folder->doc_num)->toStartWith('Dir-')
        ->and($file->doc_num)->toStartWith('Doc-')
        ->and($activity->properties->get('old_archive_files_prefix'))->toBe('File-')
        ->and($activity->properties->get('new_archive_files_prefix'))->toBe('Doc-')
        ->and($activity->properties->get('old_archive_folders_prefix'))->toBe('Folder-')
        ->and($activity->properties->get('new_archive_folders_prefix'))->toBe('Dir-')
        ->and($activity->properties->toArray())->not->toHaveKey('id');
});

test('file manager document number settings are permission protected', function () {
    $actor = coreArchiveActor(['file_manager.view']);

    $this->actingAs($actor)
        ->get(route('admin.file-manager.index'))
        ->assertOk()
        ->assertDontSee('js-archive-document-number-settings-form', false);

    $this->actingAs($actor)
        ->putJson(route('admin.file-manager.document-number-settings.update'), [
            'archive_files_prefix' => 'Doc-',
            'archive_files_padding' => 3,
            'archive_folders_prefix' => 'Dir-',
            'archive_folders_padding' => 2,
        ])
        ->assertForbidden();
});

test('file manager folders prevent duplicate sibling names and block non empty folder deletion', function () {
    $actor = coreArchiveActor([
        'file_manager.view',
        'file_manager.folders.create',
        'file_manager.folders.delete',
        'file_manager.upload',
    ]);

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.folders.store'), [
            'name' => 'Contracts',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $folder = ArchiveFolder::query()->where('name', 'Contracts')->firstOrFail();

    expect($folder->created_by)->toBe($actor->id)
        ->and($folder->updated_by)->toBeNull()
        ->and($folder->updated_at)->toBeNull();

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.folders.store'), [
            'name' => ' contracts ',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', __('archive.folder_already_exists'));

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.folders.store'), [
            'parent_folder' => $folder->doc_num,
            'name' => 'Contracts',
        ])
        ->assertOk();

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.folder.files.store', $folder->doc_num), [
            'file' => UploadedFile::fake()->create('proposal.pdf', 12, 'application/pdf'),
        ])
        ->assertOk();

    expect(ArchiveFile::query()->firstOrFail()->folder_doc_num)->toBe($folder->doc_num);

    $this->actingAs($actor)
        ->deleteJson(route('admin.file-manager.folders.destroy', $folder->doc_num))
        ->assertStatus(409)
        ->assertJsonPath('message', __('archive.folder_not_empty'));

    $blockedActivity = Activity::query()->where('action', 'archive.folder.delete_blocked')->firstOrFail();

    expect($blockedActivity->status)->toBe('blocked')
        ->and($blockedActivity->properties->get('folder_doc_num'))->toBe($folder->doc_num)
        ->and($blockedActivity->properties->get('reason'))->toBe('folder_not_empty')
        ->and($blockedActivity->properties->get('files_count'))->toBe(1)
        ->and($blockedActivity->properties->get('subfolders_count'))->toBe(1);

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.folders.store'), [
            'name' => 'Empty',
        ])
        ->assertOk();

    $emptyFolder = ArchiveFolder::query()->where('name', 'Empty')->firstOrFail();

    $this->actingAs($actor)
        ->deleteJson(route('admin.file-manager.folders.destroy', $emptyFolder->doc_num))
        ->assertOk();

    $deletedFolder = ArchiveFolder::withTrashed()->whereKey($emptyFolder->getKey())->firstOrFail();

    expect($deletedFolder->trashed())->toBeTrue()
        ->and($deletedFolder->deleted_by)->toBe($actor->id)
        ->and($deletedFolder->updated_by)->toBeNull()
        ->and($deletedFolder->updated_at)->toBeNull()
        ->and(Activity::query()->where('action', 'archive.folder.create')->count())->toBeGreaterThanOrEqual(3)
        ->and(Activity::query()->where('action', 'archive.folder.delete')->where('properties->folder_doc_num', $emptyFolder->doc_num)->exists())->toBeTrue();
});

test('file duplicate names are rejected only inside the same folder', function () {
    $actor = coreArchiveActor(['file_manager.view', 'file_manager.folders.create', 'file_manager.upload']);

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.folders.store'), ['name' => 'A'])
        ->assertOk();

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.folders.store'), ['name' => 'B'])
        ->assertOk();

    [$folderA, $folderB] = ArchiveFolder::query()
        ->whereIn('name', ['A', 'B'])
        ->orderBy('name')
        ->get()
        ->all();

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.folder.files.store', $folderA->doc_num), [
            'file' => UploadedFile::fake()->create('same-name.pdf', 12, 'application/pdf'),
        ])
        ->assertOk();

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.folder.files.store', $folderA->doc_num), [
            'file' => UploadedFile::fake()->create('same-name.pdf', 12, 'application/pdf'),
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', __('archive.file_already_exists_in_folder'));

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.folder.files.store', $folderB->doc_num), [
            'file' => UploadedFile::fake()->create('same-name.pdf', 12, 'application/pdf'),
        ])
        ->assertOk();
});

test('file manager moves a file and keeps product and fixed asset image paths valid', function () {
    $actor = coreArchiveActor(['file_manager.view', 'file_manager.upload', 'file_manager.folders.create', 'file_manager.move']);
    $company = coreArchiveOperatingContext($this);
    $this->actingAs($actor);

    $this->postJson(route('admin.file-manager.folders.store'), ['name' => 'Source'])
        ->assertOk();
    $this->postJson(route('admin.file-manager.folders.store'), ['name' => 'Destination'])
        ->assertOk();

    $source = ArchiveFolder::query()->where('name', 'Source')->firstOrFail();
    $destination = ArchiveFolder::query()->where('name', 'Destination')->firstOrFail();

    $this->postJson(route('admin.file-manager.folder.files.store', $source->doc_num), [
        'file' => UploadedFile::fake()->image('move-product.jpg')->size(32),
    ])->assertOk();

    $file = ArchiveFile::query()->where('original_name', 'move-product.jpg')->firstOrFail();
    $oldPath = $file->path;

    $product = Product::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 88101,
        'doc_num' => 'Product-88101',
        'name' => 'Move Product Image',
        'image_path' => $oldPath,
        'item_classification' => Product::ClassificationFinishedProduct,
        'status' => 'active',
    ]);
    app(ArchiveFileUsageService::class)->replaceFileForRecord($file, $product, Product::ImageCollection, Product::MainImageRole);

    $asset = FixedAsset::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 88101,
        'doc_num' => 'FixedAsset-88101',
        'asset_date' => '2026-06-13',
        'asset_name' => 'Move Fixed Asset Image',
        'image_path' => $oldPath,
        'status' => 'active',
    ]);
    app(ArchiveFileUsageService::class)->replaceFileForRecord($file, $asset, FixedAsset::ImageCollection, FixedAsset::MainImageRole);

    $this->postJson(route('admin.file-manager.move'), [
        'item_type' => 'file',
        'item_doc_num' => $file->doc_num,
        'destination_folder' => $destination->doc_num,
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', __('archive.item_moved_successfully'));

    $file->refresh();

    expect($file->archive_folder_id)->toBe($destination->getKey())
        ->and($file->folder_doc_num)->toBe($destination->doc_num)
        ->and($file->path)->toContain('/'.$destination->doc_num.'/')
        ->and($file->path)->not->toBe($oldPath)
        ->and(Storage::disk('local')->exists($oldPath))->toBeFalse()
        ->and(Storage::disk('local')->exists($file->path))->toBeTrue()
        ->and($product->refresh()->image_path)->toBe($file->path)
        ->and($asset->refresh()->image_path)->toBe($file->path)
        ->and(Activity::query()->where('action', 'archive.file.move')->where('properties->file_doc_num', $file->doc_num)->exists())->toBeTrue();
});

test('file manager moves folders and blocks descendant or conflicting folder moves', function () {
    $actor = coreArchiveActor(['file_manager.view', 'file_manager.folders.create', 'file_manager.move']);
    coreArchiveOperatingContext($this);
    $this->actingAs($actor);

    $this->postJson(route('admin.file-manager.folders.store'), ['name' => 'Folder A'])
        ->assertOk();
    $this->postJson(route('admin.file-manager.folders.store'), ['name' => 'Folder B'])
        ->assertOk();

    $folderA = ArchiveFolder::query()->where('name', 'Folder A')->firstOrFail();
    $folderB = ArchiveFolder::query()->where('name', 'Folder B')->firstOrFail();

    $this->postJson(route('admin.file-manager.folders.store'), [
        'parent_folder' => $folderA->doc_num,
        'name' => 'Folder A Child',
    ])->assertOk();

    $child = ArchiveFolder::query()->where('name', 'Folder A Child')->firstOrFail();

    $this->postJson(route('admin.file-manager.move'), [
        'item_type' => 'folder',
        'item_doc_num' => $folderA->doc_num,
        'destination_folder' => $folderA->doc_num,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', __('archive.invalid_folder_move'));

    $this->postJson(route('admin.file-manager.move'), [
        'item_type' => 'folder',
        'item_doc_num' => $folderA->doc_num,
        'destination_folder' => $child->doc_num,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', __('archive.invalid_folder_move'));

    $this->postJson(route('admin.file-manager.move'), [
        'item_type' => 'folder',
        'item_doc_num' => $folderA->doc_num,
        'destination_folder' => $folderB->doc_num,
    ])
        ->assertOk()
        ->assertJsonPath('message', __('archive.item_moved_successfully'));

    expect($folderA->refresh()->parent_id)->toBe($folderB->getKey())
        ->and($folderA->path_cache)->toContain('Folder B / Folder A')
        ->and($child->refresh()->path_cache)->toContain('Folder B / Folder A / Folder A Child')
        ->and(Activity::query()->where('action', 'archive.folder.move')->where('properties->folder_doc_num', $folderA->doc_num)->exists())->toBeTrue();

    $this->postJson(route('admin.file-manager.folders.store'), ['name' => 'Conflict Source'])
        ->assertOk();
    $this->postJson(route('admin.file-manager.folders.store'), [
        'parent_folder' => $folderB->doc_num,
        'name' => 'Conflict Source',
    ])->assertOk();

    $conflictSource = ArchiveFolder::query()
        ->where('name', 'Conflict Source')
        ->where('parent_id', app(ArchiveFolderService::class)->generalRoot()->getKey())
        ->firstOrFail();

    $this->postJson(route('admin.file-manager.move'), [
        'item_type' => 'folder',
        'item_doc_num' => $conflictSource->doc_num,
        'destination_folder' => $folderB->doc_num,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', __('archive.destination_item_conflict'));
});

test('file manager bulk move supports mixed files and folders', function () {
    $actor = coreArchiveActor(['file_manager.view', 'file_manager.upload', 'file_manager.folders.create', 'file_manager.move']);
    coreArchiveOperatingContext($this);
    $this->actingAs($actor);

    foreach (['Bulk Source', 'Bulk Destination'] as $name) {
        $this->postJson(route('admin.file-manager.folders.store'), ['name' => $name])
            ->assertOk();
    }

    $source = ArchiveFolder::query()->where('name', 'Bulk Source')->firstOrFail();
    $destination = ArchiveFolder::query()->where('name', 'Bulk Destination')->firstOrFail();

    $this->postJson(route('admin.file-manager.folders.store'), [
        'parent_folder' => $source->doc_num,
        'name' => 'Bulk Child',
    ])->assertOk();

    $this->postJson(route('admin.file-manager.folder.files.store', $source->doc_num), [
        'file' => UploadedFile::fake()->create('bulk-move.pdf', 12, 'application/pdf'),
    ])->assertOk();

    $child = ArchiveFolder::query()->where('name', 'Bulk Child')->firstOrFail();
    $file = ArchiveFile::query()->where('original_name', 'bulk-move.pdf')->firstOrFail();

    $this->postJson(route('admin.file-manager.bulk-move'), [
        'items' => [
            ['item_type' => 'file', 'item_doc_num' => $file->doc_num],
            ['item_type' => 'folder', 'item_doc_num' => $child->doc_num],
        ],
        'destination_folder' => $destination->doc_num,
    ])
        ->assertOk()
        ->assertJsonPath('message', __('archive.selected_items_moved_successfully'));

    expect($file->refresh()->archive_folder_id)->toBe($destination->getKey())
        ->and($child->refresh()->parent_id)->toBe($destination->getKey());
});

test('global file search finds files outside the current folder and shows the folder path', function () {
    $actor = coreArchiveActor(['file_manager.view', 'file_manager.folders.create', 'file_manager.upload', 'file_manager.view']);
    $root = app(ArchiveFolderService::class)->generalRoot();

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.folders.store'), ['name' => 'Contracts'])
        ->assertOk();

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.folders.store'), ['name' => 'Invoices'])
        ->assertOk();

    $contracts = ArchiveFolder::query()->where('name', 'Contracts')->firstOrFail();
    $invoices = ArchiveFolder::query()->where('name', 'Invoices')->firstOrFail();

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.folders.store'), [
            'parent_folder' => $invoices->doc_num,
            'name' => '2026',
        ])
        ->assertOk();

    $invoiceYear = ArchiveFolder::query()
        ->where('parent_id', $invoices->getKey())
        ->where('name', '2026')
        ->firstOrFail();

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.files.store'), [
            'file' => UploadedFile::fake()->create('root-ledger.pdf', 12, 'application/pdf'),
            'description' => 'Root searchable ledger note',
        ])
        ->assertOk();

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.folder.files.store', $contracts->doc_num), [
            'file' => UploadedFile::fake()->create('contract-alpha.pdf', 12, 'application/pdf'),
            'description' => 'Global searchable contract note',
        ])
        ->assertOk();

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.folder.files.store', $invoiceYear->doc_num), [
            'file' => UploadedFile::fake()->create('invoice-beta.pdf', 12, 'application/pdf'),
            'description' => 'Global searchable invoice note',
        ])
        ->assertOk();

    $scopedResponse = $this->actingAs($actor)
        ->getJson(route('admin.file-manager.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'folder_doc_num' => $contracts->doc_num,
            'search' => ['value' => 'invoice-beta'],
        ]))
        ->assertOk()
        ->json();

    $globalResponse = $this->actingAs($actor)
        ->getJson(route('admin.file-manager.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'folder_doc_num' => $contracts->doc_num,
            'global_search' => 1,
            'search' => ['value' => 'invoice-beta&&Global searchable invoice note'],
        ]))
        ->assertOk()
        ->json();

    $rootResponse = $this->actingAs($actor)
        ->getJson(route('admin.file-manager.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'global_search' => 1,
            'search' => ['value' => 'root-ledger&&Root searchable ledger note'],
        ]))
        ->assertOk()
        ->json();

    $rootPath = __('archive.file_manager');
    $nestedPath = __('archive.file_manager').' / Invoices / 2026';

    expect($scopedResponse['recordsFiltered'])->toBe(0)
        ->and($globalResponse['recordsFiltered'])->toBe(1)
        ->and($globalResponse['data'][0]['original_name'])->toContain('invoice-beta.pdf')
        ->and($globalResponse['data'][0]['folder_path'])->toContain('Invoices')
        ->and($globalResponse['data'][0]['folder_path'])->toContain('2026')
        ->and($globalResponse['data'][0]['folder_path'])->toContain('title="'.e($nestedPath).'"')
        ->and($globalResponse['data'][0]['folder_path'])->not->toContain($root->name)
        ->and($rootResponse['recordsFiltered'])->toBe(1)
        ->and($rootResponse['data'][0]['folder_path'])->toContain('title="'.e($rootPath).'"')
        ->and($rootResponse['data'][0]['folder_path'])->not->toContain($root->name)
        ->and($globalResponse['data'][0])->not->toHaveKey('id')
        ->and(json_encode($globalResponse['data']))->not->toContain(ArchiveFile::query()->where('original_name', 'invoice-beta.pdf')->firstOrFail()->path);
});

test('file manager public preview links are permanent guest links and revocable', function () {
    $actor = coreArchiveActor([
        'file_manager.upload',
        'file_manager.folders.create',
        'file_manager.public_links.create',
        'file_manager.public_links.view',
        'file_manager.public_links.revoke',
    ]);

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.files.store'), [
            'file' => UploadedFile::fake()->create('public-preview.pdf', 12, 'application/pdf'),
        ])
        ->assertOk();

    $file = ArchiveFile::query()->where('original_name', 'public-preview.pdf')->firstOrFail();

    $createResponse = $this->actingAs($actor)
        ->postJson(route('admin.file-manager.files.public-link.store', $file->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.exists', true)
        ->assertJsonPath('data.allow_preview', true)
        ->assertJsonPath('data.allow_download', false)
        ->assertJsonMissingPath('data.id')
        ->assertJsonMissingPath('data.path')
        ->json('data');

    $publicUrl = $createResponse['url'];
    $token = basename((string) parse_url($publicUrl, PHP_URL_PATH));
    $link = ArchivePublicLink::query()->firstOrFail();

    expect($publicUrl)->toContain('/public/archive/files/')
        ->and($token)->toHaveLength(80)
        ->and($link->linkable_doc_num)->toBe($file->doc_num)
        ->and($link->token)->toBe($token)
        ->and($link->token_hash)->not->toBe($token);

    auth()->logout();

    $this->get($publicUrl)
        ->assertOk()
        ->assertSee('public-preview.pdf')
        ->assertDontSee($file->path);

    $this->get(route('public.archive.files.preview', $token))
        ->assertOk()
        ->assertHeader('x-robots-tag', 'noindex, nofollow');

    $this->get(route('public.archive.files.download', $token))
        ->assertForbidden();

    $enableDownload = $this->actingAs($actor)
        ->postJson(route('admin.file-manager.files.public-link.store', $file->doc_num), [
            'allow_download' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.allow_download', true)
        ->json('data');

    expect($enableDownload['url'])->toBe($publicUrl)
        ->and($link->refresh()->allow_download)->toBeTrue();

    auth()->logout();

    $this->get($publicUrl)
        ->assertOk()
        ->assertSee(route('public.archive.files.download', $token), false);

    $this->get(route('public.archive.files.download', $token))
        ->assertOk();

    $disableDownload = $this->actingAs($actor)
        ->postJson(route('admin.file-manager.files.public-link.store', $file->doc_num), [
            'allow_download' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.allow_download', false)
        ->json('data');

    expect($disableDownload['url'])->toBe($publicUrl)
        ->and($link->refresh()->allow_download)->toBeFalse();

    auth()->logout();

    $this->get(route('public.archive.files.download', $token))
        ->assertForbidden();

    $link->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->get($publicUrl)->assertNotFound();

    $link->forceFill(['expires_at' => null])->save();

    $this->actingAs($actor)
        ->deleteJson(route('admin.file-manager.files.public-link.destroy', $file->doc_num))
        ->assertOk()
        ->assertJsonPath('data.exists', false);

    expect($link->refresh()->revoked_at)->not->toBeNull();

    $this->get($publicUrl)->assertNotFound();

    $activity = Activity::query()->where('action', 'archive.public_link.create')->firstOrFail();

    expect($activity->properties->get('file_doc_num'))->toBe($file->doc_num)
        ->and($activity->properties->toArray())->not->toHaveKey('token')
        ->and($activity->properties->toArray())->not->toHaveKey('path')
        ->and($activity->properties->toArray())->not->toHaveKey('archive_file_id');
});

test('folder public preview links expose only files inside the shared folder', function () {
    $actor = coreArchiveActor([
        'file_manager.upload',
        'file_manager.folders.create',
        'file_manager.public_links.create',
    ]);

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.folders.store'), ['name' => 'Shared Folder'])
        ->assertOk();

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.folders.store'), ['name' => 'Private Folder'])
        ->assertOk();

    $shared = ArchiveFolder::query()->where('name', 'Shared Folder')->firstOrFail();
    $private = ArchiveFolder::query()->where('name', 'Private Folder')->firstOrFail();

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.folder.files.store', $shared->doc_num), [
            'file' => UploadedFile::fake()->create('inside-shared.pdf', 12, 'application/pdf'),
        ])
        ->assertOk();

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.folder.files.store', $private->doc_num), [
            'file' => UploadedFile::fake()->create('outside-private.pdf', 12, 'application/pdf'),
        ])
        ->assertOk();

    $inside = ArchiveFile::query()->where('original_name', 'inside-shared.pdf')->firstOrFail();
    $outside = ArchiveFile::query()->where('original_name', 'outside-private.pdf')->firstOrFail();

    $createResponse = $this->actingAs($actor)
        ->postJson(route('admin.file-manager.folders.public-link.store', $shared->doc_num))
        ->assertOk()
        ->assertJsonPath('data.exists', true)
        ->assertJsonPath('data.item_type', 'folder')
        ->assertJsonPath('data.allow_preview', true)
        ->assertJsonPath('data.allow_download', false)
        ->json('data');

    $publicUrl = $createResponse['url'];
    $token = basename((string) parse_url($publicUrl, PHP_URL_PATH));

    auth()->logout();

    $this->get($publicUrl)
        ->assertOk()
        ->assertSee('Shared Folder')
        ->assertSee('inside-shared.pdf')
        ->assertDontSee('outside-private.pdf')
        ->assertDontSee($inside->path);

    $this->get(route('public.archive.folders.files.preview', [$token, $inside->doc_num]))
        ->assertOk();

    $this->get(route('public.archive.folders.files.preview', [$token, $outside->doc_num]))
        ->assertNotFound();

    $this->get(route('public.archive.folders.files.download', [$token, $inside->doc_num]))
        ->assertForbidden();

    $enabled = $this->actingAs($actor)
        ->postJson(route('admin.file-manager.folders.public-link.store', $shared->doc_num), [
            'allow_download' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.allow_download', true)
        ->json('data');

    expect($enabled['url'])->toBe($publicUrl);

    auth()->logout();

    $this->get($publicUrl)
        ->assertOk()
        ->assertSee(route('public.archive.folders.files.download', [$token, $inside->doc_num]), false);

    $this->get(route('public.archive.folders.files.download', [$token, $inside->doc_num]))
        ->assertOk();

    $this->get(route('public.archive.folders.files.download', [$token, $outside->doc_num]))
        ->assertNotFound();

    $disabled = $this->actingAs($actor)
        ->postJson(route('admin.file-manager.folders.public-link.store', $shared->doc_num), [
            'allow_download' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.allow_download', false)
        ->json('data');

    expect($disabled['url'])->toBe($publicUrl);

    auth()->logout();

    $this->get(route('public.archive.folders.files.download', [$token, $inside->doc_num]))
        ->assertForbidden();
});

test('bulk download uses public file doc nums and returns a zip', function () {
    if (! class_exists(ZipArchive::class)) {
        $this->markTestSkipped('ZipArchive extension is not installed.');
    }

    $actor = coreArchiveActor(['file_manager.upload', 'file_manager.download']);

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.files.store'), [
            'files' => [
                UploadedFile::fake()->create('one.pdf', 12, 'application/pdf'),
                UploadedFile::fake()->create('two.pdf', 12, 'application/pdf'),
            ],
        ])
        ->assertOk();

    $docNums = ArchiveFile::query()->pluck('doc_num')->all();

    $this->actingAs($actor)
        ->post(route('admin.file-manager.bulk-download'), [
            'file_doc_nums' => $docNums,
        ])
        ->assertOk()
        ->assertHeader('content-disposition');

    $downloadActivity = Activity::query()->where('action', 'archive.files.bulk_download')->firstOrFail();

    expect(collect($downloadActivity->properties->get('file_doc_nums'))->sort()->values()->all())->toBe(collect($docNums)->sort()->values()->all())
        ->and($downloadActivity->properties->get('count'))->toBe(2)
        ->and($downloadActivity->properties->get('total_size_bytes'))->toBeGreaterThan(0);
});

test('bulk delete soft deletes selected file doc nums and logs public identifiers only', function () {
    $actor = coreArchiveActor(['file_manager.upload', 'file_manager.delete']);

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.files.store'), [
            'files' => [
                UploadedFile::fake()->create('delete-one.pdf', 12, 'application/pdf'),
                UploadedFile::fake()->create('delete-two.pdf', 12, 'application/pdf'),
            ],
        ])
        ->assertOk();

    $docNums = ArchiveFile::query()->pluck('doc_num')->all();

    $this->actingAs($actor)
        ->call(
            'DELETE',
            route('admin.file-manager.bulk-delete'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            json_encode(['file_doc_nums' => $docNums], JSON_THROW_ON_ERROR),
        )
        ->assertOk()
        ->assertJsonMissingValidationErrors(['file_doc_num'])
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', __('archive.bulk_deleted_successfully', ['count' => 2]));

    expect(ArchiveFile::withTrashed()->whereIn('doc_num', $docNums)->whereNotNull('deleted_at')->count())->toBe(2)
        ->and(ArchiveFile::withTrashed()->whereIn('doc_num', $docNums)->where('deleted_by', $actor->id)->count())->toBe(2);

    $activity = Activity::query()->where('action', 'archive.files.bulk_delete')->firstOrFail();

    expect(collect($activity->properties->get('file_doc_nums'))->sort()->values()->all())->toBe(collect($docNums)->sort()->values()->all())
        ->and($activity->properties->get('count'))->toBe(2)
        ->and($activity->properties->toArray())->not->toHaveKey('ids')
        ->and($activity->properties->toArray())->not->toHaveKey('paths')
        ->and($activity->properties->toArray())->not->toHaveKey('archive_file_ids');
});

test('deleted archive file can be restored with audit fields cleared and stamped', function () {
    $actor = coreArchiveActor(['file_manager.upload', 'file_manager.delete', 'file_manager.restore']);

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.files.store'), [
            'file' => UploadedFile::fake()->create('restore-me.pdf', 12, 'application/pdf'),
        ])
        ->assertOk();

    $file = ArchiveFile::query()->where('original_name', 'restore-me.pdf')->firstOrFail();

    $this->deleteJson(route('admin.file-manager.files.destroy', $file->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    $deleted = ArchiveFile::withTrashed()->whereKey($file->getKey())->firstOrFail();

    expect($deleted->trashed())->toBeTrue()
        ->and($deleted->deleted_by)->toBe($actor->id)
        ->and($deleted->deleted_at)->not->toBeNull();

    $this->patchJson(route('admin.file-manager.files.restore', $file->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', __('archive.restored_successfully'));

    $restored = ArchiveFile::withTrashed()->whereKey($file->getKey())->firstOrFail();

    expect($restored->trashed())->toBeFalse()
        ->and($restored->deleted_by)->toBeNull()
        ->and($restored->deleted_at)->toBeNull()
        ->and($restored->restored_by)->toBe($actor->id)
        ->and($restored->restored_at)->not->toBeNull()
        ->and(Activity::query()->where('action', 'archive.file.restore')->where('properties->file_doc_num', $file->doc_num)->exists())->toBeTrue();
});

test('deleted archive folder can be restored with audit fields cleared and stamped', function () {
    $actor = coreArchiveActor(['file_manager.folders.create', 'file_manager.folders.delete', 'file_manager.folders.restore']);

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.folders.store'), ['name' => 'Restore Folder'])
        ->assertOk();

    $folder = ArchiveFolder::query()->where('name', 'Restore Folder')->firstOrFail();

    $this->deleteJson(route('admin.file-manager.folders.destroy', $folder->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    $deleted = ArchiveFolder::withTrashed()->whereKey($folder->getKey())->firstOrFail();

    expect($deleted->trashed())->toBeTrue()
        ->and($deleted->deleted_by)->toBe($actor->id)
        ->and($deleted->deleted_at)->not->toBeNull();

    $this->patchJson(route('admin.file-manager.folders.restore', $folder->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', __('archive.folder_restored_successfully'));

    $restored = ArchiveFolder::withTrashed()->whereKey($folder->getKey())->firstOrFail();

    expect($restored->trashed())->toBeFalse()
        ->and($restored->deleted_by)->toBeNull()
        ->and($restored->deleted_at)->toBeNull()
        ->and($restored->restored_by)->toBe($actor->id)
        ->and($restored->restored_at)->not->toBeNull()
        ->and(Activity::query()->where('action', 'archive.folder.restore')->where('properties->folder_doc_num', $folder->doc_num)->exists())->toBeTrue();
});

test('file manager trash filter returns active deleted and all records', function () {
    $actor = coreArchiveActor([
        'file_manager.view',
        'file_manager.view_trashed',
        'file_manager.restore',
        'file_manager.upload',
        'file_manager.delete',
        'file_manager.folders.create',
        'file_manager.folders.delete',
        'file_manager.folders.restore',
    ]);
    $root = app(ArchiveFolderService::class)->generalRoot();

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.folders.store'), ['name' => 'Active Folder'])
        ->assertOk();

    $this->postJson(route('admin.file-manager.folders.store'), ['name' => 'Deleted Folder'])
        ->assertOk();

    $this->postJson(route('admin.file-manager.files.store'), [
        'file' => UploadedFile::fake()->create('active-filter.pdf', 12, 'application/pdf'),
    ])->assertOk();

    $this->postJson(route('admin.file-manager.files.store'), [
        'file' => UploadedFile::fake()->create('deleted-filter.pdf', 12, 'application/pdf'),
    ])->assertOk();

    $deletedFolder = ArchiveFolder::query()->where('name', 'Deleted Folder')->firstOrFail();
    $deletedFile = ArchiveFile::query()->where('original_name', 'deleted-filter.pdf')->firstOrFail();

    $this->deleteJson(route('admin.file-manager.folders.destroy', $deletedFolder->doc_num))->assertOk();
    $this->deleteJson(route('admin.file-manager.files.destroy', $deletedFile->doc_num))->assertOk();

    $payloadFor = fn (string $trashFilter): array => $this->getJson(route('admin.file-manager.data', [
        'draw' => 1,
        'start' => 0,
        'length' => 25,
        'folder_doc_num' => $root->doc_num,
        'trash_filter' => $trashFilter,
    ]))->assertOk()->json('data');

    $activeRows = json_encode($payloadFor('active'), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $trashedRows = json_encode($payloadFor('trashed'), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $allRows = json_encode($payloadFor('all'), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    expect($activeRows)->toContain('Active Folder')
        ->and($activeRows)->toContain('active-filter.pdf')
        ->and($activeRows)->not->toContain('Deleted Folder')
        ->and($activeRows)->not->toContain('deleted-filter.pdf')
        ->and($trashedRows)->toContain('Deleted Folder')
        ->and($trashedRows)->toContain('deleted-filter.pdf')
        ->and($trashedRows)->toContain(__('archive.restore'))
        ->and($trashedRows)->not->toContain('Active Folder')
        ->and($trashedRows)->not->toContain('active-filter.pdf')
        ->and($allRows)->toContain('Active Folder')
        ->and($allRows)->toContain('Deleted Folder')
        ->and($allRows)->toContain('active-filter.pdf')
        ->and($allRows)->toContain('deleted-filter.pdf');
});

test('bulk restore restores multiple deleted files and logs public identifiers only', function () {
    $actor = coreArchiveActor(['file_manager.upload', 'file_manager.delete', 'file_manager.restore']);

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.files.store'), [
            'files' => [
                UploadedFile::fake()->create('restore-one.pdf', 12, 'application/pdf'),
                UploadedFile::fake()->create('restore-two.pdf', 12, 'application/pdf'),
            ],
        ])
        ->assertOk();

    $docNums = ArchiveFile::query()->pluck('doc_num')->all();

    $this->deleteJson(route('admin.file-manager.bulk-delete'), [
        'file_doc_nums' => $docNums,
    ])->assertOk();

    $this->patchJson(route('admin.file-manager.bulk-restore'), [
        'file_doc_nums' => $docNums,
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', __('archive.bulk_restored_successfully', ['count' => 2]));

    expect(ArchiveFile::withTrashed()->whereIn('doc_num', $docNums)->whereNull('deleted_at')->count())->toBe(2)
        ->and(ArchiveFile::query()->whereIn('doc_num', $docNums)->where('restored_by', $actor->id)->count())->toBe(2);

    $activity = Activity::query()->where('action', 'archive.files.bulk_restore')->firstOrFail();

    expect(collect($activity->properties->get('file_doc_nums'))->sort()->values()->all())->toBe(collect($docNums)->sort()->values()->all())
        ->and($activity->properties->get('count'))->toBe(2)
        ->and($activity->properties->toArray())->not->toHaveKey('ids')
        ->and($activity->properties->toArray())->not->toHaveKey('paths')
        ->and($activity->properties->toArray())->not->toHaveKey('archive_file_ids');
});

test('restore rejects active file and folder records with a clean error', function () {
    $actor = coreArchiveActor(['file_manager.upload', 'file_manager.restore', 'file_manager.folders.create', 'file_manager.folders.restore']);

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.files.store'), [
            'file' => UploadedFile::fake()->create('already-active.pdf', 12, 'application/pdf'),
        ])
        ->assertOk();

    $this->postJson(route('admin.file-manager.folders.store'), ['name' => 'Already Active Folder'])
        ->assertOk();

    $file = ArchiveFile::query()->where('original_name', 'already-active.pdf')->firstOrFail();
    $folder = ArchiveFolder::query()->where('name', 'Already Active Folder')->firstOrFail();

    $this->patchJson(route('admin.file-manager.files.restore', $file->doc_num))
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', __('archive.restore_not_allowed'));

    $this->patchJson(route('admin.file-manager.folders.restore', $folder->doc_num))
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', __('archive.restore_not_allowed'));
});

test('file restore respects operating company scope', function () {
    $actor = coreArchiveActor(['file_manager.delete', 'file_manager.restore']);
    $companyA = coreArchiveOperatingContext($this);
    $this->actingAs($actor);
    $file = coreArchiveFileForCompany($companyA, UploadedFile::fake()->create('company-restore.pdf', 12, 'application/pdf'));

    $this->deleteJson(route('admin.file-manager.files.destroy', $file->doc_num))
        ->assertOk();

    coreArchiveOperatingContext($this);

    $this->patchJson(route('admin.file-manager.files.restore', $file->doc_num))
        ->assertNotFound();

    expect(ArchiveFile::withTrashed()->whereKey($file->getKey())->firstOrFail()->trashed())->toBeTrue();
});

test('bulk delete rejects empty selected file doc nums without requiring singular file doc num', function () {
    $actor = coreArchiveActor(['file_manager.delete']);
    $previousLocale = app()->getLocale();

    app()->setLocale('ar');

    $response = $this->actingAs($actor)
        ->deleteJson(route('admin.file-manager.bulk-delete'), [
            'file_doc_nums' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file_doc_nums'])
        ->assertJsonMissingValidationErrors(['file_doc_num']);

    expect($response->json('errors.file_doc_nums.0'))->toBe('يرجى تحديد ملف واحد على الأقل.');

    app()->setLocale($previousLocale);
});

test('bulk delete cannot delete another operating company file', function () {
    $actor = coreArchiveActor(['file_manager.delete']);
    $companyA = coreArchiveOperatingContext($this);
    $this->actingAs($actor);

    $file = coreArchiveFileForCompany($companyA, UploadedFile::fake()->create('company-a-file.pdf', 12, 'application/pdf'));

    coreArchiveOperatingContext($this);

    $this->actingAs($actor)
        ->deleteJson(route('admin.file-manager.bulk-delete'), [
            'file_doc_nums' => [$file->doc_num],
        ])
        ->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', __('archive.selected_files_invalid'));

    expect($file->refresh()->trashed())->toBeFalse();
});

test('file manager bulk delete javascript sends selected file doc nums as an explicit array payload', function () {
    $script = (string) file_get_contents(public_path('assets/js/modules/Core/file-manager.js'));

    expect($script)
        ->toContain("contentType: 'application/json'")
        ->toContain('JSON.stringify({ file_doc_nums: fileDocNums })')
        ->toContain('const fileDocNums = files.map')
        ->toContain("method: 'DELETE'")
        ->toContain("method: 'PATCH'")
        ->toContain("action !== 'bulk_download' && action !== 'bulk_delete' && action !== 'bulk_restore'");
});

test('archive file delete is blocked when file is used by product main image', function () {
    Storage::fake('local');
    config()->set('archive.disk', 'local');
    $actor = coreArchiveActor(['file_manager.upload', 'file_manager.delete']);
    $company = coreArchiveOperatingContext($this);
    $this->actingAs($actor);
    $file = coreArchiveFileForCompany($company, UploadedFile::fake()->image('used-product.jpg')->size(64));
    $product = Product::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 1001,
        'doc_num' => 'Product-01001',
        'name' => 'Used File Product',
        'status' => 'active',
    ]);

    app(ArchiveFileUsageService::class)->replaceFileForRecord($file, $product, Product::ImageCollection, Product::MainImageRole);

    $this->deleteJson(route('admin.file-manager.files.destroy', $file->doc_num))
        ->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', __('archive.file_used_in', [
            'records' => __('products.singular').' / Product-01001 / Used File Product',
        ]));

    expect($file->refresh()->trashed())->toBeFalse();
});

test('file manager data shows lightweight usage badge and product summary', function () {
    Storage::fake('local');
    config()->set('archive.disk', 'local');
    $actor = coreArchiveActor(['file_manager.view', 'file_manager.upload']);
    $company = coreArchiveOperatingContext($this);
    $this->actingAs($actor);
    $root = app(ArchiveFolderService::class)->generalRoot();
    $file = coreArchiveFileForCompany($company, UploadedFile::fake()->image('usage-visible.jpg')->size(64));
    $product = Product::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 1002,
        'doc_num' => 'Product-01002',
        'name' => 'Usage Visible Product',
        'status' => 'active',
    ]);

    app(ArchiveFileUsageService::class)->replaceFileForRecord($file, $product, Product::ImageCollection, Product::MainImageRole);

    $payload = $this->getJson(route('admin.file-manager.data', [
        'draw' => 1,
        'start' => 0,
        'length' => 10,
        'folder_doc_num' => $root->doc_num,
    ]))
        ->assertOk()
        ->json();

    $row = collect($payload['data'])->first(fn (array $row): bool => str_contains((string) ($row['name'] ?? ''), 'usage-visible.jpg'));
    $encodedRow = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    expect($row)->not->toBeNull()
        ->and($row['usage'])->toContain(__('archive.used'))
        ->and($row['usage'])->toContain(__('archive.usage_count'))
        ->and($row['usage'])->toContain(__('archive.used_in'))
        ->and($row['usage'])->toContain('Product-01002')
        ->and($row['usage'])->toContain('Usage Visible Product')
        ->and($row)->not->toHaveKey('archive_file_id')
        ->and($row)->not->toHaveKey('id')
        ->and($encodedRow)->not->toContain('archive_file_id');
});

test('archive file metadata update fills updated by and logs public rename properties', function () {
    $actor = coreArchiveActor(['file_manager.upload']);

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.files.store'), [
            'file' => UploadedFile::fake()->create('old-name.pdf', 12, 'application/pdf'),
        ])
        ->assertOk();

    $file = ArchiveFile::query()->firstOrFail();

    expect($file->updated_by)->toBeNull()
        ->and($file->updated_at)->toBeNull()
        ->and($file->deleted_by)->toBeNull()
        ->and($file->deleted_at)->toBeNull();

    app(ArchiveFileService::class)->updateMetadata($file, [
        'original_name' => 'new-name.pdf',
    ]);

    $file->refresh();
    $previousUpdatedAt = $file->updated_at?->copy();
    $renameActivity = Activity::query()->where('action', 'archive.file.rename')->firstOrFail();

    expect($file->original_name)->toBe('new-name.pdf')
        ->and($file->updated_by)->toBe($actor->id)
        ->and($previousUpdatedAt)->not->toBeNull()
        ->and($renameActivity->properties->get('file_doc_num'))->toBe($file->doc_num)
        ->and($renameActivity->properties->get('old_original_name'))->toBe('old-name.pdf')
        ->and($renameActivity->properties->get('new_original_name'))->toBe('new-name.pdf')
        ->and($renameActivity->properties->get('changed_fields'))->toBe(['original_name'])
        ->and($renameActivity->properties->toArray())->not->toHaveKey('archive_file_id')
        ->and($renameActivity->properties->toArray())->not->toHaveKey('path');

    app(ArchiveFileService::class)->delete($file, log: false);

    $deleted = ArchiveFile::withTrashed()->whereKey($file->getKey())->firstOrFail();

    expect($deleted->trashed())->toBeTrue()
        ->and($deleted->deleted_by)->toBe($actor->id)
        ->and($deleted->updated_by)->toBe($actor->id)
        ->and($deleted->updated_at?->toDateTimeString())->toBe($previousUpdatedAt?->toDateTimeString());
});

test('archive upload rejects blocked types and enforces one hundred files per upload', function () {
    $actor = coreArchiveActor(['file_manager.upload']);

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.files.store'), [
            'file' => UploadedFile::fake()->create('shell.php', 1, 'text/plain'),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);

    $files = collect(range(1, 11))
        ->map(fn (int $number): UploadedFile => UploadedFile::fake()->create("file-{$number}.pdf", 1, 'application/pdf'))
        ->all();

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.files.store'), [
            'files' => $files,
        ])
        ->assertOk();

    $tooManyFiles = collect(range(1, 101))
        ->map(fn (int $number): UploadedFile => UploadedFile::fake()->create("too-many-{$number}.pdf", 1, 'application/pdf'))
        ->all();

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.files.store'), [
            'files' => $tooManyFiles,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['files'])
        ->assertJsonPath('errors.files.0', __('archive.too_many_files', ['count' => 100]));
});

test('file picker image mode returns folders and image files only', function () {
    $actor = coreArchiveActor(['file_manager.view', 'file_manager.upload', 'file_manager.folders.create']);
    $company = coreArchiveOperatingContext($this);
    $this->actingAs($actor);
    $root = app(ArchiveFolderService::class)->generalRoot();
    $folder = app(ArchiveFolderService::class)->createForCompanyScope('Design Images', $root, $company);

    app(ArchiveFileService::class)->upload(
        files: [UploadedFile::fake()->image('chair.jpg')->size(32)],
        attachable: $company,
        module: 'core',
        recordType: 'company',
        recordDocNum: $company->doc_num,
        folder: $root,
    );

    app(ArchiveFileService::class)->upload(
        files: [UploadedFile::fake()->create('manual.pdf', 16, 'application/pdf')],
        attachable: $company,
        module: 'core',
        recordType: 'company',
        recordDocNum: $company->doc_num,
        folder: $root,
    );

    $payload = $this->getJson(route('admin.file-manager.picker.items', [
        'accept' => 'image',
    ]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json('data');

    $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
    $imageFile = collect($payload['files'])->firstWhere('name', 'chair.jpg');
    $imageFileKeys = array_keys($imageFile ?? []);

    expect($imageFile)->not->toBeNull();
    expect($payload['folders'][0]['public_id'])->toBe($folder->doc_num)
        ->and($imageFileKeys)->toContain('public_id')
        ->and($imageFileKeys)->toContain('name')
        ->and($imageFileKeys)->toContain('url')
        ->and($imageFileKeys)->toContain('thumbnail_url')
        ->and($imageFileKeys)->toContain('mime_type')
        ->and($imageFileKeys)->toContain('size')
        ->and($imageFileKeys)->toContain('can_delete')
        ->and($imageFileKeys)->toContain('delete_url')
        ->and($imageFileKeys)->not->toContain('id')
        ->and($imageFileKeys)->not->toContain('path')
        ->and($imageFile['mime_type'])->toStartWith('image/')
        ->and($imageFile['thumbnail_url'])->toBe($imageFile['url'])
        ->and($encoded)->toContain('chair.jpg')
        ->and($encoded)->not->toContain('manual.pdf')
        ->and($encoded)->not->toContain('"id"')
        ->and($encoded)->not->toContain('"path"');
});

test('file picker shows folders and images created from the main file manager', function () {
    $actor = coreArchiveActor(['file_manager.view', 'file_manager.upload', 'file_manager.folders.create']);
    $company = coreArchiveOperatingContext($this);
    $this->actingAs($actor);

    $this->postJson(route('admin.file-manager.folders.store'), [
        'name' => 'Favicons',
    ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $folder = ArchiveFolder::query()->where('name', 'Favicons')->firstOrFail();

    $this->postJson(route('admin.file-manager.folder.files.store', $folder->doc_num), [
        'file' => coreArchiveSvgImage('Logo.svg'),
    ])->assertOk();

    $file = ArchiveFile::query()->where('original_name', 'Logo.svg')->firstOrFail();

    expect($folder->attachable_type)->toBe($company->getMorphClass())
        ->and($folder->attachable_id)->toBe($company->getKey())
        ->and($folder->hidden_from_picker)->toBeFalse()
        ->and($file->attachable_type)->toBe($company->getMorphClass())
        ->and($file->attachable_id)->toBe($company->getKey())
        ->and($file->hidden_from_picker)->toBeFalse();

    $rootPayload = $this->getJson(route('admin.file-manager.picker.items', [
        'accept' => 'image',
    ]))
        ->assertOk()
        ->json('data');

    $folderPayload = $this->getJson(route('admin.file-manager.picker.items', [
        'folder' => $folder->doc_num,
        'accept' => 'image',
    ]))
        ->assertOk()
        ->json('data');

    expect(json_encode($rootPayload, JSON_THROW_ON_ERROR))->toContain('Favicons')
        ->and(json_encode($folderPayload, JSON_THROW_ON_ERROR))->toContain('Logo.svg');
});

test('opening file picker uses normal root without creating a company folder', function () {
    $actor = coreArchiveActor(['file_manager.view']);
    $company = coreArchiveOperatingContext($this);
    $this->actingAs($actor);

    $companyFolderQuery = fn (): bool => ArchiveFolder::query()
        ->whereNull('parent_id')
        ->where('record_type', 'company')
        ->where('record_doc_num', $company->doc_num)
        ->where('attachable_type', $company->getMorphClass())
        ->where('attachable_id', $company->getKey())
        ->exists();

    expect($companyFolderQuery())->toBeFalse();

    $this->getJson(route('admin.file-manager.picker.items', ['accept' => 'image']))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.folder.public_id', '');

    expect($companyFolderQuery())->toBeFalse();
});

test('file picker upload stores root image as company scoped file visible in main file manager', function () {
    $actor = coreArchiveActor(['file_manager.view', 'file_manager.upload']);
    $company = coreArchiveOperatingContext($this);
    $this->actingAs($actor);

    $this->postJson(route('admin.file-manager.picker.files.store'), [
        'accept' => 'image',
        'file' => UploadedFile::fake()->image('picker-root-visible.jpg')->size(32),
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonMissingPath('data.file.id')
        ->assertJsonMissingPath('data.file.path');

    $file = ArchiveFile::query()->where('original_name', 'picker-root-visible.jpg')->firstOrFail();
    $root = app(ArchiveFolderService::class)->generalRoot();

    expect($file->archive_folder_id)->toBe($root->getKey())
        ->and($file->attachable_type)->toBe($company->getMorphClass())
        ->and($file->attachable_id)->toBe($company->getKey());

    $mainPayload = $this->getJson(route('admin.file-manager.data', [
        'draw' => 1,
        'start' => 0,
        'length' => 10,
        'folder_doc_num' => $root->doc_num,
    ]))
        ->assertOk()
        ->json('data');

    expect(json_encode($mainPayload, JSON_THROW_ON_ERROR))->toContain('picker-root-visible.jpg');
});

test('file picker delete url soft deletes file and removes it from picker and main manager lists', function () {
    $actor = coreArchiveActor(['file_manager.view', 'file_manager.delete']);
    $company = coreArchiveOperatingContext($this);
    $this->actingAs($actor);
    $file = coreArchiveFileForCompany($company, UploadedFile::fake()->image('picker-delete-me.jpg')->size(32));
    $root = app(ArchiveFolderService::class)->generalRoot();

    $pickerPayload = $this->getJson(route('admin.file-manager.picker.items', ['accept' => 'image']))
        ->assertOk()
        ->json('data');
    $pickerFile = collect($pickerPayload['files'])->firstWhere('public_id', $file->doc_num);

    expect($pickerFile)->not->toBeNull()
        ->and($pickerFile['delete_url'])->toBe(route('admin.file-manager.files.destroy', $file->doc_num));

    $this->deleteJson($pickerFile['delete_url'])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($file->refresh()->trashed())->toBeTrue();

    $afterPickerPayload = $this->getJson(route('admin.file-manager.picker.items', ['accept' => 'image']))
        ->assertOk()
        ->json('data');
    $afterMainPayload = $this->getJson(route('admin.file-manager.data', [
        'draw' => 1,
        'start' => 0,
        'length' => 10,
        'folder_doc_num' => $root->doc_num,
    ]))
        ->assertOk()
        ->json('data');

    expect(json_encode($afterPickerPayload, JSON_THROW_ON_ERROR))->not->toContain('picker-delete-me.jpg')
        ->and(json_encode($afterMainPayload, JSON_THROW_ON_ERROR))->not->toContain('picker-delete-me.jpg');
});

test('file picker upload accepts svg and bmp images', function () {
    $actor = coreArchiveActor(['file_manager.view', 'file_manager.upload']);
    coreArchiveOperatingContext($this);
    $this->actingAs($actor);

    foreach ([coreArchiveSvgImage('picker-vector.svg'), coreArchiveBmpImage('picker-bitmap.bmp')] as $file) {
        $this->postJson(route('admin.file-manager.picker.files.store'), [
            'accept' => 'image',
            'file' => $file,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('data.file.id')
            ->assertJsonMissingPath('data.file.path');
    }

    expect(ArchiveFile::query()->where('original_name', 'picker-vector.svg')->first()?->hidden_from_picker)->toBeFalse()
        ->and(ArchiveFile::query()->where('original_name', 'picker-bitmap.bmp')->first()?->hidden_from_picker)->toBeFalse()
        ->and(ArchiveFile::query()->pluck('extension')->all())->toContain('svg')
        ->and(ArchiveFile::query()->pluck('extension')->all())->toContain('bmp');
});

test('file picker hides hidden files folders and descendants while normal file manager still shows them', function () {
    $actor = coreArchiveActor(['file_manager.view', 'file_manager.upload', 'file_manager.folders.create', 'file_manager.update_picker_visibility']);
    $company = coreArchiveOperatingContext($this);
    $this->actingAs($actor);
    $root = app(ArchiveFolderService::class)->generalRoot();
    $visibleFolder = app(ArchiveFolderService::class)->createForCompanyScope('Visible Picker Folder', $root, $company);
    $hiddenFolder = app(ArchiveFolderService::class)->createForCompanyScope('Hidden Picker Folder', $root, $company);
    $nestedHiddenFolder = app(ArchiveFolderService::class)->createForCompanyScope('Nested Hidden Folder', $hiddenFolder, $company);
    $visibleFile = coreArchiveFileForCompany($company, UploadedFile::fake()->image('visible-picker.jpg')->size(32));
    $hiddenFile = coreArchiveFileForCompany($company, UploadedFile::fake()->image('hidden-picker.jpg')->size(32));

    $hiddenFolder->forceFill(['hidden_from_picker' => true])->save();
    $hiddenFile->forceFill(['hidden_from_picker' => true])->save();

    app(ArchiveFileService::class)->upload(
        files: [UploadedFile::fake()->image('descendant-hidden-picker.jpg')->size(32)],
        attachable: $company,
        module: 'core',
        recordType: 'company',
        recordDocNum: $company->doc_num,
        folder: $nestedHiddenFolder,
    );

    $pickerPayload = $this->getJson(route('admin.file-manager.picker.items', [
        'accept' => 'image',
    ]))
        ->assertOk()
        ->json('data');

    $encodedPickerPayload = json_encode($pickerPayload, JSON_THROW_ON_ERROR);

    expect($visibleFolder->hidden_from_picker)->toBeFalse()
        ->and($visibleFile->hidden_from_picker)->toBeFalse()
        ->and($encodedPickerPayload)->toContain('Visible Picker Folder')
        ->and($encodedPickerPayload)->toContain('visible-picker.jpg')
        ->and($encodedPickerPayload)->not->toContain('Hidden Picker Folder')
        ->and($encodedPickerPayload)->not->toContain('hidden-picker.jpg')
        ->and($encodedPickerPayload)->not->toContain('Nested Hidden Folder')
        ->and($encodedPickerPayload)->not->toContain('descendant-hidden-picker.jpg');

    $this->getJson(route('admin.file-manager.picker.items', [
        'folder' => $hiddenFolder->doc_num,
        'accept' => 'image',
    ]))->assertNotFound();

    $this->getJson(route('admin.file-manager.picker.items', [
        'folder' => $nestedHiddenFolder->doc_num,
        'accept' => 'image',
    ]))->assertNotFound();

    $normalRootPayload = $this->getJson(route('admin.file-manager.data', [
        'draw' => 1,
        'start' => 0,
        'length' => 25,
        'folder_doc_num' => $root->doc_num,
    ]))
        ->assertOk()
        ->json('data');

    $normalHiddenFolderPayload = $this->getJson(route('admin.file-manager.data', [
        'draw' => 1,
        'start' => 0,
        'length' => 25,
        'folder_doc_num' => $hiddenFolder->doc_num,
    ]))
        ->assertOk()
        ->json('data');

    $encodedNormalRootPayload = json_encode($normalRootPayload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    expect($encodedNormalRootPayload)->toContain('Hidden Picker Folder')
        ->and($encodedNormalRootPayload)->toContain('hidden-picker.jpg')
        ->and($encodedNormalRootPayload)->toContain(__('archive.hidden_from_picker'))
        ->and(json_encode($normalHiddenFolderPayload, JSON_THROW_ON_ERROR))->toContain('Nested Hidden Folder');
});

test('file manager picker visibility can be toggled for files and folders', function () {
    $actor = coreArchiveActor(['file_manager.view', 'file_manager.update_picker_visibility']);
    $company = coreArchiveOperatingContext($this);
    $this->actingAs($actor);
    $root = app(ArchiveFolderService::class)->generalRoot();
    $folder = app(ArchiveFolderService::class)->createForCompanyScope('Toggle Picker Folder', $root, $company);
    $file = coreArchiveFileForCompany($company, UploadedFile::fake()->image('toggle-picker.jpg')->size(32));

    $this->patchJson(route('admin.file-manager.folders.picker-visibility.update', $folder->doc_num), [
        'hidden_from_picker' => 1,
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.doc_num', $folder->doc_num)
        ->assertJsonPath('data.hidden_from_picker', true)
        ->assertJsonMissingPath('data.id');

    $this->patchJson(route('admin.file-manager.files.picker-visibility.update', $file->doc_num), [
        'hidden_from_picker' => 1,
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.doc_num', $file->doc_num)
        ->assertJsonPath('data.hidden_from_picker', true)
        ->assertJsonMissingPath('data.id');

    expect($folder->refresh()->hidden_from_picker)->toBeTrue()
        ->and($file->refresh()->hidden_from_picker)->toBeTrue();
});

test('file picker modal keeps search beside the close button', function () {
    $markup = file_get_contents(resource_path('views/components/file-picker-modal.blade.php')) ?: '';

    expect($markup)->toContain('file-picker-header-tools')
        ->and($markup)->toContain('js-file-picker-search-form')
        ->and($markup)->toContain('btn-close')
        ->and(strpos($markup, 'js-file-picker-search-form'))->toBeLessThan(strpos($markup, 'btn-close'));
});

test('file picker upload rejects non images in image mode', function () {
    $actor = coreArchiveActor(['file_manager.view', 'file_manager.upload']);
    coreArchiveOperatingContext($this);
    $this->actingAs($actor);

    $this->postJson(route('admin.file-manager.picker.files.store'), [
        'accept' => 'image',
        'file' => UploadedFile::fake()->create('manual.pdf', 16, 'application/pdf'),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);
});

test('file picker create folder is permissioned and scoped to operating company', function () {
    $creator = coreArchiveActor(['file_manager.view', 'file_manager.folders.create']);
    $companyA = coreArchiveOperatingContext($this);
    $this->actingAs($creator);
    $root = app(ArchiveFolderService::class)->generalRoot();
    $companyAFolder = app(ArchiveFolderService::class)->createForCompanyScope('Company A Images', $root, $companyA);

    $companyB = coreArchiveOperatingContext($this);

    $this->postJson(route('admin.file-manager.picker.folders.store'), [
        'parent_folder' => $companyAFolder->doc_num,
        'name' => 'Blocked Cross Company Folder',
    ])->assertNotFound();

    $this->postJson(route('admin.file-manager.picker.folders.store'), [
        'name' => 'Company B Images',
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonMissingPath('data.folder.id');

    expect(ArchiveFolder::query()
        ->where('name', 'Company B Images')
        ->where('attachable_type', $companyB->getMorphClass())
        ->where('attachable_id', $companyB->getKey())
        ->exists())->toBeTrue();

    $viewer = coreArchiveActor(['file_manager.view']);

    $this->actingAs($viewer)
        ->postJson(route('admin.file-manager.picker.folders.store'), [
            'name' => 'Forbidden Folder',
        ])
        ->assertForbidden();
});

test('file manager download and delete use only public file identifiers', function () {
    $actor = coreArchiveActor(['file_manager.upload', 'file_manager.download', 'file_manager.delete']);

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.files.store'), [
            'file' => UploadedFile::fake()->create('receipt.pdf', 12, 'application/pdf'),
        ])
        ->assertOk();

    $file = ArchiveFile::query()->firstOrFail();

    $this->actingAs($actor)
        ->get(route('admin.file-manager.files.download', $file->doc_num))
        ->assertOk()
        ->assertDontSee($file->path);

    $this->actingAs($actor)
        ->deleteJson(route('admin.file-manager.files.destroy', $file->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($file->refresh()->trashed())->toBeTrue()
        ->and($file->deleted_by)->toBe($actor->id)
        ->and(Activity::query()->where('action', 'archive.file.download')->exists())->toBeTrue()
        ->and(Activity::query()->where('action', 'archive.file.delete')->exists())->toBeTrue()
        ->and(Activity::query()->where('action', 'companies.files.delete')->exists())->toBeFalse();
});

test('archive preview logging is controlled by audit config', function () {
    $actor = coreArchiveActor(['file_manager.upload', 'file_manager.view']);

    $this->actingAs($actor)
        ->postJson(route('admin.file-manager.files.store'), [
            'file' => UploadedFile::fake()->create('preview.pdf', 12, 'application/pdf'),
        ])
        ->assertOk();

    $file = ArchiveFile::query()->firstOrFail();

    $this->actingAs($actor)
        ->get(route('admin.file-manager.files.preview', $file->doc_num))
        ->assertOk();

    expect(Activity::query()->where('action', 'archive.file.preview')->exists())->toBeFalse();

    config()->set('archive.audit.log_previews', true);

    $this->actingAs($actor)
        ->get(route('admin.file-manager.files.preview', $file->doc_num))
        ->assertOk();

    $previewActivity = Activity::query()->where('action', 'archive.file.preview')->firstOrFail();

    expect($previewActivity->properties->get('file_doc_num'))->toBe($file->doc_num)
        ->and($previewActivity->properties->toArray())->not->toHaveKey('path');
});
