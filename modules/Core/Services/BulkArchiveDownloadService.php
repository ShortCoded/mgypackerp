<?php

namespace Modules\Core\Services;

use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\Company;
use ZipArchive;

class BulkArchiveDownloadService
{
    public function __construct(
        private readonly ArchiveAuditLogger $auditLogger,
    ) {}

    /**
     * @param  list<string>  $docNums
     * @return array{path: string, name: string, file_doc_nums: list<string>, count: int, total_size_bytes: int}
     */
    public function buildZip(array $docNums, ?callable $filter = null, ?Request $request = null, ?Company $company = null): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new DomainException(__('archive.zip_not_available'));
        }

        $maxFiles = (int) config('archive.bulk_download.max_files', 100);
        $maxBytes = (int) config('archive.bulk_download.max_total_size_mib', 500) * 1024 * 1024;
        $docNums = array_values(array_unique(array_filter($docNums)));

        if ($docNums === []) {
            throw new DomainException(__('archive.no_files_selected'));
        }

        if (count($docNums) > $maxFiles) {
            throw new DomainException(__('archive.too_many_files', ['count' => $maxFiles]));
        }

        $files = ArchiveFile::query()
            ->whereIn('doc_num', $docNums)
            ->get();

        if ($filter) {
            $files = $files->filter($filter)->values();
        }

        if ($files->count() !== count($docNums)) {
            throw new DomainException(__('archive.selected_files_invalid'));
        }

        if ((int) $files->sum('size_bytes') > $maxBytes) {
            throw new DomainException(__('archive.bulk_too_large'));
        }

        $directory = storage_path('app/tmp/archive-bulk');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = $directory.'/archive-'.Str::uuid()->toString().'.zip';
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new DomainException(__('archive.zip_create_failed'));
        }

        $names = [];

        foreach ($files as $file) {
            if (! Storage::disk($file->disk)->exists($file->path)) {
                continue;
            }

            $zip->addFile(Storage::disk($file->disk)->path($file->path), $this->zipName($file->original_name, $names));
        }

        $zip->close();
        $totalSizeBytes = (int) $files->sum('size_bytes');

        $this->auditLogger->filesBulkDownloaded($files, $totalSizeBytes, $request, $company);

        return [
            'path' => $path,
            'name' => 'archive-'.now()->format('Ymd-His').'.zip',
            'file_doc_nums' => $files->pluck('doc_num')->all(),
            'count' => $files->count(),
            'total_size_bytes' => $totalSizeBytes,
        ];
    }

    /**
     * @param  array<string, int>  $seen
     */
    private function zipName(string $name, array &$seen): string
    {
        $name = trim(str_replace(["\0", '/', '\\'], '', $name)) ?: 'file';
        $key = mb_strtolower($name);

        if (! isset($seen[$key])) {
            $seen[$key] = 1;

            return $name;
        }

        $seen[$key]++;
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = $extension ? mb_substr($name, 0, -1 * (mb_strlen($extension) + 1)) : $name;

        return $base.' ('.$seen[$key].')'.($extension ? ".{$extension}" : '');
    }
}
