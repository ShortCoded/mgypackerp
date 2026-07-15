<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\ArchiveFolder;
use Modules\Core\Models\ArchivePublicLink;
use Modules\Core\Services\ArchiveFolderService;
use Modules\Core\Services\ArchivePublicLinkService;

class PublicArchiveController extends Controller
{
    public function __construct(
        private readonly ArchivePublicLinkService $publicLinks,
        private readonly ArchiveFolderService $archiveFolders,
    ) {}

    public function file(string $token): View
    {
        $link = $this->fileLink($token);
        $file = $this->fileFromLink($link);

        abort_unless($link->allow_preview, 403);

        return view('modules.core.archive.public-file', [
            'file' => $file,
            'previewUrl' => $file->isPreviewable() ? route('public.archive.files.preview', $token) : null,
            'downloadUrl' => $link->allow_download ? route('public.archive.files.download', $token) : null,
        ]);
    }

    public function previewFile(string $token)
    {
        $link = $this->fileLink($token);
        $file = $this->fileFromLink($link);

        abort_unless($link->allow_preview, 403);

        return $this->previewResponse($file);
    }

    public function downloadFile(string $token)
    {
        $link = $this->fileLink($token);
        $file = $this->fileFromLink($link);

        abort_unless($link->allow_download, 403);
        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        return Storage::disk($file->disk)->download($file->path, $file->original_name);
    }

    public function folder(Request $request, string $token): View
    {
        $link = $this->folderLink($token);
        $root = $this->folderFromLink($link);

        abort_unless($link->allow_preview, 403);

        $current = $this->currentFolder($request, $root);
        $contents = $this->archiveFolders->contents($current);

        return view('modules.core.archive.public-folder', [
            'link' => $link,
            'token' => $token,
            'rootFolder' => $root,
            'currentFolder' => $current,
            'folderBreadcrumbs' => $this->publicBreadcrumb($root, $current),
            'folders' => $contents['folders'],
            'files' => $contents['files'],
            'allowDownload' => $link->allow_download,
        ]);
    }

    public function previewFolderFile(string $token, ArchiveFile $file)
    {
        $link = $this->folderLink($token);
        $root = $this->folderFromLink($link);

        abort_unless($link->allow_preview, 403);
        abort_unless($this->fileIsInsideFolder($file, $root), 404);

        return $this->previewResponse($file);
    }

    public function downloadFolderFile(string $token, ArchiveFile $file)
    {
        $link = $this->folderLink($token);
        $root = $this->folderFromLink($link);

        abort_unless($link->allow_download, 403);
        abort_unless($this->fileIsInsideFolder($file, $root), 404);
        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        return Storage::disk($file->disk)->download($file->path, $file->original_name);
    }

    private function fileLink(string $token): ArchivePublicLink
    {
        $link = $this->publicLinks->resolve($token);

        abort_unless($link instanceof ArchivePublicLink && $link->item_type === 'file', 404);

        return $link;
    }

    private function folderLink(string $token): ArchivePublicLink
    {
        $link = $this->publicLinks->resolve($token);

        abort_unless($link instanceof ArchivePublicLink && $link->item_type === 'folder', 404);

        return $link;
    }

    private function fileFromLink(ArchivePublicLink $link): ArchiveFile
    {
        $file = $link->linkable;

        abort_unless($file instanceof ArchiveFile, 404);

        return $file;
    }

    private function folderFromLink(ArchivePublicLink $link): ArchiveFolder
    {
        $folder = $link->linkable;

        abort_unless($folder instanceof ArchiveFolder, 404);

        return $folder;
    }

    private function currentFolder(Request $request, ArchiveFolder $root): ArchiveFolder
    {
        $docNum = $request->string('folder')->trim()->toString();

        if ($docNum === '') {
            return $root;
        }

        $folder = ArchiveFolder::query()->where('doc_num', $docNum)->firstOrFail();

        abort_unless($this->folderIsInsideFolder($folder, $root), 404);

        return $folder;
    }

    /**
     * @return list<ArchiveFolder>
     */
    private function publicBreadcrumb(ArchiveFolder $root, ArchiveFolder $current): array
    {
        $folders = $this->archiveFolders->breadcrumb($current);
        $rootIndex = collect($folders)->search(fn (ArchiveFolder $folder): bool => $folder->is($root));

        if ($rootIndex === false) {
            return [$current];
        }

        return array_values(array_slice($folders, (int) $rootIndex));
    }

    private function fileIsInsideFolder(ArchiveFile $file, ArchiveFolder $root): bool
    {
        $folder = $file->folder;

        return $folder instanceof ArchiveFolder && $this->folderIsInsideFolder($folder, $root);
    }

    private function folderIsInsideFolder(ArchiveFolder $folder, ArchiveFolder $root): bool
    {
        $current = $folder;

        while ($current instanceof ArchiveFolder) {
            if ($current->is($root)) {
                return true;
            }

            $current = $current->parent;
        }

        return false;
    }

    private function previewResponse(ArchiveFile $file)
    {
        abort_unless($file->isPreviewable(), 415, __('archive.not_previewable'));
        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        return response()->file(Storage::disk($file->disk)->path($file->path), [
            'Content-Type' => $file->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.addslashes($file->original_name).'"',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
