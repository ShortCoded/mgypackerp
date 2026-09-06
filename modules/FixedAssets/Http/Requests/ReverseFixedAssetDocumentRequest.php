<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReverseFixedAssetDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(match (true) {
            $this->routeIs('admin.fixed-assets.depreciation.reverse') => 'fixed_assets.depreciation.reverse',
            $this->routeIs('admin.fixed-assets.movements.reverse') => $this->route('movement')?->movement_type === 'addition' ? 'fixed_assets.improvement.reverse' : 'fixed_assets.recognition.reverse',
            default => 'fixed_assets.disposal.reverse',
        });
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:2000']];
    }
}
