<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;

class StoreDirectCustomerInvoiceRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('customer_invoices.create');
    }

    protected function prepareForValidation(): void
    {
        $context = app(OperatingContextService::class)->snapshot($this);
        $invoiceDate = $this->string('invoice_date')->trim()->toString();
        $dueDate = $this->string('due_date')->trim()->toString();

        $this->merge([
            'company_id' => $context['company_id'],
            'financial_period_id' => $context['financial_period_id'],
            'branch_id' => $context['branch_id'],
            'source_request_doc_num' => $this->string('source_request_doc_num')->trim()->toString() ?: null,
            'customer_doc_num' => $this->string('customer_doc_num')->trim()->toString() ?: null,
            'currency_doc_num' => $this->string('currency_doc_num')->trim()->toString() ?: null,
            'invoice_date' => $invoiceDate === '' ? null : app(DateFormatService::class)->normalizeForStorage($invoiceDate),
            'due_date' => $dueDate === '' ? null : app(DateFormatService::class)->normalizeForStorage($dueDate),
            'notes' => $this->string('notes')->trim()->toString() ?: null,
            'lines' => collect($this->input('lines', []))->filter(fn (mixed $line): bool => is_array($line))
                ->map(fn (array $line): array => [
                    'source_request_line_public_id' => filled($line['source_request_line_public_id'] ?? null) ? trim((string) $line['source_request_line_public_id']) : null,
                    'product_doc_num' => filled($line['product_doc_num'] ?? null) ? trim((string) $line['product_doc_num']) : null,
                    'unit_doc_num' => filled($line['unit_doc_num'] ?? null) ? trim((string) $line['unit_doc_num']) : null,
                    'quantity' => filled($line['quantity'] ?? null) ? trim((string) $line['quantity']) : null,
                    'unit_price' => filled($line['unit_price'] ?? null) ? trim((string) $line['unit_price']) : null,
                    'discount_amount' => filled($line['discount_amount'] ?? null) ? trim((string) $line['discount_amount']) : '0',
                    'tax_amount' => filled($line['tax_amount'] ?? null) ? trim((string) $line['tax_amount']) : '0',
                ])->filter(fn (array $line): bool => filled($line['product_doc_num']))->values()->all(),
        ]);

        $this->normalizeNumericInput([
            'exchange_rate', 'lines.*.quantity', 'lines.*.unit_price', 'lines.*.discount_amount', 'lines.*.tax_amount',
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'financial_period_id' => ['required', 'integer', 'exists:financial_periods,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'source_request_doc_num' => ['nullable', 'string'],
            'customer_doc_num' => ['required', 'string'],
            'currency_doc_num' => ['required', 'string'],
            'exchange_rate' => ['required', 'numeric', 'gt:0'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.source_request_line_public_id' => ['nullable', 'uuid'],
            'lines.*.product_doc_num' => ['required', 'string'],
            'lines.*.unit_doc_num' => ['required', 'string'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
