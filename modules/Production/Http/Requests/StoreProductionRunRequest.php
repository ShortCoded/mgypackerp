<?php

namespace Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingContextService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\HR\Models\HrEmployee;
use Modules\Production\Models\ProductionOrderLine;
use Modules\Production\Models\ProductionOrderStageSnapshot;
use Modules\Production\Models\ProductionShift;

class StoreProductionRunRequest extends FormRequest
{
    use NormalizesNumericInput;

    protected function prepareForValidation(): void
    {
        $context = app(OperatingContextService::class)->snapshot($this);
        $data = $this->all();

        if (filled($data['production_order_line_public_id'] ?? null)) {
            $data['production_order_line_id'] = ProductionOrderLine::query()
                ->where('public_id', $data['production_order_line_public_id'])
                ->whereHas('order', fn ($orders) => $orders
                    ->where('company_id', $context['company_id'])
                    ->where('financial_period_id', $context['financial_period_id'])
                    ->where('branch_id', $context['branch_id']))
                ->value('id');
        }
        if (filled($data['production_order_stage_snapshot_public_id'] ?? null) && filled($data['production_order_line_id'] ?? null)) {
            $data['production_order_stage_snapshot_id'] = ProductionOrderStageSnapshot::query()
                ->where('production_order_line_id', $data['production_order_line_id'])
                ->where('public_id', $data['production_order_stage_snapshot_public_id'])
                ->value('id');
        }
        if (filled($data['fixed_asset_doc_num'] ?? null)) {
            $data['fixed_asset_id'] = FixedAsset::query()
                ->where('company_id', $context['company_id'])
                ->where('branch_id', $context['branch_id'])
                ->where('doc_num', $data['fixed_asset_doc_num'])
                ->value('id');
        }

        $workerDocumentNumbers = collect($data['labor_details'] ?? [])->pluck('employee_doc_num')->filter()->unique();
        $workerIds = HrEmployee::query()
            ->where('company_id', $context['company_id'])
            ->where('branch_id', $context['branch_id'])
            ->whereIn('doc_num', $workerDocumentNumbers)
            ->pluck('id', 'doc_num');
        $data['labor_details'] = collect($data['labor_details'] ?? [])->map(function (mixed $labor) use ($workerIds): mixed {
            if (! is_array($labor)) {
                return $labor;
            }

            if (filled($labor['employee_doc_num'] ?? null)) {
                $labor['employee_id'] = $workerIds->get($labor['employee_doc_num']);
            }

            return $labor;
        })->all();

        $dates = app(DateFormatService::class);
        foreach (['planned_start_at', 'planned_end_at'] as $field) {
            if (filled($data[$field] ?? null)) {
                $data[$field] = $dates->normalizeDateTimeForStorage((string) $data[$field]) ?? $data[$field];
            }
        }

        $this->replace($data);
        $this->normalizeNumericInput(['planned_quantity', 'planned_labor_count', 'labor_details.*.planned_hours']);
        $laborDetails = collect($this->input('labor_details', []))
            ->filter(fn (mixed $labor): bool => is_array($labor) && filled($labor['employee_id'] ?? null))
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
        $permission = $this->routeIs('admin.production.runs.update')
            ? 'production.runs.edit'
            : 'production.runs.plan';

        return (bool) $this->user()?->can($permission);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $context = app(OperatingContextService::class)->snapshot($this);

        return [
            'production_order_line_public_id' => ['nullable', 'uuid'],
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
            'production_order_stage_snapshot_public_id' => ['nullable', 'uuid'],
            'production_order_stage_snapshot_id' => [
                'nullable',
                'integer',
                Rule::exists('production_order_stage_snapshots', 'id')->where(fn ($query) => $query
                    ->where('production_order_line_id', $this->integer('production_order_line_id'))),
            ],
            'fixed_asset_doc_num' => ['nullable', 'string', 'max:100'],
            'planned_quantity' => ['required', 'numeric', 'gt:0'],
            'planned_start_at' => ['required', 'date'],
            'planned_end_at' => ['required', 'date', 'after:planned_start_at'],
            'production_shift_id' => [
                'nullable',
                'integer',
                Rule::exists(ProductionShift::class, 'id')->where(fn ($query) => $query
                    ->where('company_id', $context['company_id'])
                    ->where('branch_id', $context['branch_id'])
                    ->where('is_active', true)),
            ],
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
            'labor_details.*.employee_doc_num' => ['nullable', 'string', 'max:100'],
            'labor_details.*.employee_id' => [
                'required_with:labor_details',
                'integer',
                'distinct',
                Rule::exists('hr_employees', 'id')->where(fn ($query) => $query
                    ->where('company_id', $context['company_id'])
                    ->where('branch_id', $context['branch_id'])
                    ->whereIn('person_type', ['regular_labor', 'casual_labor'])
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
            ],
            'labor_details.*.role' => ['nullable', 'string', 'max:255'],
            'labor_details.*.planned_hours' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'labor_details.*.notes' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string'],
            'submit_action' => ['nullable', Rule::in(['save', 'save_view', 'save_back', 'save_edit', 'save_clone'])],
        ];
    }
}
