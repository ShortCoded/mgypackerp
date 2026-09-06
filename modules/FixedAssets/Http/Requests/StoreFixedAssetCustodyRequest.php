<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Services\DateFormatService;

class StoreFixedAssetCustodyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('fixed_assets.custody.post');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['movement_date' => app(DateFormatService::class)->normalizeForStorage($this->input('movement_date'))]);
    }

    public function rules(): array
    {
        return ['movement_date' => ['required', 'date'], 'custody_action' => ['sometimes', 'in:assign,return'], 'custodian_doc_num' => ['required_if:custody_action,assign', 'prohibited_if:custody_action,return', 'nullable', 'string', 'max:255'], 'reason' => ['required', 'string', 'max:2000'], 'notes' => ['nullable', 'string', 'max:10000']];
    }
}
