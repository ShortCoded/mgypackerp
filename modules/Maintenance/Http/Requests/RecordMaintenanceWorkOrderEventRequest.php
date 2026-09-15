<?php

namespace Modules\Maintenance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordMaintenanceWorkOrderEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = in_array($this->route('eventAction'), ['external-dispatch', 'external-receive'], true)
            ? 'maintenance.orders.external'
            : 'maintenance.orders.pause';

        return (bool) $this->user()?->can($permission);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $action = (string) $this->route('eventAction');

        return [
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'reason' => [Rule::requiredIf($action === 'pause'), 'nullable', 'string', 'max:2000'],
            'recipient' => [Rule::requiredIf($action === 'external-dispatch'), 'nullable', 'string', 'max:255'],
            'item_condition' => [Rule::requiredIf(in_array($action, ['external-dispatch', 'external-receive'], true)), 'nullable', 'string', 'max:2000'],
            'accessories' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
