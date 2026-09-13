<?php

namespace Modules\Maintenance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteMaintenanceWorkOrder extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('maintenance.orders.complete');
    }

    public function rules(): array
    {
        return [
            'diagnosis' => ['required', 'string', 'max:5000'],
            'root_cause' => ['nullable', 'string', 'max:5000'],
            'work_performed' => ['required', 'string', 'max:5000'],
            'completion_notes' => ['nullable', 'string', 'max:5000'],
            'next_due_date' => ['nullable', 'date'],
        ];
    }
}
