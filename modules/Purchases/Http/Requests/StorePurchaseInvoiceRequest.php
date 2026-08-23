<?php

namespace Modules\Purchases\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\ProductComponentUnitOptionsService;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\Supplier;
use Modules\Purchases\Services\PurchaseInvoiceCalculationService;

class StorePurchaseInvoiceRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        $action = $this->filled('clone_source_token') ? 'clone' : 'create';

        return (bool) $this->user()?->can('purchase_invoices.'.$action);
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput([
            'exchange_rate',
            'header_discount_value',
            'freight_amount',
            'freight_tax_rate',
            'lines.*.quantity',
            'lines.*.unit_price',
            'lines.*.discount_value',
            'lines.*.tax_rate',
            'payment_schedules.*.amount',
        ]);

        $context = app(OperatingContextService::class)->snapshot($this);
        $financialPeriodDocNum = $this->filled('financial_period_doc_num')
            ? trim((string) $this->input('financial_period_doc_num'))
            : ($context['financial_period_doc_num'] ?? null);

        $this->merge([
            'company_id' => $context['company_id'],
            'financial_period_doc_num' => $financialPeriodDocNum,
            'supplier_doc_num' => $this->trimmed('supplier_doc_num'),
            'purchase_order_doc_num' => $this->trimmed('purchase_order_doc_num'),
            'purchase_type' => $this->trimmed('purchase_type') ?: 'standard',
            'direct_procurement_override' => $this->boolean('direct_procurement_override'),
            'direct_procurement_reason' => $this->trimmed('direct_procurement_reason'),
            'currency_doc_num' => $this->trimmed('currency_doc_num'),
            'cashbox_doc_num' => $this->trimmed('cashbox_doc_num'),
            'bank_account_doc_num' => $this->trimmed('bank_account_doc_num'),
            'invoice_date' => $this->trimmed('invoice_date'),
            'supplier_invoice_number' => $this->trimmed('supplier_invoice_number'),
            'supplier_invoice_date' => $this->trimmed('supplier_invoice_date'),
            'exchange_rate' => $this->decimalInput('exchange_rate', '1'),
            'payment_type' => $this->trimmed('payment_type') ?: PurchaseInvoice::PaymentTypeCredit,
            'payment_source_type' => $this->trimmed('payment_source_type'),
            'header_discount_type' => $this->trimmed('header_discount_type'),
            'header_discount_value' => $this->decimalInput('header_discount_value', '0'),
            'freight_amount' => $this->decimalInput('freight_amount', '0'),
            'freight_tax_rate' => $this->decimalInput('freight_tax_rate', '0'),
            'notes' => $this->trimmed('notes'),
            'internal_notes' => $this->trimmed('internal_notes'),
            'lines' => $this->normalizedLines(),
            'payment_schedules' => $this->normalizedSchedules(),
        ]);
    }

    public function rules(): array
    {
        $companyId = $this->input('company_id');

        return [
            'doc_number' => ['nullable', 'integer', 'min:1', $this->uniqueDocumentNumberRule()],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'financial_period_doc_num' => [
                'required',
                'string',
                Rule::exists('financial_periods', 'doc_num')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'supplier_doc_num' => [
                'required',
                'string',
                Rule::exists('suppliers', 'doc_num')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at')),
            ],
            'invoice_date' => ['required', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! app(DateFormatService::class)->isValidDate(is_string($value) ? $value : null)) {
                    $fail(__('purchase_invoices.messages.invoice_date_invalid'));
                }
            }],
            'supplier_invoice_number' => ['nullable', 'string', 'max:100', $this->uniqueSupplierInvoiceRule()],
            'purchase_order_doc_num' => [
                'nullable',
                'string',
                Rule::exists('purchase_orders', 'doc_num')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->whereIn('status', ['approved', 'closed'])->whereNull('deleted_at')),
            ],
            'purchase_type' => ['required', Rule::in(['standard', 'service', 'direct'])],
            'direct_procurement_override' => ['boolean'],
            'direct_procurement_reason' => ['nullable', 'string', 'required_if:direct_procurement_override,1'],
            'supplier_invoice_date' => ['nullable', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! app(DateFormatService::class)->isValidDate(is_string($value) ? $value : null)) {
                    $fail(__('purchase_invoices.messages.supplier_invoice_date_invalid'));
                }
            }],
            'currency_doc_num' => [
                'required',
                'string',
                Rule::exists('currencies', 'doc_num')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at')),
            ],
            'exchange_rate' => ['required', 'numeric', 'decimal:0,6', 'regex:/^\d{1,12}(?:\.\d{1,6})?$/D', 'gt:0'],
            'payment_type' => ['required', Rule::in([PurchaseInvoice::PaymentTypeCash, PurchaseInvoice::PaymentTypeCredit, PurchaseInvoice::PaymentTypePartial])],
            'payment_source_type' => ['nullable', Rule::in([PurchaseInvoice::SourceCashbox, PurchaseInvoice::SourceBank])],
            'cashbox_doc_num' => [
                'nullable',
                'string',
                Rule::exists('cashboxes', 'doc_num')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at')),
            ],
            'bank_account_doc_num' => [
                'nullable',
                'string',
                Rule::exists('bank_accounts', 'doc_num')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at')),
            ],
            'header_discount_type' => ['nullable', Rule::in(['fixed', 'percentage'])],
            'header_discount_value' => ['nullable', 'numeric', 'decimal:0,4', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/D', 'min:0'],
            'freight_amount' => ['nullable', 'numeric', 'decimal:0,4', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/D', 'min:0'],
            'freight_tax_rate' => ['nullable', 'numeric', 'decimal:0,4', 'regex:/^\d{1,4}(?:\.\d{1,4})?$/D', 'between:0,100'],
            'notes' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.public_id' => ['nullable', 'string'],
            'lines.*.product_doc_num' => [
                'required',
                'string',
                Rule::exists('products', 'doc_num')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at')),
            ],
            'lines.*.unit_doc_num' => ['required', 'string'],
            'lines.*.purchase_order_line_public_id' => ['nullable', 'uuid', 'exists:purchase_order_lines,public_id'],
            'lines.*.receipt_line_public_id' => ['nullable', 'uuid', 'exists:unpriced_inventory_receipt_lines,public_id'],
            'lines.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/D', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'decimal:0,4', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/D', 'min:0'],
            'lines.*.discount_type' => ['nullable', Rule::in(['fixed', 'percentage'])],
            'lines.*.discount_value' => ['nullable', 'numeric', 'decimal:0,4', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/D', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'decimal:0,4', 'regex:/^\d{1,4}(?:\.\d{1,4})?$/D', 'min:0', 'max:100'],
            'lines.*.notes' => ['nullable', 'string'],
            'payment_schedules' => ['nullable', 'array'],
            'payment_schedules.*.public_id' => ['nullable', 'string'],
            'payment_schedules.*.due_date' => ['required', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! app(DateFormatService::class)->isValidDate(is_string($value) ? $value : null)) {
                    $fail(__('purchase_invoices.messages.due_date_invalid'));
                }
            }],
            'payment_schedules.*.amount' => ['required', 'numeric', 'decimal:0,4', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/D', 'gt:0'],
            'payment_schedules.*.payment_source_type' => ['required', Rule::in([PurchaseInvoice::SourceScheduled, PurchaseInvoice::SourceCashbox, PurchaseInvoice::SourceBank])],
            'payment_schedules.*.cashbox_doc_num' => [
                'nullable',
                'string',
                Rule::exists('cashboxes', 'doc_num')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at')),
            ],
            'payment_schedules.*.bank_account_doc_num' => [
                'nullable',
                'string',
                Rule::exists('bank_accounts', 'doc_num')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at')),
            ],
            'payment_schedules.*.payment_date' => ['nullable', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! app(DateFormatService::class)->isValidDate(is_string($value) ? $value : null)) {
                    $fail(__('purchase_invoices.messages.payment_date_invalid'));
                }
            }],
            'payment_schedules.*.notes' => ['nullable', 'string'],
            'submit_action' => ['nullable', 'string'],
            'clone_source_token' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required' => __('purchase_invoices.messages.lines_required'),
            'lines.min' => __('purchase_invoices.messages.lines_required'),
            'lines.*.quantity.gt' => __('purchase_invoices.messages.quantity_gt_zero'),
            'lines.*.unit_price.min' => __('purchase_invoices.messages.unit_price_positive'),
            'payment_schedules.*.amount.gt' => __('purchase_invoices.messages.payment_amount_positive'),
        ];
    }

    public function attributes(): array
    {
        return __('purchase_invoices.attributes');
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validatePeriod($validator);
            $this->validateLines($validator);
            $this->validateDiscountsAndSchedule($validator);
            $this->validatePaymentSources($validator);
            $this->validateProcurementSource($validator);
        });
    }

    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);

        if ($key !== null || ! is_array($data)) {
            return $data;
        }

        foreach (['invoice_date', 'supplier_invoice_date'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = app(DateFormatService::class)->normalizeForStorage($data[$field]);
            }
        }

        foreach ($data['payment_schedules'] ?? [] as $index => $row) {
            foreach (['due_date', 'payment_date'] as $field) {
                if (array_key_exists($field, $row)) {
                    $data['payment_schedules'][$index][$field] = app(DateFormatService::class)->normalizeForStorage($row[$field]);
                }
            }
        }

        return $data;
    }

    protected function currentRecord(): ?PurchaseInvoice
    {
        return null;
    }

    private function validatePeriod(Validator $validator): void
    {
        $period = FinancialPeriod::query()
            ->where('company_id', $this->input('company_id'))
            ->where('doc_num', $this->input('financial_period_doc_num'))
            ->whereNull('deleted_at')
            ->first();
        $invoiceDate = app(DateFormatService::class)->parseDate($this->input('invoice_date'));

        if (! $period instanceof FinancialPeriod || ! $invoiceDate) {
            return;
        }

        if ($period->is_closed) {
            $validator->errors()->add('financial_period_doc_num', __('purchase_invoices.messages.period_closed'));
        }

        if ($period->from_date && $period->to_date && ($invoiceDate->lt($period->from_date) || $invoiceDate->gt($period->to_date))) {
            $validator->errors()->add('invoice_date', __('purchase_invoices.messages.invoice_date_outside_period'));
        }
    }

    private function validateLines(Validator $validator): void
    {
        $companyId = (int) $this->input('company_id');
        $units = app(ProductComponentUnitOptionsService::class);

        foreach ($this->input('lines', []) as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            $subtotal = app(PurchaseInvoiceCalculationService::class)->number($line['quantity'] ?? 0)
                * app(PurchaseInvoiceCalculationService::class)->number($line['unit_price'] ?? 0);
            $discountValue = app(PurchaseInvoiceCalculationService::class)->number($line['discount_value'] ?? 0);
            $discountType = $line['discount_type'] ?? null;

            if ($discountType === 'percentage' && $discountValue > 100) {
                $validator->errors()->add("lines.{$index}.discount_value", __('purchase_invoices.messages.discount_percentage_invalid'));
            }

            if ($discountType === 'fixed' && $discountValue > $subtotal) {
                $validator->errors()->add("lines.{$index}.discount_value", __('purchase_invoices.messages.line_discount_exceeds_subtotal'));
            }

            $product = Product::query()
                ->with(['unit', 'equivalentUnit'])
                ->active()
                ->purchasable()
                ->forCompany($companyId)
                ->where('doc_num', $line['product_doc_num'] ?? null)
                ->first();

            if ($product instanceof Product && ! $units->unitIsValidForProduct($product, $line['unit_doc_num'] ?? null, $companyId)) {
                $validator->errors()->add("lines.{$index}.unit_doc_num", __('purchase_invoices.messages.invalid_unit'));
            }
        }
    }

    private function validateDiscountsAndSchedule(Validator $validator): void
    {
        $calculator = app(PurchaseInvoiceCalculationService::class);
        $calculation = $calculator->calculate(
            $this->input('lines', []),
            $this->input('header_discount_type'),
            $this->input('header_discount_value'),
            $this->input('freight_amount'),
            $this->input('freight_tax_rate'),
        );
        $headerDiscountType = $this->input('header_discount_type');
        $headerDiscountValue = $calculator->number($this->input('header_discount_value'));
        $headerBase = $calculator->number($calculation['invoice']['subtotal_amount']) - $calculator->number($calculation['invoice']['line_discount_amount']);
        $total = $calculator->toUnits($calculation['invoice']['total_amount']);
        $scheduleTotal = collect($this->input('payment_schedules', []))->sum(fn (array $row): int => $calculator->toUnits($row['amount'] ?? 0));

        if ($headerDiscountType === 'percentage' && $headerDiscountValue > 100) {
            $validator->errors()->add('header_discount_value', __('purchase_invoices.messages.discount_percentage_invalid'));
        }

        if ($headerDiscountType === 'fixed' && $headerDiscountValue > $headerBase) {
            $validator->errors()->add('header_discount_value', __('purchase_invoices.messages.header_discount_exceeds_total'));
        }

        if ($this->input('payment_type') === PurchaseInvoice::PaymentTypeCash && $this->input('payment_source_type') === PurchaseInvoice::SourceBank) {
            $validator->errors()->add('payment_source_type', __('purchase_invoices.messages.bank_payment_voucher_unavailable'));
        } elseif ($this->input('payment_type') === PurchaseInvoice::PaymentTypeCash && $this->input('payment_source_type') !== PurchaseInvoice::SourceCashbox) {
            $validator->errors()->add('payment_source_type', __('purchase_invoices.messages.cashbox_required_for_cash_invoice'));
        }

        if ($this->input('payment_type') === PurchaseInvoice::PaymentTypeCash && $this->input('payment_source_type') === PurchaseInvoice::SourceCashbox && empty($this->input('cashbox_doc_num'))) {
            $validator->errors()->add('cashbox_doc_num', __('purchase_invoices.messages.cashbox_required_for_cash_invoice'));
        }

        if ($this->input('payment_type') === PurchaseInvoice::PaymentTypeCash && $scheduleTotal > 0 && $scheduleTotal !== $total) {
            $validator->errors()->add('payment_schedules', __('purchase_invoices.messages.payment_schedule_total_mismatch'));
        }

        if ($this->input('payment_type') === PurchaseInvoice::PaymentTypePartial && $scheduleTotal !== $total) {
            $validator->errors()->add('payment_schedules', __('purchase_invoices.messages.payment_schedule_total_mismatch'));
        }

        if ($this->input('payment_type') === PurchaseInvoice::PaymentTypeCredit && $scheduleTotal > 0 && $scheduleTotal !== $total) {
            $validator->errors()->add('payment_schedules', __('purchase_invoices.messages.payment_schedule_total_mismatch'));
        }
    }

    private function validatePaymentSources(Validator $validator): void
    {
        foreach ($this->input('payment_schedules', []) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $sourceType = $row['payment_source_type'] ?? PurchaseInvoice::SourceScheduled;

            if ($this->input('payment_type') === PurchaseInvoice::PaymentTypeCash && $sourceType !== PurchaseInvoice::SourceCashbox) {
                $validator->errors()->add("payment_schedules.{$index}.payment_source_type", __('purchase_invoices.messages.cashbox_required_for_cash_invoice'));
            }

            if ($this->input('payment_type') === PurchaseInvoice::PaymentTypeCash && empty($row['payment_date'])) {
                $validator->errors()->add("payment_schedules.{$index}.payment_date", __('purchase_invoices.messages.payment_date_required_for_cash_invoice'));
            }

            if ($sourceType === PurchaseInvoice::SourceCashbox && empty($row['cashbox_doc_num'])) {
                $validator->errors()->add("payment_schedules.{$index}.cashbox_doc_num", __('purchase_invoices.messages.cashbox_required_for_paid_row'));
            }

            if ($sourceType === PurchaseInvoice::SourceBank && ! empty($row['payment_date'])) {
                $validator->errors()->add("payment_schedules.{$index}.bank_account_doc_num", __('purchase_invoices.messages.bank_payment_voucher_unavailable'));
            }

            if ($sourceType === PurchaseInvoice::SourceBank && empty($row['bank_account_doc_num'])) {
                $validator->errors()->add("payment_schedules.{$index}.bank_account_doc_num", __('purchase_invoices.messages.bank_account_required'));
            }
        }
    }

    private function uniqueDocumentNumberRule(): mixed
    {
        $period = FinancialPeriod::query()
            ->where('company_id', $this->input('company_id'))
            ->where('doc_num', $this->input('financial_period_doc_num'))
            ->value('id');
        $rule = Rule::unique('purchase_invoices', 'doc_number')
            ->where(fn ($query) => $query
                ->where('company_id', $this->input('company_id'))
                ->where('financial_period_id', $period ?: 0)
                ->whereNull('deleted_at'));
        $current = $this->currentRecord();

        return $current instanceof PurchaseInvoice ? $rule->ignore($current->getKey()) : $rule;
    }

    private function uniqueSupplierInvoiceRule(): mixed
    {
        $supplierId = Supplier::query()
            ->where('company_id', $this->input('company_id'))
            ->where('doc_num', $this->input('supplier_doc_num'))
            ->value('id');
        $rule = Rule::unique('purchase_invoices', 'supplier_invoice_number')
            ->where(fn ($query) => $query
                ->where('company_id', $this->input('company_id'))
                ->where('supplier_id', $supplierId ?: 0)
                ->whereNull('deleted_at'));
        $current = $this->currentRecord();

        return $current instanceof PurchaseInvoice ? $rule->ignore($current->getKey()) : $rule;
    }

    private function validateProcurementSource(Validator $validator): void
    {
        $hasPurchaseOrder = filled($this->input('purchase_order_doc_num'));
        $isAuthorizedDirect = $this->boolean('direct_procurement_override')
            && (bool) $this->user()?->can('purchases.direct_procurement.override');

        if (! $hasPurchaseOrder && ! $isAuthorizedDirect) {
            $validator->errors()->add('purchase_order_doc_num', __('A purchase order is required unless an authorized direct-procurement override is used.'));
        }

        if ($this->boolean('direct_procurement_override') && ! $isAuthorizedDirect) {
            $validator->errors()->add('direct_procurement_override', __('You are not authorized to bypass the procurement source workflow.'));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizedLines(): array
    {
        return collect($this->input('lines', []))
            ->filter(fn (mixed $line): bool => is_array($line))
            ->map(fn (array $line): array => [
                'public_id' => trim((string) ($line['public_id'] ?? '')) ?: null,
                'product_doc_num' => trim((string) ($line['product_doc_num'] ?? '')) ?: null,
                'unit_doc_num' => trim((string) ($line['unit_doc_num'] ?? '')) ?: null,
                'purchase_order_line_public_id' => trim((string) ($line['purchase_order_line_public_id'] ?? '')) ?: null,
                'receipt_line_public_id' => trim((string) ($line['receipt_line_public_id'] ?? '')) ?: null,
                'quantity' => $this->decimalValue($line['quantity'] ?? null),
                'unit_price' => $this->decimalValue($line['unit_price'] ?? null),
                'discount_type' => trim((string) ($line['discount_type'] ?? '')) ?: null,
                'discount_value' => $this->decimalValue($line['discount_value'] ?? 0),
                'tax_rate' => $this->decimalValue($line['tax_rate'] ?? 0),
                'notes' => trim((string) ($line['notes'] ?? '')) ?: null,
            ])
            ->reject(fn (array $line): bool => $line['product_doc_num'] === null && $line['quantity'] === null && $line['unit_price'] === null)
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizedSchedules(): array
    {
        return collect($this->input('payment_schedules', []))
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): array => [
                'public_id' => trim((string) ($row['public_id'] ?? '')) ?: null,
                'due_date' => trim((string) ($row['due_date'] ?? '')) ?: null,
                'amount' => $this->decimalValue($row['amount'] ?? null),
                'payment_source_type' => trim((string) ($row['payment_source_type'] ?? '')) ?: PurchaseInvoice::SourceScheduled,
                'cashbox_doc_num' => trim((string) ($row['cashbox_doc_num'] ?? '')) ?: null,
                'bank_account_doc_num' => trim((string) ($row['bank_account_doc_num'] ?? '')) ?: null,
                'payment_date' => trim((string) ($row['payment_date'] ?? '')) ?: null,
                'notes' => trim((string) ($row['notes'] ?? '')) ?: null,
            ])
            ->reject(fn (array $row): bool => $row['due_date'] === null && $row['amount'] === null)
            ->values()
            ->all();
    }

    private function trimmed(string $field): ?string
    {
        return $this->filled($field) ? trim((string) $this->input($field)) : null;
    }

    private function decimalInput(string $field, string $default): ?string
    {
        return $this->filled($field) ? $this->decimalValue($this->input($field)) : $default;
    }

    private function decimalValue(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
