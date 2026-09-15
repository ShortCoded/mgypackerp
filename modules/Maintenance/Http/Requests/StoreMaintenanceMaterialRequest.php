<?php

namespace Modules\Maintenance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Services\OperatingContextService;

class StoreMaintenanceMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = $this->isMethod('PUT') || $this->isMethod('PATCH')
            ? 'maintenance.material_requests.edit'
            : 'maintenance.material_requests.create';

        return (bool) $this->user()?->can($permission);
    }

    public function rules(): array
    {
        $context = app(OperatingContextService::class)->snapshot($this);

        return [
            'maintenance_work_order_id' => ['required', 'integer', Rule::exists('maintenance_work_orders', 'id')->where(fn ($query) => $query
                ->where('company_id', $context['company_id'])
                ->where('financial_period_id', $context['financial_period_id'])
                ->where('branch_id', $context['branch_id'])
                ->whereNull('deleted_at'))],
            'branch_store_id' => ['required', 'integer', Rule::exists('branch_stores', 'id')->where(fn ($query) => $query
                ->where('branch_id', $context['branch_id'])
                ->whereNull('deleted_at'))],
            'reason' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where(fn ($query) => $query
                ->where('company_id', $context['company_id'])
                ->where('status', 'active')
                ->whereNull('deleted_at'))],
            'lines.*.item_type' => ['required', Rule::in(['spare_part', 'oil', 'consumable'])],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
