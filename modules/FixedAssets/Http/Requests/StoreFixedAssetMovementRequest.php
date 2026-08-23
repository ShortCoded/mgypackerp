<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingCompanyContextService;

class StoreFixedAssetMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('fixed_assets.transfer');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'movement_date' => app(DateFormatService::class)->normalizeForStorage($this->nullableTrim('movement_date')),
            'destination_branch_doc_num' => $this->nullableTrim('destination_branch_doc_num'),
            'destination_branch_hall_uuid' => $this->nullableTrim('destination_branch_hall_uuid'),
            'destination_cost_center_doc_num' => $this->nullableTrim('destination_cost_center_doc_num'),
            'destination_location_address' => $this->nullableTrim('destination_location_address'),
            'reason' => $this->nullableTrim('reason'),
            'notes' => $this->nullableTrim('notes'),
        ]);
    }

    public function rules(): array
    {
        $companyId = app(OperatingCompanyContextService::class)->requireCompanyId();

        return [
            'movement_date' => ['required', 'date'],
            'destination_branch_doc_num' => ['required', 'string', Rule::exists('branches', 'doc_num')->where(fn ($query) => $query->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at'))],
            'destination_branch_hall_uuid' => ['nullable', 'string', 'max:255'],
            'destination_cost_center_doc_num' => ['nullable', 'string', Rule::exists('cost_centers', 'doc_num')->where(fn ($query) => $query->where('company_id', $companyId)->where('status', 'active')->where('is_group', false)->whereNull('deleted_at'))],
            'destination_location_address' => ['nullable', 'string'],
            'reason' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }

    private function nullableTrim(string $key): ?string
    {
        $value = trim((string) $this->input($key));

        return $value === '' ? null : $value;
    }
}
