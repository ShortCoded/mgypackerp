<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Services\DateFormatService;

class ActivateFixedAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('fixed_assets.activate');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['activation_date' => app(DateFormatService::class)->normalizeForStorage(trim((string) $this->input('activation_date')))]);
    }

    public function rules(): array
    {
        return ['activation_date' => ['required', 'date']];
    }
}
