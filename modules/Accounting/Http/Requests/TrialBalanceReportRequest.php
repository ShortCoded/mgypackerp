<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Accounting\Models\CostCenter;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;

class TrialBalanceReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $action = $this->route('trial_balance_export_format') ? 'export' : 'view';

        return (bool) $this->user()?->can("reports.trial_balance.{$action}");
    }

    protected function prepareForValidation(): void
    {
        foreach (['branch_doc_num', 'cost_center_doc_num'] as $field) {
            $value = trim((string) $this->input($field));
            $this->merge([$field => $value === '' ? null : $value]);
        }

        $dates = app(DateFormatService::class);
        foreach (['from_date', 'to_date'] as $field) {
            if ($this->filled($field) && $dates->isValidDate((string) $this->input($field))) {
                $this->merge([$field => $dates->normalizeForStorage((string) $this->input($field))]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if (! $this->boolean('run') && ! $this->route('trial_balance_export_format')) {
            return ['run' => ['nullable', 'boolean']];
        }

        return [
            'run' => ['required', 'boolean'],
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'branch_doc_num' => ['nullable', 'string', 'max:255'],
            'cost_center_doc_num' => ['nullable', 'string', 'max:255'],
            'include_zero' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        if (! $this->boolean('run')) {
            return;
        }

        $validator->after(function (Validator $validator): void {
            $operatingContext = app(OperatingContextService::class);
            $context = $operatingContext->snapshot($this);
            $companyId = $context['company_id'];
            $periodId = $context['financial_period_id'];

            if ($companyId === null || $periodId === null) {
                $validator->errors()->add('from_date', __('trial_balance.messages.operating_context_required'));

                return;
            }

            $period = FinancialPeriod::query()
                ->whereKey($periodId)
                ->where('company_id', $companyId)
                ->first();

            if ($period && $this->isValidDate($this->input('from_date')) && $this->isValidDate($this->input('to_date'))) {
                if ($this->date('from_date')->lt($period->from_date) || $this->date('to_date')->gt($period->to_date)) {
                    $validator->errors()->add('from_date', __('trial_balance.messages.date_outside_period'));
                }
            }

            if ($this->filled('branch_doc_num') && ! $operatingContext
                ->allowedBranchQueryForCurrentCompany($this)
                ->where('branches.doc_num', $this->input('branch_doc_num'))
                ->exists()) {
                $validator->errors()->add('branch_doc_num', __('trial_balance.messages.filter_invalid'));
            }

            if ($this->filled('cost_center_doc_num') && ! CostCenter::query()
                ->forCompany((int) $companyId)
                ->active()
                ->where('is_group', false)
                ->where('doc_num', $this->input('cost_center_doc_num'))
                ->exists()) {
                $validator->errors()->add('cost_center_doc_num', __('trial_balance.messages.filter_invalid'));
            }
        });
    }

    private function isValidDate(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
