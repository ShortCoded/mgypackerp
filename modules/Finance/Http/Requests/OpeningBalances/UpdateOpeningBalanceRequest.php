<?php

namespace Modules\Finance\Http\Requests\OpeningBalances;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Finance\Models\OpeningBalance;

class UpdateOpeningBalanceRequest extends StoreOpeningBalanceRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('opening_balances.edit');
    }

    public function rules(): array
    {
        $openingBalance = $this->currentRecord();
        $rules = parent::rules();
        $rules['doc_number'] = [
            'nullable',
            'integer',
            'min:1',
            Rule::unique('opening_balances', 'doc_number')
                ->ignore($openingBalance?->getKey())
                ->where(fn ($query) => $query
                    ->where('company_id', $this->input('company_id'))
                    ->where('financial_period_id', $this->input('financial_period_id'))
                    ->whereNull('deleted_at')),
        ];
        unset($rules['clone_source_token']);

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var OpeningBalance|null $openingBalance */
            $openingBalance = $this->currentRecord();
            $this->validateBusiness(
                $validator,
                FinancialPeriod::query()->find($this->input('financial_period_id')),
                Currency::query()
                    ->where('company_id', $this->input('company_id'))
                    ->where('doc_num', $this->input('currency_doc_num'))
                    ->first(),
                $openingBalance,
            );
        });
    }

    private function currentRecord(): ?OpeningBalance
    {
        $docNum = $this->route('openingBalance');

        if (! is_string($docNum) || $docNum === '') {
            return null;
        }

        return OpeningBalance::query()
            ->where('doc_num', $docNum)
            ->where('company_id', $this->input('company_id'))
            ->where('financial_period_id', $this->input('financial_period_id'))
            ->first();
    }
}
