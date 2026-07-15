<?php

namespace Modules\Core\Services;

use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\ArchiveFolder;
use Modules\Core\Models\Company;

class ArchiveFolderService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumberService,
        private readonly ArchiveAuditLogger $auditLogger,
        private readonly CrudAuditService $crudAudit,
        private readonly OperatingCompanyContextService $companyContext,
    ) {}

    public function generalRoot(): ArchiveFolder
    {
        return $this->rootFor(
            name: __('archive.root.general'),
            module: 'core',
            recordType: null,
            recordDocNum: null,
            attachable: null,
        );
    }

    public function companyRoot(Company $company): ArchiveFolder
    {
        return $this->rootFor(
            name: $company->name,
            module: 'core',
            recordType: 'company',
            recordDocNum: $company->doc_num,
            attachable: $company,
        );
    }

    public function create(string $name, ?ArchiveFolder $parent, ?ArchiveFolder $contextFolder = null, ?Request $request = null): ArchiveFolder
    {
        $name = $this->cleanName($name);
        $context = $contextFolder ?: $parent;
        $this->ensureNameIsAllowed($name);
        $this->ensureNoSiblingDuplicate($name, $parent, $context);

        $folder = DB::transaction(function () use ($name, $parent, $context): ArchiveFolder {
            $documentNumber = $this->documentNumberService->next('archive_folders', ArchiveFolder::class);
            $folder = new ArchiveFolder([
                'doc_number' => $documentNumber['doc_number'],
                'doc_num' => $documentNumber['doc_num'],
                'name' => $name,
                'slug' => Str::slug($name),
                'module' => $context?->module ?? 'core',
                'record_type' => $context?->record_type,
                'record_doc_num' => $context?->record_doc_num,
                'created_by' => auth()->id(),
            ]);

            if ($parent) {
                $folder->parent()->associate($parent);
            }

            if ($context?->attachable_type && $context->attachable_id) {
                $folder->attachable_type = $context->attachable_type;
                $folder->attachable_id = $context->attachable_id;
            }

            $folder->path_cache = $this->pathFor($folder, $parent);
            $folder->save();
            $this->crudAudit->clearCreationUpdateAudit($folder);

            return $folder->refresh();
        });

        $this->auditLogger->folderCreated($folder, $request);

        return $folder;
    }

    public function createForCompanyScope(string $name, ArchiveFolder $parent, Company $company, ?Request $request = null): ArchiveFolder
    {
        $context = new ArchiveFolder([
            'module' => 'core',
            'record_type' => 'company',
            'record_doc_num' => $company->doc_num,
            'attachable_type' => $company->getMorphClass(),
            'attachable_id' => $company->getKey(),
        ]);

        return $this->create($name, $parent, $context, $request);
    }

    public function rename(ArchiveFolder $folder, string $name, ?Request $request = null): ArchiveFolder
    {
        $name = $this->cleanName($name);
        $this->ensureNameIsAllowed($name);
        $this->ensureNoSiblingDuplicate($name, $folder->parent, $folder, $folder);
        $oldName = $folder->name;

        $this->crudAudit->saveUpdate($folder, [
            'name' => $name,
            'slug' => Str::slug($name),
            'path_cache' => $this->pathFor($folder, $folder->parent),
        ]);

        $folder = $folder->refresh();

        $this->auditLogger->folderRenamed($folder, $oldName, $request);

        return $folder;
    }

    public function updatePickerVisibility(ArchiveFolder $folder, bool $hiddenFromPicker): ArchiveFolder
    {
        if ((bool) $folder->hidden_from_picker === $hiddenFromPicker) {
            return $folder;
        }

        $this->crudAudit->saveUpdate($folder, [
            'hidden_from_picker' => $hiddenFromPicker,
        ]);

        return $folder->refresh();
    }

    public function delete(ArchiveFolder $folder, ?Request $request = null): void
    {
        $subfoldersCount = $folder->children()->count();
        $filesCount = $folder->files()->count();

        if ($subfoldersCount > 0 || $filesCount > 0) {
            $this->auditLogger->folderDeleteBlocked(
                $folder,
                'folder_not_empty',
                $filesCount,
                $subfoldersCount,
                $request,
            );

            throw new DomainException(__('archive.folder_not_empty'));
        }

        DB::transaction(function () use ($folder): void {
            $this->crudAudit->softDelete($folder);
        });

        $this->auditLogger->folderDeleted($folder, $request);
    }

    public function restore(ArchiveFolder $folder, ?Request $request = null): ArchiveFolder
    {
        $restored = DB::transaction(function () use ($folder, $request): ArchiveFolder {
            $lockedFolder = ArchiveFolder::withTrashed()
                ->with(['parent', 'attachable'])
                ->whereKey($folder->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertFolderCanBeRestored($lockedFolder, $request);
            $this->crudAudit->restore($lockedFolder, auth()->id());

            return $lockedFolder->refresh();
        });

        $this->auditLogger->folderRestored($restored, $request);

        return $restored;
    }

    /**
     * @return array{folders: Collection<int, ArchiveFolder>, files: Collection<int, ArchiveFile>}
     */
    public function contents(ArchiveFolder $folder): array
    {
        return [
            'folders' => $folder->children()->orderBy('name')->get(),
            'files' => ArchiveFile::query()
                ->with('uploadedBy:id,name')
                ->where('archive_folder_id', $folder->getKey())
                ->latest('created_at')
                ->get(),
        ];
    }

    /**
     * @return list<ArchiveFolder>
     */
    public function breadcrumb(ArchiveFolder $folder): array
    {
        $folders = [];
        $current = $folder->loadMissing('parent');

        while ($current instanceof ArchiveFolder) {
            array_unshift($folders, $current);
            $current = $current->parent;
        }

        return $folders;
    }

    private function rootFor(string $name, string $module, ?string $recordType, ?string $recordDocNum, ?Model $attachable): ArchiveFolder
    {
        $query = ArchiveFolder::query()
            ->whereNull('parent_id')
            ->where('module', $module)
            ->where('record_type', $recordType)
            ->where('record_doc_num', $recordDocNum);

        if ($attachable instanceof Model) {
            $query
                ->where('attachable_type', $attachable->getMorphClass())
                ->where('attachable_id', $attachable->getKey());
        } else {
            $query->whereNull('attachable_type')->whereNull('attachable_id');
        }

        $existing = $query->first();

        if ($existing instanceof ArchiveFolder) {
            return $existing;
        }

        return DB::transaction(function () use ($name, $module, $recordType, $recordDocNum, $attachable): ArchiveFolder {
            $documentNumber = $this->documentNumberService->next('archive_folders', ArchiveFolder::class);
            $folder = new ArchiveFolder([
                'doc_number' => $documentNumber['doc_number'],
                'doc_num' => $documentNumber['doc_num'],
                'name' => $this->cleanName($name),
                'slug' => Str::slug($name),
                'module' => $module,
                'record_type' => $recordType,
                'record_doc_num' => $recordDocNum,
                'path_cache' => $this->cleanName($name),
                'created_by' => auth()->id(),
            ]);

            if ($attachable instanceof Model) {
                $folder->attachable()->associate($attachable);
            }

            $folder->save();
            $this->crudAudit->clearCreationUpdateAudit($folder);

            return $folder->refresh();
        });
    }

    private function cleanName(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name) ?: '');
    }

    private function ensureNameIsAllowed(string $name): void
    {
        if ($name === '' || in_array($name, ['.', '..'], true) || str_contains($name, '/') || str_contains($name, '\\') || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
            throw new DomainException(__('archive.invalid_folder_name'));
        }
    }

    private function ensureNoSiblingDuplicate(string $name, ?ArchiveFolder $parent, ?ArchiveFolder $context, ?ArchiveFolder $ignore = null): void
    {
        $query = ArchiveFolder::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->where('parent_id', $parent?->getKey());

        if ($context) {
            $query
                ->where('module', $context->module)
                ->where('record_type', $context->record_type)
                ->where('record_doc_num', $context->record_doc_num);
        }

        if ($ignore) {
            $query->whereKeyNot($ignore->getKey());
        }

        if ($query->exists()) {
            throw new DomainException(__('archive.folder_already_exists'));
        }
    }

    private function assertFolderCanBeRestored(ArchiveFolder $folder, ?Request $request = null): void
    {
        $this->assertFolderBelongsToCurrentCompanyScope($folder, $request);

        if (! $folder->trashed()) {
            throw new DomainException(__('archive.restore_not_allowed'));
        }

        if ($this->hasActiveFolderConflict($folder)) {
            throw new DomainException(__('archive.restore_conflict'));
        }
    }

    private function assertFolderBelongsToCurrentCompanyScope(ArchiveFolder $folder, ?Request $request = null): void
    {
        $company = $this->companyContext->currentCompany($request);

        if (! $company instanceof Company) {
            return;
        }

        $folderCompanyId = $this->folderCompanyId($folder);

        abort_if($folderCompanyId !== null && $folderCompanyId !== (int) $company->getKey(), 404);
    }

    private function hasActiveFolderConflict(ArchiveFolder $folder): bool
    {
        if ($folder->doc_num !== null && ArchiveFolder::query()
            ->where('doc_num', $folder->doc_num)
            ->whereKeyNot($folder->getKey())
            ->exists()) {
            return true;
        }

        if ($folder->doc_number !== null && ArchiveFolder::query()
            ->where('doc_number', $folder->doc_number)
            ->whereKeyNot($folder->getKey())
            ->exists()) {
            return true;
        }

        $name = trim((string) $folder->name);

        return $name !== '' && ArchiveFolder::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->where('parent_id', $folder->parent_id)
            ->where('module', $folder->module)
            ->where('record_type', $folder->record_type)
            ->where('record_doc_num', $folder->record_doc_num)
            ->whereKeyNot($folder->getKey())
            ->exists();
    }

    private function folderCompanyId(ArchiveFolder $folder): ?int
    {
        if ((string) $folder->attachable_type !== (new Company)->getMorphClass()) {
            return null;
        }

        return $folder->attachable_id === null ? null : (int) $folder->attachable_id;
    }

    private function pathFor(ArchiveFolder $folder, ?ArchiveFolder $parent): string
    {
        return trim(($parent?->path_cache ? $parent->path_cache.' / ' : '').$folder->name);
    }
}
