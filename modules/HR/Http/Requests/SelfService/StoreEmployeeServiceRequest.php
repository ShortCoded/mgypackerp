<?php

namespace Modules\HR\Http\Requests\SelfService;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HR\Models\HrEmployeeServiceRequest;

class StoreEmployeeServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $payload = $this->input('payload');

        if (is_array($payload) && filled($payload['leave_type'] ?? null)) {
            $payload['leave_type'] = strtoupper(trim((string) $payload['leave_type']));
            $this->merge(['payload' => $payload]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'request_type' => ['required', 'string', Rule::in(HrEmployeeServiceRequest::types())],
            'subject' => ['nullable', 'string', 'max:255'],
            'details' => ['required', 'string', 'max:5000'],
            'requested_from' => [Rule::requiredIf(fn (): bool => in_array($this->input('request_type'), ['leave', 'attendance_adjustment', 'overtime', 'remote_work'], true)), 'nullable', 'date'],
            'requested_to' => [Rule::requiredIf(fn (): bool => in_array($this->input('request_type'), ['leave', 'remote_work'], true)), 'nullable', 'date', 'after_or_equal:requested_from'],
            'requested_minutes' => [Rule::requiredIf(fn (): bool => $this->input('request_type') === 'overtime'), 'nullable', 'integer', 'min:1', 'max:1440'],
            'amount' => [Rule::requiredIf(fn (): bool => $this->input('request_type') === 'salary_advance'), 'nullable', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'currency_doc_num' => [
                Rule::requiredIf(fn (): bool => $this->input('request_type') === 'salary_advance'),
                'nullable',
                'string',
                Rule::exists('currencies', 'doc_num')
                    ->where('company_id', $this->user()?->hrEmployee?->company_id)
                    ->whereNull('deleted_at'),
            ],
            'payload' => ['nullable', 'array:leave_type,requested_check_in,requested_check_out,asset_type,letter_language,profile_field,profile_value'],
            'payload.leave_type' => [
                Rule::requiredIf(fn (): bool => $this->input('request_type') === 'leave'),
                'nullable',
                'string',
                'max:100',
                Rule::exists('hr_leave_types', 'code')->where('status', 'active')->whereNull('deleted_at'),
            ],
            'payload.requested_check_in' => [Rule::requiredIf(fn (): bool => $this->input('request_type') === 'attendance_adjustment'), 'nullable', 'date'],
            'payload.requested_check_out' => [Rule::requiredIf(fn (): bool => $this->input('request_type') === 'attendance_adjustment'), 'nullable', 'date', 'after_or_equal:payload.requested_check_in'],
            'payload.asset_type' => [Rule::requiredIf(fn (): bool => $this->input('request_type') === 'device_asset'), 'nullable', 'string', 'max:100'],
            'payload.letter_language' => [Rule::requiredIf(fn (): bool => $this->input('request_type') === 'employment_letter'), 'nullable', 'string', Rule::in(['ar', 'en'])],
            'payload.profile_field' => [Rule::requiredIf(fn (): bool => $this->input('request_type') === 'profile_update'), 'nullable', 'string', 'max:100'],
            'payload.profile_value' => [Rule::requiredIf(fn (): bool => $this->input('request_type') === 'profile_update'), 'nullable', 'string', 'max:1000'],
        ];
    }
}
