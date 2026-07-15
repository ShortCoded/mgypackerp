<?php

namespace Modules\Core\Services;

class ArchiveDocumentNumberSettingsService
{
    public const ArchiveFilesPrefixKey = 'document_numbers.archive_files.prefix';

    public const ArchiveFilesPaddingKey = 'document_numbers.archive_files.padding';

    public const ArchiveFoldersPrefixKey = 'document_numbers.archive_folders.prefix';

    public const ArchiveFoldersPaddingKey = 'document_numbers.archive_folders.padding';

    public function __construct(
        private readonly SettingService $settings,
    ) {}

    /**
     * @return array{archive_files: array{prefix: string, padding: int}, archive_folders: array{prefix: string, padding: int}}
     */
    public function current(): array
    {
        $values = $this->settings->many([
            self::ArchiveFilesPrefixKey,
            self::ArchiveFilesPaddingKey,
            self::ArchiveFoldersPrefixKey,
            self::ArchiveFoldersPaddingKey,
            SettingService::DateFormatKey,
            SettingService::DateTimeFormatKey,
        ], [
            self::ArchiveFilesPrefixKey => config('document_numbers.archive_files.prefix', ''),
            self::ArchiveFilesPaddingKey => config('document_numbers.archive_files.padding', 0),
            self::ArchiveFoldersPrefixKey => config('document_numbers.archive_folders.prefix', ''),
            self::ArchiveFoldersPaddingKey => config('document_numbers.archive_folders.padding', 0),
            SettingService::DateFormatKey => SettingService::DefaultDateFormat,
            SettingService::DateTimeFormatKey => SettingService::DefaultDateTimeFormat,
        ]);

        return [
            'archive_files' => [
                'prefix' => (string) $values[self::ArchiveFilesPrefixKey],
                'padding' => max(0, (int) $values[self::ArchiveFilesPaddingKey]),
            ],
            'archive_folders' => [
                'prefix' => (string) $values[self::ArchiveFoldersPrefixKey],
                'padding' => max(0, (int) $values[self::ArchiveFoldersPaddingKey]),
            ],
        ];
    }

    /**
     * @return array{old: array{archive_files: array{prefix: string, padding: int}, archive_folders: array{prefix: string, padding: int}}, new: array{archive_files: array{prefix: string, padding: int}, archive_folders: array{prefix: string, padding: int}}}
     */
    public function update(?string $archiveFilesPrefix, int $archiveFilesPadding, ?string $archiveFoldersPrefix, int $archiveFoldersPadding): array
    {
        $old = $this->current();
        $new = [
            'archive_files' => [
                'prefix' => trim((string) $archiveFilesPrefix),
                'padding' => max(0, $archiveFilesPadding),
            ],
            'archive_folders' => [
                'prefix' => trim((string) $archiveFoldersPrefix),
                'padding' => max(0, $archiveFoldersPadding),
            ],
        ];

        $this->settings->set(self::ArchiveFilesPrefixKey, $new['archive_files']['prefix']);
        $this->settings->set(self::ArchiveFilesPaddingKey, $new['archive_files']['padding']);
        $this->settings->set(self::ArchiveFoldersPrefixKey, $new['archive_folders']['prefix']);
        $this->settings->set(self::ArchiveFoldersPaddingKey, $new['archive_folders']['padding']);

        return [
            'old' => $old,
            'new' => $new,
        ];
    }
}
