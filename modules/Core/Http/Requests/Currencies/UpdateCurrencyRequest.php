<?php

namespace Modules\Core\Http\Requests\Currencies;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Models\Currency;
use Modules\Core\Services\OperatingCompanyContextService;

class UpdateCurrencyRequest extends StoreCurrencyRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('currencies.edit');
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $currency = $this->route('currency');
        $companyId = app(OperatingCompanyContextService::class)->requireCompanyId($this);

        $rules['doc_number'] = [
            'nullable',
            'integer',
            'min:1',
            Rule::unique('currencies', 'doc_number')
                ->ignore($currency instanceof Currency ? $currency->getKey() : null)
                ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
        ];
        unset($rules['clone_source_token']);

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Currency|null $currency */
            $currency = $this->route('currency');

            $companyId = app(OperatingCompanyContextService::class)->requireCompanyId($this);

            if (Currency::query()->forCompany($companyId)->where('code', $this->input('code'))->whereKeyNot($currency?->getKey())->exists()) {
                $validator->errors()->add('code', __('currencies.messages.code_used'));
            }
        });
    }
}
