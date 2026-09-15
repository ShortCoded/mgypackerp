<?php

namespace Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Models\Product;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerInvoiceLine;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;

class StoreProductionOrderRequest extends FormRequest
{
    use NormalizesNumericInput;

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput(['overproduction_tolerance_percent', 'lines.*.quantity']);
        $dates = app(DateFormatService::class);
        $normalizedDates = [];

        foreach (['production_order_date', 'expected_start_date', 'expected_finish_date', 'expected_delivery_date'] as $field) {
            if (filled($this->input($field))) {
                $normalizedDates[$field] = $dates->normalizeForStorage((string) $this->input($field)) ?? $this->input($field);
            }
        }

        $lines = collect($this->input('lines', []))->map(function (mixed $line): mixed {
            if (! is_array($line) || filled($line['source_line_reference'] ?? null) || blank($line['product_doc_num'] ?? null)) {
                return $line;
            }

            $line['source_line_reference'] = $this->legacySourceLineReference((string) $line['product_doc_num']);

            return $line;
        })->all();

        $this->merge([...$normalizedDates, 'lines' => $lines]);
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->isMethod('POST') ? 'production.orders.create' : 'production.orders.edit');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $context = app(OperatingContextService::class)->snapshot($this);

        return [
            'source_type' => ['required', Rule::in(['make_to_stock', 'sales_order', 'customer_invoice'])],
            'source_doc_num' => ['nullable', 'required_unless:source_type,make_to_stock', 'string', 'max:100'],
            'production_order_date' => ['required', 'date'],
            'expected_start_date' => ['nullable', 'date'],
            'expected_finish_date' => ['nullable', 'date', 'after_or_equal:expected_start_date'],
            'expected_delivery_date' => ['nullable', 'date'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'overproduction_tolerance_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'production_notes' => ['nullable', 'string', 'max:5000'],
            'submit_action' => ['nullable', Rule::in(['save', 'save_view', 'save_edit', 'save_back', 'save_clone'])],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.source_line_reference' => ['required', 'string', 'max:180', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
            'lines.*.production_notes' => ['nullable', 'string', 'max:2000'],
            'lines.*.stage_selection_present' => ['nullable', 'boolean'],
            'lines.*.stage_public_ids' => ['nullable', 'array', 'max:50'],
            'lines.*.stage_public_ids.*' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('product_production_stages', 'public_id')->where(fn ($query) => $query
                    ->where('company_id', $context['company_id'])
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
            ],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $context = app(OperatingContextService::class)->snapshot($this);
            $sourceType = (string) $this->input('source_type');
            $source = match ($this->input('source_type')) {
                'sales_order' => SalesOrder::query()
                    ->where('company_id', $context['company_id'])
                    ->where('doc_num', $this->input('source_doc_num'))
                    ->whereIn('status', [SalesOrder::StatusApproved, SalesOrder::StatusPartiallyFulfilled])
                    ->first(),
                'customer_invoice' => CustomerInvoice::query()
                    ->where('company_id', $context['company_id'])
                    ->where('doc_num', $this->input('source_doc_num'))
                    ->where('document_type', CustomerInvoice::TypeInvoice)
                    ->where('status', CustomerInvoice::StatusPosted)
                    ->first(),
                default => null,
            };

            if ($sourceType !== 'make_to_stock' && $source === null) {
                $validator->errors()->add('source_doc_num', __('production_execution.messages.sales_source_invalid'));

                return;
            }

            foreach ($this->input('lines', []) as $index => $line) {
                $reference = (string) ($line['source_line_reference'] ?? '');
                [$referenceType, $publicReference] = array_pad(explode(':', $reference, 2), 2, null);
                $isValid = match ($sourceType) {
                    'make_to_stock' => $referenceType === 'product' && Product::query()
                        ->where('company_id', $context['company_id'])
                        ->where('doc_num', $publicReference)
                        ->where('item_classification', Product::ClassificationFinishedProduct)
                        ->where('status', 'active')
                        ->whereNull('deleted_at')
                        ->exists(),
                    'sales_order' => $referenceType === 'sales_order_line' && SalesOrderLine::query()
                        ->where('sales_order_id', $source?->getKey())
                        ->where('public_id', $publicReference)
                        ->where('product_classification_snapshot', Product::ClassificationFinishedProduct)
                        ->whereHas('product', fn ($query) => $query
                            ->where('company_id', $context['company_id'])
                            ->where('item_classification', Product::ClassificationFinishedProduct)
                            ->where('status', 'active'))
                        ->exists(),
                    'customer_invoice' => $referenceType === 'customer_invoice_line' && CustomerInvoiceLine::query()
                        ->where('customer_invoice_id', $source?->getKey())
                        ->where('public_id', $publicReference)
                        ->where('is_service', false)
                        ->whereHas('product', fn ($query) => $query
                            ->where('company_id', $context['company_id'])
                            ->where('item_classification', Product::ClassificationFinishedProduct)
                            ->where('status', 'active'))
                        ->exists(),
                    default => false,
                };

                if (! $isValid) {
                    $validator->errors()->add("lines.{$index}.source_line_reference", __('production_execution.messages.invalid_source_line'));
                }
            }
        }];
    }

    private function legacySourceLineReference(string $productDocumentNumber): string
    {
        $sourceType = (string) $this->input('source_type', 'make_to_stock');

        if ($sourceType === 'make_to_stock') {
            return 'product:'.$productDocumentNumber;
        }

        $context = app(OperatingContextService::class)->snapshot($this);
        $source = match ($sourceType) {
            'sales_order' => SalesOrder::query()->where('company_id', $context['company_id'])->where('doc_num', $this->input('source_doc_num'))->first(),
            'customer_invoice' => CustomerInvoice::query()->where('company_id', $context['company_id'])->where('doc_num', $this->input('source_doc_num'))->first(),
            default => null,
        };
        $matchingLines = $source?->lines()
            ->whereHas('product', fn ($query) => $query->where('doc_num', $productDocumentNumber))
            ->pluck('public_id') ?? collect();

        if ($matchingLines->count() !== 1) {
            return '';
        }

        return ($sourceType === 'sales_order' ? 'sales_order_line:' : 'customer_invoice_line:').$matchingLines->first();
    }
}
