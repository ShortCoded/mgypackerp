<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\HR\Models\HrEmployee;
use Modules\Purchases\Models\Supplier;
use Modules\Sales\Models\Customer;

abstract class JournalEntryRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $numeric = app(NumericFormatService::class);
        $lines = collect($this->input('lines', []))
            ->map(function (mixed $line) use ($numeric): mixed {
                if (! is_array($line)) {
                    return $line;
                }

                return [
                    ...$line,
                    'account_doc_num' => trim((string) ($line['account_doc_num'] ?? '')),
                    'cost_center_doc_num' => $this->nullableTrim($line['cost_center_doc_num'] ?? null),
                    'customer_doc_num' => $this->nullableTrim($line['customer_doc_num'] ?? null),
                    'supplier_doc_num' => $this->nullableTrim($line['supplier_doc_num'] ?? null),
                    'employee_doc_num' => $this->nullableTrim($line['employee_doc_num'] ?? null),
                    'description' => $this->nullableTrim($line['description'] ?? null),
                    'debit_amount' => $numeric->normalizeForValidation($line['debit_amount'] ?? 0),
                    'credit_amount' => $numeric->normalizeForValidation($line['credit_amount'] ?? 0),
                ];
            })
            ->values()
            ->all();

        $this->merge([
            'reference_no' => $this->nullableTrim($this->input('reference_no')),
            'description' => $this->nullableTrim($this->input('description')),
            'notes' => $this->nullableTrim($this->input('notes')),
            'lines' => $lines,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:2', 'max:500'],
            'lines.*' => ['required', 'array'],
            'lines.*.account_doc_num' => ['required', 'string', 'max:255'],
            'lines.*.debit_amount' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999999999.9999'],
            'lines.*.credit_amount' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999999999.9999'],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
            'lines.*.cost_center_doc_num' => ['nullable', 'string', 'max:255'],
            'lines.*.customer_doc_num' => ['nullable', 'string', 'max:255'],
            'lines.*.supplier_doc_num' => ['nullable', 'string', 'max:255'],
            'lines.*.employee_doc_num' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $context = app(OperatingContextService::class)->snapshot($this);
            $companyId = $context['company_id'];
            $financialPeriodId = $context['financial_period_id'];

            if ($companyId === null || $financialPeriodId === null || $context['branch_id'] === null) {
                $validator->errors()->add('entry_date', __('journal_entries.messages.operating_context_required'));

                return;
            }

            $period = FinancialPeriod::query()
                ->whereKey($financialPeriodId)
                ->where('company_id', $companyId)
                ->first();

            if (! $period || $period->is_closed) {
                $validator->errors()->add('entry_date', __('journal_entries.messages.period_closed'));
            } elseif ($this->isValidDate($this->input('entry_date'))) {
                $entryDate = (string) $this->input('entry_date');
                if ($entryDate < $period->from_date->format('Y-m-d') || $entryDate > $period->to_date->format('Y-m-d')) {
                    $validator->errors()->add('entry_date', __('journal_entries.messages.date_outside_period'));
                }
            }

            $debits = '0.0000';
            $credits = '0.0000';

            foreach ((array) $this->input('lines', []) as $index => $line) {
                if (! is_array($line)) {
                    continue;
                }

                $debit = (string) ($line['debit_amount'] ?? '0');
                $credit = (string) ($line['credit_amount'] ?? '0');

                if (is_numeric($debit) && is_numeric($credit)) {
                    $hasDebit = bccomp($debit, '0', 4) === 1;
                    $hasCredit = bccomp($credit, '0', 4) === 1;

                    if ($hasDebit === $hasCredit) {
                        $validator->errors()->add("lines.{$index}.debit_amount", __('journal_entries.messages.one_side_required'));
                    }

                    $debits = bcadd($debits, $debit, 4);
                    $credits = bcadd($credits, $credit, 4);
                }

                $account = $this->account($companyId, $line['account_doc_num'] ?? null);
                if (! $account) {
                    $validator->errors()->add("lines.{$index}.account_doc_num", __('journal_entries.messages.account_invalid'));

                    continue;
                }

                $costCenterDocNum = $line['cost_center_doc_num'] ?? null;
                if ($costCenterDocNum !== null && ! CostCenter::query()->forCompany($companyId)->active()->where('is_group', false)->where('doc_num', $costCenterDocNum)->exists()) {
                    $validator->errors()->add("lines.{$index}.cost_center_doc_num", __('journal_entries.messages.cost_center_invalid'));
                }

                $this->validateParty($validator, $companyId, $index, $account, $line);
            }

            if (bccomp($debits, '0', 4) !== 1 || bccomp($debits, $credits, 4) !== 0) {
                $validator->errors()->add('lines', __('journal_entries.messages.unbalanced'));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'entry_date' => __('journal_entries.attributes.entry_date'),
            'reference_no' => __('journal_entries.attributes.reference_no'),
            'description' => __('journal_entries.attributes.description'),
            'notes' => __('journal_entries.attributes.notes'),
            'lines' => __('journal_entries.attributes.lines'),
            'lines.*.account_doc_num' => __('journal_entries.attributes.account'),
            'lines.*.debit_amount' => __('journal_entries.attributes.debit'),
            'lines.*.credit_amount' => __('journal_entries.attributes.credit'),
            'lines.*.cost_center_doc_num' => __('journal_entries.attributes.cost_center'),
        ];
    }

    private function account(int $companyId, mixed $docNum): ?Account
    {
        if (! is_string($docNum) || trim($docNum) === '') {
            return null;
        }

        return Account::query()
            ->forCompany($companyId)
            ->active()
            ->where('is_group', false)
            ->where('is_postable', true)
            ->where('doc_num', $docNum)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function validateParty(Validator $validator, int $companyId, int $index, Account $account, array $line): void
    {
        $parties = [
            'customer' => [Customer::class, 'customer_doc_num'],
            'supplier' => [Supplier::class, 'supplier_doc_num'],
            'employee' => [HrEmployee::class, 'employee_doc_num'],
        ];

        foreach ($parties as $party => [$model, $field]) {
            $docNum = $line[$field] ?? null;
            if ($docNum === null) {
                continue;
            }

            $query = $model::query()->active();
            if ($model === HrEmployee::class) {
                $query->where('company_id', $companyId);
            } else {
                $query->forCompany($companyId);
            }
            $record = $query
                ->where('doc_num', $docNum)
                ->first();

            if (! $record) {
                $validator->errors()->add("lines.{$index}.{$field}", __('journal_entries.messages.party_invalid', ['party' => __("journal_entries.parties.{$party}")]));
            } elseif (in_array($party, ['customer', 'supplier'], true) && (int) $record->account_id !== (int) $account->getKey()) {
                $validator->errors()->add("lines.{$index}.{$field}", __('journal_entries.messages.party_account_mismatch'));
            }
        }

        if (($line['customer_doc_num'] ?? null) !== null && ($line['supplier_doc_num'] ?? null) !== null) {
            $validator->errors()->add("lines.{$index}.customer_doc_num", __('journal_entries.messages.single_party_required'));
        }
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function isValidDate(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
