<?php

namespace Modules\HR\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Services\OperatingCompanyContextService;

class StoreAttendanceImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hr.employee_attendance.import');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId($this);

        return [
            'biometric_device_doc_num' => [
                'required',
                'string',
                Rule::exists('hr_biometric_devices', 'doc_num')
                    ->where('company_id', $companyId ?? 0)
                    ->where('status', 'active')
                    ->whereNull('deleted_at'),
            ],
            'workbook' => [
                'required',
                'file',
                'extensions:xlsx',
                'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/x-zip-compressed',
                'max:'.(int) config('excel_imports.max_file_size_kb'),
            ],
        ];
    }
}
