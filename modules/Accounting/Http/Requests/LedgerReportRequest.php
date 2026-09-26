<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Core\Models\Branch;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Purchases\Models\Supplier;
use Modules\Sales\Models\Customer;

class LedgerReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $action = $this->route('ledger_export_format') ? 'export' : 'view';
        $permission = match ($this->reportType()) {
            'customer_statement' => "reports.customer_statement.{$action}",
            'supplier_statement' => "reports.supplier_statement.{$action}",
            'general_journal' => "reports.general_journal.{$action}",
            default => "reports.account_ledger.{$action}",
        };

        return (bool) $this->user()?->can($permission);
    }

    protected function prepareForValidation(): void
    {
        foreach (['account_doc_num', 'customer_doc_num', 'supplier_doc_num', 'branch_doc_num', 'cost_center_doc_num'] as $field) {
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

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if (! $this->boolean('run') && ! $this->route('ledger_export_format')) {
            return ['run' => ['nullable', 'boolean']];
        }

        return [
            'run' => ['required', 'boolean'],
            'all_periods' => ['nullable', 'boolean'],
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'account_doc_num' => [$this->reportType() === 'account_ledger' ? 'required' : 'nullable', 'string', 'max:255'],
            'customer_doc_num' => [$this->reportType() === 'customer_statement' ? 'required' : 'nullable', 'string', 'max:255'],
            'supplier_doc_num' => [$this->reportType() === 'supplier_statement' ? 'required' : 'nullable', 'string', 'max:255'],
            'branch_doc_num' => ['nullable', 'string', 'max:255'],
            'cost_center_doc_num' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        if (! $this->boolean('run')) {
            return;
        }

        $validator->after(function (Validator $validator): void {
            $context = app(OperatingContextService::class)->snapshot($this);
            $companyId = $context['company_id'];
            $periodId = $context['financial_period_id'];

            if ($companyId === null || $periodId === null) {
                $validator->errors()->add('from_date', __('ledger_reports.messages.operating_context_required'));

                return;
            }

            $period = FinancialPeriod::query()->whereKey($periodId)->where('company_id', $companyId)->first();
            $isPartnerStatement = in_array($this->reportType(), ['customer_statement', 'supplier_statement'], true);
            if (! $isPartnerStatement && ! $this->boolean('all_periods') && $period && $this->isValidDate($this->input('from_date')) && $this->isValidDate($this->input('to_date'))) {
                $from = $this->date('from_date');
                $to = $this->date('to_date');
                if ($from->lt($period->from_date) || $to->gt($period->to_date)) {
                    $validator->errors()->add('from_date', __('ledger_reports.messages.date_outside_period'));
                }
            }

            $checks = [
                'account_doc_num' => Account::query()->withTrashed()->forCompany((int) $companyId),
                'customer_doc_num' => Customer::query()->withTrashed()->forCompany((int) $companyId),
                'supplier_doc_num' => Supplier::query()->withTrashed()->forCompany((int) $companyId),
                'branch_doc_num' => Branch::query()->where('company_id', (int) $companyId)->active(),
                'cost_center_doc_num' => CostCenter::query()->forCompany((int) $companyId)->active()->where('is_group', false),
            ];

            foreach ($checks as $field => $query) {
                if ($this->filled($field) && ! $query->where('doc_num', $this->input($field))->exists()) {
                    $validator->errors()->add($field, __('ledger_reports.messages.filter_invalid'));
                }
            }
        });
    }

    public function reportType(): string
    {
        if (is_string($this->route('ledger_report_type'))) {
            return $this->route('ledger_report_type');
        }

        return match ($this->route()?->getName()) {
            'admin.accounting.reports.general-journal' => 'general_journal',
            'admin.accounting.reports.customer-statement' => 'customer_statement',
            'admin.accounting.reports.supplier-statement' => 'supplier_statement',
            default => 'account_ledger',
        };
    }

    private function isValidDate(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
