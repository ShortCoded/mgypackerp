<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Services\FilePickerService;

class StoreFilePickerUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('file_manager.upload');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'accept' => trim((string) $this->input('accept')) ?: FilePickerService::AcceptImage,
            'folder' => trim((string) $this->input('folder')) ?: null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $accept = (string) $this->input('accept');
        $imageExtensions = implode(',', config('archive.logo.allowed_extensions', ['jpg', 'jpeg', 'png', 'webp']));
        $imageMimeTypes = implode(',', config('archive.logo.allowed_mime_types', ['image/jpeg', 'image/png', 'image/webp']));
        $documentExtensions = implode(',', config('archive.documents.allowed_extensions', config('archive.allowed_extensions', [])));
        $imageMaxFileSize = (int) config('archive.logo.max_file_size_kib', 2048);
        $documentMaxFileSize = (int) config('archive.uploads.max_file_size_kib', 51200);
        $fileRules = $accept === FilePickerService::AcceptDocument
            ? ['required', 'file', "extensions:{$documentExtensions}", "max:{$documentMaxFileSize}"]
            : ['required', 'file', 'image:allow_svg', "mimetypes:{$imageMimeTypes}", "mimes:{$imageExtensions}", "extensions:{$imageExtensions}", "max:{$imageMaxFileSize}"];

        return [
            'accept' => ['nullable', 'string', Rule::in([FilePickerService::AcceptImage, FilePickerService::AcceptDocument])],
            'folder' => ['nullable', 'string'],
            'file' => $fileRules,
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ((string) $this->input('accept') !== FilePickerService::AcceptDocument) {
                return;
            }

            $file = $this->file('file');

            if (! $file instanceof UploadedFile) {
                return;
            }

            $extension = mb_strtolower($file->getClientOriginalExtension());

            if (in_array($extension, config('archive.documents.blocked_extensions', config('archive.blocked_extensions', [])), true)) {
                $validator->errors()->add('file', __('archive.invalid_file_type'));
            }
        });
    }

    public function uploadedFile(): UploadedFile
    {
        $file = $this->file('file');

        abort_unless($file instanceof UploadedFile, 422);

        return $file;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $isDocument = (string) $this->input('accept') === FilePickerService::AcceptDocument;

        return [
            'file' => $isDocument ? __('archive.file_name') : __('products.attributes.image'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $isDocument = (string) $this->input('accept') === FilePickerService::AcceptDocument;

        return [
            'file.required' => $isDocument ? __('archive.validation.files_required') : __('archive.picker.image_required'),
            'file.image' => __('archive.picker.selected_file_not_image'),
            'file.mimetypes' => __('archive.picker.selected_file_not_image'),
            'file.mimes' => __('archive.picker.selected_file_not_image'),
            'file.extensions' => $isDocument
                ? __('archive.invalid_file_type')
                : __('archive.picker.selected_file_not_image'),
            'file.max' => $isDocument
                ? __('archive.file_too_large', ['size' => (int) config('archive.uploads.max_file_size_mib', 50)])
                : __('products.validation.image_too_large', ['size' => (int) config('archive.logo.max_file_size_mib', 2)]),
        ];
    }
}
