<?php

namespace Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NumericFormatService;

final class ProposeProductionRunCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('production.runs.correct') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $numbers = app(NumericFormatService::class);
        $output = [];
        foreach (['good_base_quantity', 'rejected_base_quantity', 'rework_base_quantity', 'scrap_base_quantity'] as $field) {
            $value = data_get($this->input('output', []), $field);
            $output[$field] = is_scalar($value) || $value === null ? $numbers->normalizeForValidation($value) : $value;
        }
        $date = $this->input('posting_date');
        $receiptDates = $this->input('receipt_dates', []);
        $receiptDates = is_array($receiptDates) ? collect($receiptDates)->map(function (mixed $row): mixed {
            if (! is_array($row)) {
                return $row;
            }
            foreach (['manufacture_date', 'expiry_date'] as $field) {
                if (is_string($row[$field] ?? null) && filled($row[$field])) {
                    $row[$field] = app(DateFormatService::class)->normalizeForStorage($row[$field]) ?? $row[$field];
                }
            }

            return $row;
        })->all() : $receiptDates;
        $this->merge(['output' => $output, 'posting_date' => is_string($date) ? (app(DateFormatService::class)->normalizeForStorage($date) ?? $date) : $date]);
        $this->merge(['receipt_dates' => $receiptDates]);
    }

    public function rules(): array
    {
        return ['fingerprint' => ['required', 'string', 'size:64'], 'reason' => ['required', 'string', 'max:2000'], 'posting_date' => ['required', 'date_format:Y-m-d'],
            'correction_mode' => ['nullable', 'string', 'in:original_period,later_period'],
            'receipt_dates' => ['nullable', 'array'], 'receipt_dates.*' => ['array'],
            'receipt_dates.*.manufacture_date' => ['nullable', 'date_format:Y-m-d'], 'receipt_dates.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'receipt_date_evidence' => ['nullable', 'string', 'max:2000'],
            'output' => ['required', 'array'], 'output.good_base_quantity' => ['required', 'numeric', 'gt:0'],
            'output.rejected_base_quantity' => ['required', 'numeric', 'min:0'],
            'output.rework_base_quantity' => ['required', 'numeric', 'min:0'], 'output.scrap_base_quantity' => ['required', 'numeric', 'min:0']];
    }
}
