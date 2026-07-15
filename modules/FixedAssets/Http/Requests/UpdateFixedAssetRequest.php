<?php

namespace Modules\FixedAssets\Http\Requests;

use Modules\FixedAssets\Models\FixedAsset;

class UpdateFixedAssetRequest extends StoreFixedAssetRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('fixed_assets.edit');
    }

    protected function currentFixedAsset(): ?FixedAsset
    {
        $fixedAsset = $this->route('fixedAsset');

        return $fixedAsset instanceof FixedAsset ? $fixedAsset : null;
    }
}
