<?php

namespace Modules\Purchases\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Services\DateFormatService;

final class PurchaseInvoiceStageCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('production.runs.correct') && $this->user()?->can('purchase_invoices.view') && $this->user()?->can('purchases.prices.view');
    }

    protected function prepareForValidation(): void
    {
        $date = $this->input('posting_date');
        $this->merge(['posting_date' => is_string($date) ? (app(DateFormatService::class)->normalizeForStorage($date) ?? $date) : $date]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['reverse' => ['required', 'boolean'], 'fingerprint' => ['required', 'string', 'size:64'],
            'posting_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'price_evidence' => ['required', 'string', 'min:5', 'max:2000']];
    }
}
