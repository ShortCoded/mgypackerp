<?php

namespace Modules\Purchases\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Services\DateFormatService;
use Modules\FixedAssets\Services\FixedAssetPurchaseIntegrationService;
use Modules\Purchases\Models\PurchaseInvoice;

class UpdatePurchaseInvoiceAssetTreatmentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $effectiveDate = trim((string) $this->input('asset_effective_date'));

        $this->merge([
            'line_public_id' => trim((string) $this->input('line_public_id')),
            'asset_treatment' => trim((string) $this->input('asset_treatment')),
            'target_fixed_asset_doc_num' => trim((string) $this->input('target_fixed_asset_doc_num')),
            'asset_effective_date' => $effectiveDate === '' ? null : app(DateFormatService::class)->normalizeForStorage($effectiveDate),
        ]);
    }

    public function authorize(): bool
    {
        if (! $this->user()?->can('purchase_invoices.edit')) {
            return false;
        }

        $treatment = (string) $this->input('asset_treatment');
        if ($treatment === FixedAssetPurchaseIntegrationService::TreatmentNewAsset) {
            return (bool) $this->user()?->can('fixed_assets.create');
        }

        if ($treatment === FixedAssetPurchaseIntegrationService::TreatmentCapitalImprovement) {
            return (bool) $this->user()?->can('fixed_assets.improvement.post');
        }

        /** @var PurchaseInvoice|null $invoice */
        $invoice = $this->route('purchaseInvoice');
        $line = $invoice?->lines()->where('public_id', $this->input('line_public_id'))->first();

        return $line?->asset_treatment === FixedAssetPurchaseIntegrationService::TreatmentCapitalImprovement
            ? (bool) $this->user()?->can('fixed_assets.improvement.post')
            : (bool) $this->user()?->can('fixed_assets.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'line_public_id' => ['required', 'uuid'],
            'asset_treatment' => ['required', Rule::in([
                FixedAssetPurchaseIntegrationService::TreatmentNone,
                FixedAssetPurchaseIntegrationService::TreatmentNewAsset,
                FixedAssetPurchaseIntegrationService::TreatmentCapitalImprovement,
            ])],
            'target_fixed_asset_doc_num' => [
                'nullable',
                'string',
                'required_if:asset_treatment,'.FixedAssetPurchaseIntegrationService::TreatmentCapitalImprovement,
            ],
            'asset_effective_date' => [
                'nullable',
                'date',
                'required_if:asset_treatment,'.FixedAssetPurchaseIntegrationService::TreatmentCapitalImprovement,
            ],
        ];
    }
}
