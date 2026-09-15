<?php

namespace Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Services\DateFormatService;

class StoreProductionMaterialRequest extends FormRequest
{
    use NormalizesNumericInput;

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput(['lines.*.quantity']);

        if (filled($this->input('required_by_date'))) {
            $submittedDate = (string) $this->input('required_by_date');
            $this->merge([
                'required_by_date' => app(DateFormatService::class)->normalizeForStorage($submittedDate) ?? $submittedDate,
            ]);
        }
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->isMethod('POST')
            ? 'production.material_requests.create'
            : 'production.material_requests.edit');
    }

    public function rules(): array
    {
        return [
            'production_run_id' => ['required', 'integer'],
            'branch_store_id' => ['required', 'integer'],
            'additional' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:2000', 'required_if:additional,1'],
            'required_by_date' => ['nullable', 'date'],
            'submit_action' => ['nullable', Rule::in(['save', 'save_view', 'save_edit', 'save_back', 'save_clone'])],
            'lines' => ['nullable', 'array'],
            'lines.*.requirement_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['nullable', 'numeric', 'gt:0'],
        ];
    }
}
