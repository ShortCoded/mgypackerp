<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Modules\Core\Services\OperatingContextService;

class BulkDeleteFixedAssetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('fixed_assets.delete');
    }

    public function rules(): array
    {
        $companyId = Arr::get(app(OperatingContextService::class)->snapshot($this), 'company_id');

        return [
            'doc_nums' => ['required', 'array', 'min:1'],
            'doc_nums.*' => [
                'required',
                'string',
                Rule::exists('fixed_assets', 'doc_num')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
        ];
    }
}
