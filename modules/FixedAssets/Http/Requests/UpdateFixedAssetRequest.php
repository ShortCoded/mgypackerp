<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Validation\Validator;
use Modules\FixedAssets\Models\FixedAsset;

class UpdateFixedAssetRequest extends StoreFixedAssetRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('fixed_assets.edit');
    }

    public function rules(): array
    {
        if (! $this->isLockedMasterUpdate()) {
            return parent::rules();
        }

        return [
            'asset_name' => ['required', 'string', 'max:255', $this->uniqueActiveRule('asset_name')],
            'image_archive_file_doc_num' => ['nullable', 'string', 'max:255'],
            'remove_image' => ['nullable', 'boolean'],
            'description' => ['required', 'string'],
            'serial_number' => ['nullable', 'string', 'max:255', $this->uniqueActiveRule('serial_number')],
            'notes' => ['nullable', 'string'],
            'submit_action' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        if (! $this->isLockedMasterUpdate()) {
            parent::withValidator($validator);

            return;
        }

        $validator->after(function (Validator $validator): void {
            $this->validateImageSelection($validator);
        });
    }

    protected function currentFixedAsset(): ?FixedAsset
    {
        $fixedAsset = $this->route('fixedAsset');

        return $fixedAsset instanceof FixedAsset ? $fixedAsset : null;
    }

    private function isLockedMasterUpdate(): bool
    {
        return (bool) $this->currentFixedAsset()?->isMasterLocked();
    }
}
