<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Services\FilePickerService;
use Modules\Core\Services\OperatingCompanyContextService;

class UpdatePwaSettingsRequest extends FormRequest
{
    /**
     * @var list<string>
     */
    private const IconFileFields = [
        'icon_192_archive_file_doc_num',
        'icon_512_archive_file_doc_num',
        'icon_maskable_archive_file_doc_num',
        'apple_touch_icon_archive_file_doc_num',
    ];

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('settings.pwa.update');
    }

    protected function prepareForValidation(): void
    {
        $payload = ['enabled' => $this->boolean('enabled')];

        foreach (['orientation', 'direction', 'offline_title', 'offline_message'] as $field) {
            if ($this->has($field)) {
                $payload[$field] = trim((string) $this->input($field)) ?: null;
            }
        }

        foreach (self::IconFileFields as $field) {
            if ($this->has($field)) {
                $payload[$field] = trim((string) $this->input($field)) ?: null;
            }
        }

        $this->merge($payload);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'app_name' => ['required', 'string', 'max:255'],
            'short_name' => ['required', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:500'],
            'display' => ['required', 'string', Rule::in(['standalone', 'fullscreen', 'minimal-ui', 'browser'])],
            'orientation' => ['nullable', 'string', Rule::in(['any', 'portrait', 'landscape'])],
            'direction' => ['nullable', 'string', Rule::in(['auto', 'rtl', 'ltr'])],
            'offline_title' => ['nullable', 'string', 'max:120'],
            'offline_message' => ['nullable', 'string', 'max:500'],
            'icon_192_archive_file_doc_num' => ['nullable', 'string', 'max:255'],
            'icon_512_archive_file_doc_num' => ['nullable', 'string', 'max:255'],
            'icon_maskable_archive_file_doc_num' => ['nullable', 'string', 'max:255'],
            'apple_touch_icon_archive_file_doc_num' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateSelectedIconFiles($validator);
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'enabled' => __('pwa.fields.enabled'),
            'app_name' => __('pwa.fields.app_name'),
            'short_name' => __('pwa.fields.short_name'),
            'description' => __('pwa.fields.description'),
            'display' => __('pwa.fields.display'),
            'orientation' => __('pwa.fields.orientation'),
            'direction' => __('pwa.fields.direction'),
            'offline_title' => __('pwa.fields.offline_title'),
            'offline_message' => __('pwa.fields.offline_message'),
            'icon_192_archive_file_doc_num' => __('pwa.fields.icon_192'),
            'icon_512_archive_file_doc_num' => __('pwa.fields.icon_512'),
            'icon_maskable_archive_file_doc_num' => __('pwa.fields.icon_maskable'),
            'apple_touch_icon_archive_file_doc_num' => __('pwa.fields.apple_touch_icon'),
        ];
    }

    private function validateSelectedIconFiles(Validator $validator): void
    {
        foreach (self::IconFileFields as $field) {
            $publicId = trim((string) $this->input($field));

            if ($publicId === '') {
                continue;
            }

            if (! $this->user()?->can('file_manager.view')) {
                $validator->errors()->add($field, __('pwa.validation.selected_file_unavailable'));

                continue;
            }

            $companyId = app(OperatingCompanyContextService::class)->currentCompanyId($this);

            if ($companyId === null) {
                $validator->errors()->add($field, __('pwa.validation.selected_file_unavailable'));

                continue;
            }

            $files = app(FilePickerService::class);
            $file = $files->fileForCompany($publicId, $companyId);

            if (! $file instanceof ArchiveFile || ! $files->isAvailableFile($file)) {
                $validator->errors()->add($field, __('pwa.validation.selected_file_unavailable'));

                continue;
            }

            if ($files->fileHiddenFromPicker($file)) {
                $validator->errors()->add($field, __('pwa.validation.selected_file_hidden_from_picker'));

                continue;
            }

            if (! $files->isImageFile($file)) {
                $validator->errors()->add($field, __('pwa.validation.selected_file_not_image'));
            }
        }
    }
}
