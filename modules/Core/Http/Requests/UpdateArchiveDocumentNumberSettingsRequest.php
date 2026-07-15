<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateArchiveDocumentNumberSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('file_manager.document_number_settings.update');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'archive_files_prefix' => ['nullable', 'string', 'max:20'],
            'archive_files_padding' => ['required', 'integer', 'min:0', 'max:10'],
            'archive_folders_prefix' => ['nullable', 'string', 'max:20'],
            'archive_folders_padding' => ['required', 'integer', 'min:0', 'max:10'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'archive_files_prefix' => __('archive.document_number_settings.archive_files_prefix'),
            'archive_files_padding' => __('archive.document_number_settings.archive_files_padding'),
            'archive_folders_prefix' => __('archive.document_number_settings.archive_folders_prefix'),
            'archive_folders_padding' => __('archive.document_number_settings.archive_folders_padding'),
        ];
    }
}
