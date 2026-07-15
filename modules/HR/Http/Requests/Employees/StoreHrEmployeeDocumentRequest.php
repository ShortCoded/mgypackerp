<?php

namespace Modules\HR\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\FilePickerService;
use Modules\Core\Services\OperatingCompanyContextService;

class StoreHrEmployeeDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hr.employees.documents.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'document_type_doc_num' => ['required', 'string', Rule::exists('hr_document_types', 'doc_num')->whereNull('deleted_at')],
            'document_type' => ['nullable', 'string', Rule::in(['national_id', 'birth_certificate', 'qualification', 'military_service', 'work_permit', 'insurance', 'contract', 'medical', 'other'])],
            'document_number_text' => ['nullable', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:255'],
            'archive_file_doc_num' => ['required', 'string'],
            'file_label' => ['nullable', 'string', 'max:80'],
            'issue_date' => ['nullable', 'date_format:Y-m-d'],
            'expires_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'alert_before_expiry_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'notes' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $dates = [];
        $dateFormat = app(DateFormatService::class);

        foreach (['issue_date', 'expires_at'] as $field) {
            $value = $this->input($field);

            if ($value === null || trim((string) $value) === '') {
                continue;
            }

            $normalized = $dateFormat->normalizeForStorage((string) $value);

            if ($normalized !== null) {
                $dates[$field] = $normalized;
            }
        }

        if ($dates !== []) {
            $this->merge($dates);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('archive_file_doc_num')) {
                return;
            }

            $companyId = app(OperatingCompanyContextService::class)->currentCompanyId($this);
            $file = $companyId
                ? app(FilePickerService::class)->selectableFileByPublicId((string) $this->input('archive_file_doc_num'), $companyId, FilePickerService::AcceptDocument)
                : null;

            if (! $file) {
                $validator->errors()->add('archive_file_doc_num', __('hr.employees.validation.selected_file_type_not_allowed'));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('hr.employees.documents.attributes');
    }
}
