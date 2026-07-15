<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

class StoreArchiveFilesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('file_manager.upload');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxFiles = (int) config('archive.uploads.max_files', 100);
        $maxFileSize = (int) config('archive.uploads.max_file_size_kib', 51200);
        $extensions = implode(',', config('archive.documents.allowed_extensions', config('archive.allowed_extensions', [])));

        return [
            'file' => ['nullable', 'file', "max:{$maxFileSize}", "extensions:{$extensions}"],
            'files' => ['nullable', 'array', "max:{$maxFiles}"],
            'files.*' => ['required', 'file', "max:{$maxFileSize}", "extensions:{$extensions}"],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $files = $this->archiveFiles();

            if ($files === []) {
                $validator->errors()->add('files', __('archive.validation.files_required'));

                return;
            }

            if (count($files) > (int) config('archive.uploads.max_files', 100)) {
                $validator->errors()->add('files', __('archive.too_many_files', [
                    'count' => (int) config('archive.uploads.max_files', 100),
                ]));
            }

            foreach ($files as $index => $file) {
                $extension = mb_strtolower($file->getClientOriginalExtension());

                if (in_array($extension, config('archive.documents.blocked_extensions', config('archive.blocked_extensions', [])), true)) {
                    $validator->errors()->add($this->file('file') instanceof UploadedFile && $index === 0 ? 'file' : "files.{$index}", __('archive.invalid_file_type'));
                }
            }
        });
    }

    /**
     * @return list<UploadedFile>
     */
    public function archiveFiles(): array
    {
        $files = [];

        if ($this->file('file') instanceof UploadedFile) {
            $files[] = $this->file('file');
        }

        foreach ($this->file('files', []) as $file) {
            if ($file instanceof UploadedFile) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'file' => __('archive.file_name'),
            'files' => __('archive.files'),
            'files.*' => __('archive.file_name'),
            'title' => __('archive.title_field'),
            'description' => __('archive.description'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.max' => __('archive.file_too_large', ['size' => (int) config('archive.uploads.max_file_size_mib', 50)]),
            'file.extensions' => __('archive.invalid_file_type'),
            'files.max' => __('archive.too_many_files', ['count' => (int) config('archive.uploads.max_files', 100)]),
            'files.*.max' => __('archive.file_too_large', ['size' => (int) config('archive.uploads.max_file_size_mib', 50)]),
            'files.*.extensions' => __('archive.invalid_file_type'),
        ];
    }
}
