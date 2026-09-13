<?php

namespace Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingContextService;

class StoreProductionRunRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $laborDetails = collect($this->input('labor_details', []))
            ->filter(fn (mixed $labor): bool => is_array($labor) && (filled($labor['name'] ?? null) || filled($labor['employee_id'] ?? null)))
            ->values();

        $this->merge([
            'labor_details' => $laborDetails->isEmpty() ? null : $laborDetails->all(),
            'planned_labor_count' => filled($this->input('planned_labor_count'))
                ? $this->input('planned_labor_count')
                : ($laborDetails->isEmpty() ? null : $laborDetails->count()),
        ]);
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('production.runs.plan');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $context = app(OperatingContextService::class)->snapshot($this);

        return [
            'production_order_line_id' => [
                'required',
                'integer',
                Rule::exists('production_order_lines', 'id')->where(fn ($query) => $query
                    ->whereIn('production_order_id', fn ($orders) => $orders
                        ->select('id')->from('production_orders')
                        ->where('company_id', $context['company_id'])
                        ->where('financial_period_id', $context['financial_period_id'])
                        ->where('branch_id', $context['branch_id'])
                        ->whereNull('deleted_at'))),
            ],
            'production_order_stage_snapshot_id' => [
                'nullable',
                'integer',
                Rule::exists('production_order_stage_snapshots', 'id')->where(fn ($query) => $query
                    ->where('production_order_line_id', $this->integer('production_order_line_id'))),
            ],
            'planned_quantity' => ['required', 'numeric', 'gt:0'],
            'planned_start_at' => ['required', 'date'],
            'planned_end_at' => ['required', 'date', 'after:planned_start_at'],
            'fixed_asset_id' => [
                'nullable',
                'integer',
                Rule::exists('fixed_assets', 'id')->where(fn ($query) => $query
                    ->where('company_id', $context['company_id'])
                    ->where('branch_id', $context['branch_id'])
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
            ],
            'cost_center_doc_num' => [
                'nullable',
                'string',
                Rule::exists('cost_centers', 'doc_num')->where(fn ($query) => $query
                    ->where('company_id', app(OperatingCompanyContextService::class)->requireCompanyId($this))
                    ->where('status', 'active')
                    ->where('is_group', false)
                    ->whereNull('deleted_at')),
            ],
            'batch_lot' => ['nullable', 'string', 'max:100'],
            'work_description' => ['nullable', 'string', 'max:5000'],
            'planned_labor_count' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'labor_details' => ['nullable', 'array', 'max:200'],
            'labor_details.*.employee_id' => ['nullable', 'integer'],
            'labor_details.*.name' => ['required_with:labor_details', 'string', 'max:255'],
            'labor_details.*.role' => ['nullable', 'string', 'max:255'],
            'labor_details.*.planned_hours' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'labor_details.*.notes' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
