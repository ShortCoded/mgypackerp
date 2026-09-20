<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HR\Models\HrLeaveType;

class StoreHrLeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = $this->route('leaveType') instanceof HrLeaveType
            ? 'hr.leave_types.update'
            : 'hr.leave_types.create';

        return (bool) $this->user()?->can($permission);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $leaveType = $this->route('leaveType');

        return [
            'code' => [
                'required',
                'string',
                'max:80',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('hr_leave_types', 'code')->ignore($leaveType instanceof HrLeaveType ? $leaveType->getKey() : null),
            ],
            'name' => ['required', 'string', 'max:255'],
            'payment_status' => ['required', 'string', Rule::in(['paid', 'unpaid'])],
            'requires_balance' => ['nullable', 'boolean'],
            'annual_entitlement_days' => ['nullable', 'numeric', 'min:0', 'max:366'],
            'carry_forward_max_days' => ['nullable', 'numeric', 'min:0', 'max:366'],
            'status' => ['required', 'string', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code'))),
            'requires_balance' => $this->boolean('requires_balance'),
            'annual_entitlement_days' => $this->nullableNumber('annual_entitlement_days'),
            'carry_forward_max_days' => $this->nullableNumber('carry_forward_max_days'),
        ]);
    }

    /** @return array<string, mixed> */
    public function persistenceData(): array
    {
        $validated = $this->validated();

        return [
            'code' => $validated['code'],
            'name' => $validated['name'],
            'status' => $validated['status'],
            'notes' => $validated['notes'] ?? null,
            'metadata' => [
                'payment_status' => $validated['payment_status'],
                'requires_balance' => (bool) ($validated['requires_balance'] ?? false),
                'annual_entitlement_days' => $validated['annual_entitlement_days'] ?? null,
                'carry_forward_max_days' => $validated['carry_forward_max_days'] ?? null,
            ],
        ];
    }

    private function nullableNumber(string $key): mixed
    {
        $value = $this->input($key);

        return $value === null || $value === '' ? null : $value;
    }
}
