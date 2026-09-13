<?php

namespace Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Production\Models\ProductionQualityInspection;

class StoreProductionQualityInspectionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->filled('subject_type') && $this->filled('production_run_id')) {
            $this->merge(['subject_type' => ProductionQualityInspection::SubjectProductionRun]);
        }
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('production.quality.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $context = app(OperatingContextService::class)->snapshot($this);

        return [
            'subject_type' => ['required', Rule::in([
                ProductionQualityInspection::SubjectProductionRun,
                ProductionQualityInspection::SubjectProduct,
                ProductionQualityInspection::SubjectInventoryStock,
            ])],
            'production_run_id' => [
                'nullable',
                'required_if:subject_type,'.ProductionQualityInspection::SubjectProductionRun,
                'integer',
                Rule::exists('production_runs', 'id')->where(fn ($query) => $query
                    ->where('company_id', $context['company_id'])
                    ->where('financial_period_id', $context['financial_period_id'])
                    ->where('branch_id', $context['branch_id'])
                    ->whereNull('deleted_at')),
            ],
            'product_id' => [
                'nullable',
                'required_if:subject_type,'.ProductionQualityInspection::SubjectProduct,
                'required_if:subject_type,'.ProductionQualityInspection::SubjectInventoryStock,
                'integer',
                Rule::exists('products', 'id')->where(fn ($query) => $query
                    ->where('company_id', $context['company_id'])
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
            ],
            'branch_store_id' => [
                'nullable',
                'required_if:subject_type,'.ProductionQualityInspection::SubjectInventoryStock,
                'integer',
                Rule::exists('branch_stores', 'id')->where(fn ($query) => $query
                    ->where('branch_id', $context['branch_id'])
                    ->whereNull('deleted_at')),
            ],
            'stock_status' => ['nullable', 'required_if:subject_type,'.ProductionQualityInspection::SubjectInventoryStock, Rule::in([
                InventoryTransaction::StatusAvailable,
                InventoryTransaction::StatusQuarantine,
                InventoryTransaction::StatusRework,
                InventoryTransaction::StatusDamaged,
                InventoryTransaction::StatusScrap,
            ])],
            'batch_lot' => ['nullable', 'string', 'max:120'],
            'source_reference' => ['nullable', 'string', 'max:255'],
            'quality_inspection_type_id' => [
                'nullable',
                'integer',
                Rule::exists('quality_inspection_types', 'id')->where(fn ($query) => $query
                    ->where('company_id', $context['company_id'])
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ],
            'affected_base_quantity' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
