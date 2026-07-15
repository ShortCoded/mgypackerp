<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Support\Facades\Storage;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\Company;
use Modules\FixedAssets\Models\FixedAsset;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FixedAssetImageResolver
{
    public function url(FixedAsset $asset): ?string
    {
        if ($this->archiveFileAvailable($this->usageArchiveFile($asset))) {
            return route('admin.fixed-assets.assets.image', $asset->doc_num);
        }

        $path = trim((string) $asset->image_path);

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'archive/')) {
            if ($this->archiveFileFromImagePath($asset) instanceof ArchiveFile) {
                return route('admin.fixed-assets.assets.image', $asset->doc_num);
            }

            return null;
        }

        return Storage::disk('public')->url($path);
    }

    public function response(FixedAsset $asset): ?StreamedResponse
    {
        $usageFile = $this->usageArchiveFile($asset);

        if ($this->archiveFileAvailable($usageFile)) {
            return $this->streamArchiveFile($usageFile);
        }

        $path = trim((string) $asset->image_path);

        if ($path === '') {
            return null;
        }

        $file = $this->archiveFileFromImagePath($asset);

        if (str_starts_with($path, 'archive/') && ! $file instanceof ArchiveFile) {
            return null;
        }

        if ($file instanceof ArchiveFile) {
            return $this->streamArchiveFile($file);
        }

        if (! Storage::disk('public')->exists($path)) {
            return null;
        }

        $stream = Storage::disk('public')->readStream($path);

        if (! is_resource($stream)) {
            return null;
        }

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => Storage::disk('public')->mimeType($path) ?: 'image/*',
            'Content-Disposition' => 'inline; filename="'.addslashes(basename($path)).'"',
        ]);
    }

    public function archiveFile(FixedAsset $asset): ?ArchiveFile
    {
        return $this->usageArchiveFile($asset) ?: $this->archiveFileFromImagePath($asset);
    }

    private function archiveFileFromImagePath(FixedAsset $asset): ?ArchiveFile
    {
        $path = trim((string) $asset->image_path);

        if ($path === '' || ! str_starts_with($path, 'archive/')) {
            return null;
        }

        return ArchiveFile::query()
            ->where('path', $path)
            ->where('attachable_type', (new Company)->getMorphClass())
            ->where('attachable_id', $asset->company_id)
            ->where('mime_type', 'like', 'image/%')
            ->first();
    }

    private function usageArchiveFile(FixedAsset $asset): ?ArchiveFile
    {
        $usage = $asset->relationLoaded('mainImageUsage')
            ? $asset->getRelation('mainImageUsage')
            : $asset->mainImageUsage()->first();

        $file = $usage?->file;

        if (! $file instanceof ArchiveFile) {
            return null;
        }

        if ((string) $file->attachable_type !== (new Company)->getMorphClass() || (int) $file->attachable_id !== (int) $asset->company_id) {
            return null;
        }

        if (! str_starts_with((string) $file->mime_type, 'image/')) {
            return null;
        }

        return $file;
    }

    private function archiveFileAvailable(?ArchiveFile $file): bool
    {
        return $file instanceof ArchiveFile
            && Storage::disk($file->disk)->exists($file->path);
    }

    private function streamArchiveFile(ArchiveFile $file): ?StreamedResponse
    {
        if (! $this->archiveFileAvailable($file)) {
            return null;
        }

        $stream = Storage::disk($file->disk)->readStream($file->path);

        if (! is_resource($stream)) {
            return null;
        }

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $file->mime_type ?: 'image/*',
            'Content-Disposition' => 'inline; filename="'.addslashes($file->original_name).'"',
        ]);
    }
}
