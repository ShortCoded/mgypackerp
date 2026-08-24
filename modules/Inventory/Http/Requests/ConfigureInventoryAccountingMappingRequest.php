<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Services\OperatingCompanyContextService;

class ConfigureInventoryAccountingMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('inventory.accounting.configure');
    }

    protected function prepareForValidation(): void
    {
        foreach (array_keys($this->rules()) as $field) {
            if ($this->has($field)) {
                $this->merge([$field => trim((string) $this->input($field))]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = app(OperatingCompanyContextService::class)->requireCompanyId();
        $postableAccount = fn () => Rule::exists('accounts', 'doc_num')->where(fn ($query) => $query
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->where('is_postable', true)
            ->where('is_group', false)
            ->whereNull('deleted_at'));

        return [
            'raw_material_inventory_account_doc_num' => ['required', 'string', $postableAccount()],
            'packaging_inventory_account_doc_num' => ['required', 'string', $postableAccount()],
            'semi_finished_inventory_account_doc_num' => ['required', 'string', $postableAccount()],
            'finished_goods_inventory_account_doc_num' => ['required', 'string', $postableAccount()],
            'wip_account_doc_num' => ['required', 'string', $postableAccount()],
            'production_waste_account_doc_num' => ['required', 'string', $postableAccount()],
            'recoverable_scrap_inventory_account_doc_num' => ['nullable', 'string', $postableAccount()],
            'warehouse_damage_loss_account_doc_num' => ['required', 'string', $postableAccount()],
            'inventory_adjustment_gain_account_doc_num' => ['required', 'string', $postableAccount()],
            'inventory_adjustment_loss_account_doc_num' => ['required', 'string', $postableAccount()],
            'production_variance_account_doc_num' => ['nullable', 'string', $postableAccount()],
            'production_cost_center_doc_num' => [
                'nullable',
                'string',
                Rule::exists('cost_centers', 'doc_num')->where(fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->where('status', 'active')
                    ->where('is_group', false)
                    ->whereNull('deleted_at')),
            ],
        ];
    }
}
