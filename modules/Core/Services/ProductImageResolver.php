<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\Storage;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\Company;
use Modules\Core\Models\Product;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductImageResolver
{
    public function url(Product $product): ?string
    {
        if ($this->archiveFileAvailable($this->usageArchiveFile($product))) {
            return route('admin.products.image', $product->doc_num);
        }

        $path = trim((string) $product->image_path);

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'archive/')) {
            if ($this->archiveFileFromImagePath($product) instanceof ArchiveFile) {
                return route('admin.products.image', $product->doc_num);
            }

            return null;
        }

        return Storage::disk('public')->exists($path)
            ? Storage::disk('public')->url($path)
            : null;
    }

    public function response(Product $product): ?StreamedResponse
    {
        $usageFile = $this->usageArchiveFile($product);

        if ($this->archiveFileAvailable($usageFile)) {
            return $this->streamArchiveFile($usageFile);
        }

        $path = trim((string) $product->image_path);

        if ($path === '') {
            return null;
        }

        $file = $this->archiveFileFromImagePath($product);

        if (str_starts_with($path, 'archive/') && ! $file instanceof ArchiveFile) {
            return null;
        }

        if ($file instanceof ArchiveFile) {
            return $this->streamArchiveFile($file);
        }

        $disk = 'public';
        $storagePath = $path;
        $name = basename($path);
        $mimeType = Storage::disk($disk)->mimeType($storagePath) ?: 'image/*';

        if (! Storage::disk($disk)->exists($storagePath)) {
            return null;
        }

        $stream = Storage::disk($disk)->readStream($storagePath);

        if (! is_resource($stream)) {
            return null;
        }

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="'.addslashes($name).'"',
        ]);
    }

    public function archiveFile(Product $product): ?ArchiveFile
    {
        return $this->usageArchiveFile($product) ?: $this->archiveFileFromImagePath($product);
    }

    private function archiveFileFromImagePath(Product $product): ?ArchiveFile
    {
        $path = trim((string) $product->image_path);

        if ($path === '' || ! str_starts_with($path, 'archive/')) {
            return null;
        }

        return ArchiveFile::query()
            ->where('path', $path)
            ->where('attachable_type', (new Company)->getMorphClass())
            ->where('attachable_id', $product->company_id)
            ->where('mime_type', 'like', 'image/%')
            ->first();
    }

    private function usageArchiveFile(Product $product): ?ArchiveFile
    {
        $usage = $product->relationLoaded('mainImageUsage')
            ? $product->getRelation('mainImageUsage')
            : $product->mainImageUsage()->first();

        $file = $usage?->file;

        if (! $file instanceof ArchiveFile) {
            return null;
        }

        if ((string) $file->attachable_type !== (new Company)->getMorphClass() || (int) $file->attachable_id !== (int) $product->company_id) {
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
