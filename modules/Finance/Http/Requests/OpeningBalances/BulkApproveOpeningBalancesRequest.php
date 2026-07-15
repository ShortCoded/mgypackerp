<?php

namespace Modules\Finance\Http\Requests\OpeningBalances;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Services\OperatingContextService;

class BulkApproveOpeningBalancesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('opening_balances.approve');
    }

    public function rules(): array
    {
        $context = app(OperatingContextService::class)->snapshot($this);

        return [
            'doc_nums' => ['required', 'array', 'min:1'],
            'doc_nums.*' => [
                'required',
                'string',
                Rule::exists('opening_balances', 'doc_num')
                    ->where(fn ($query) => $query
                        ->where('company_id', $context['company_id'])
                        ->where('financial_period_id', $context['financial_period_id'])
                        ->whereNull('deleted_at')),
            ],
        ];
    }
}
