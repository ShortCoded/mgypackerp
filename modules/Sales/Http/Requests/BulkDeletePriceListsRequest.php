<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Services\OperatingCompanyContextService;

class BulkDeletePriceListsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('price_lists.delete');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(OperatingCompanyContextService::class)->requireCompanyId($this);

        return [
            'doc_nums' => ['required', 'array', 'min:1'],
            'doc_nums.*' => [
                'required',
                'string',
                'distinct',
                Rule::exists('price_lists', 'doc_num')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
        ];
    }
}
