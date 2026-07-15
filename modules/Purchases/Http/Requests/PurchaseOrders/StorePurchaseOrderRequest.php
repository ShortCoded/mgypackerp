<?php

namespace Modules\Purchases\Http\Requests\PurchaseOrders;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\ProductComponentUnitOptionsService;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\Supplier;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('purchase_orders.create');
    }

    protected function prepareForValidation(): void
    {
        $context = app(OperatingContextService::class)->snapshot($this);

        $this->merge([
            'doc_number' => $this->user()?->can('purchase_orders.document_number.control')
                ? $this->decimalValue($this->input('doc_number'))
                : null,
            'company_id' => $context['company_id'],
            'financial_period_id' => $context['financial_period_id'],
            'branch_id' => $context['branch_id'],
            'branch_store_uuid' => $this->trimmed('branch_store_uuid'),
            'supplier_doc_num' => $this->trimmed('supplier_doc_num'),
            'currency_doc_num' => $this->trimmed('currency_doc_num'),
            'document_date' => $this->trimmed('document_date'),
            'exchange_rate' => $this->decimalInput('exchange_rate', '1'),
            'expected_delivery_date' => $this->trimmed('expected_delivery_date'),
            'supplier_reference' => $this->trimmed('supplier_reference'),
            'notes' => $this->trimmed('notes'),
            'lines' => $this->normalizedLines(),
        ]);
    }

    public function rules(): array
    {
        $companyId = $this->input('company_id');

        return [
            'doc_number' => ['nullable', 'integer', 'min:1', $this->uniqueDocumentNumberRule()],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'financial_period_id' => ['required', 'integer', 'exists:financial_periods,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'branch_store_uuid' => ['required', 'string'],
            'supplier_doc_num' => [
                'required',
                'string',
                Rule::exists('suppliers', 'doc_num')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at')),
            ],
            'currency_doc_num' => [
                'required',
                'string',
                Rule::exists('currencies', 'doc_num')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at')),
            ],
            'document_date' => ['required', function (string $attribute, mixed $value, Closure $fail): void {
                if (! app(DateFormatService::class)->isValidDate(is_string($value) ? $value : null)) {
                    $fail(__('purchase_orders.messages.document_date_invalid'));
                }
            }],
            'exchange_rate' => ['required', 'numeric', 'gt:0'],
            'expected_delivery_date' => ['nullable', function (string $attribute, mixed $value, Closure $fail): void {
                if ($value !== null && $value !== '' && ! app(DateFormatService::class)->isValidDate(is_string($value) ? $value : null)) {
                    $fail(__('purchase_orders.messages.expected_delivery_date_invalid'));
                }
            }],
            'supplier_reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.public_id' => ['nullable', 'string'],
            'lines.*.product_doc_num' => [
                'required',
                'string',
                Rule::exists('products', 'doc_num')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at')),
            ],
            'lines.*.unit_doc_num' => ['required', 'string'],
            'lines.*.ordered_quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.notes' => ['nullable', 'string'],
            'submit_action' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'branch_store_uuid.required' => __('purchase_orders.messages.store_required'),
            'lines.required' => __('purchase_orders.messages.lines_required'),
            'lines.min' => __('purchase_orders.messages.lines_required'),
            'lines.*.ordered_quantity.gt' => __('purchase_orders.messages.quantity_gt_zero'),
            'lines.*.unit_price.min' => __('purchase_orders.messages.unit_price_positive'),
        ];
    }

    public function attributes(): array
    {
        return __('purchase_orders.attributes');
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateBusiness($validator, $this->currentRecord());
        });
    }

    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);

        if ($key !== null || ! is_array($data)) {
            return $data;
        }

        foreach (['document_date', 'expected_delivery_date'] as $field) {
            if (array_key_exists($field, $data) && $data[$field]) {
                $data[$field] = app(DateFormatService::class)->normalizeForStorage((string) $data[$field]);
            }
        }

        return $data;
    }

    protected function currentRecord(): ?PurchaseOrder
    {
        return null;
    }

    protected function validateBusiness(Validator $validator, ?PurchaseOrder $current = null): void
    {
        if ($current instanceof PurchaseOrder && $current->isLockedForEditing()) {
            $validator->errors()->add('document', __('purchase_orders.messages.document_locked'));
        }

        $companyId = (int) $this->input('company_id');
        $branchId = (int) $this->input('branch_id');
        $period = FinancialPeriod::query()->find($this->input('financial_period_id'));
        $branch = Branch::query()
            ->where('id', $branchId)
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first();

        if (! $period instanceof FinancialPeriod || (int) $period->company_id !== $companyId) {
            $validator->errors()->add('financial_period_id', __('operating_context.validation.financial_period_invalid'));
        } elseif ((bool) $period->is_closed) {
            $validator->errors()->add('financial_period_id', __('purchase_orders.messages.period_closed'));
        }

        if (! $branch instanceof Branch) {
            $validator->errors()->add('branch_id', __('operating_context.validation.branch_invalid'));
        }

        $this->validateDateInsidePeriod($validator, $period);
        $this->validateExpectedDeliveryDate($validator);
        $this->validateBranchStore($validator, $branch);
        $this->validateSupplier($validator, $companyId);
        $this->validateLines($validator, $companyId);
    }

    private function validateDateInsidePeriod(Validator $validator, ?FinancialPeriod $period): void
    {
        $documentDate = $this->normalizedDate('document_date');

        if (! $period instanceof FinancialPeriod || $documentDate === null) {
            return;
        }

        $fromDate = $period->from_date?->toDateString();
        $toDate = $period->to_date?->toDateString();

        if ($fromDate !== null && $toDate !== null && ($documentDate < $fromDate || $documentDate > $toDate)) {
            $validator->errors()->add('document_date', __('purchase_orders.messages.document_date_outside_period'));
        }
    }

    private function validateExpectedDeliveryDate(Validator $validator): void
    {
        $documentDate = $this->normalizedDate('document_date');
        $expectedDeliveryDate = $this->normalizedDate('expected_delivery_date');

        if ($documentDate !== null && $expectedDeliveryDate !== null && $expectedDeliveryDate < $documentDate) {
            $validator->errors()->add('expected_delivery_date', __('purchase_orders.messages.expected_delivery_before_document'));
        }
    }

    private function validateBranchStore(Validator $validator, ?Branch $branch): void
    {
        $uuid = trim((string) $this->input('branch_store_uuid'));

        if ($uuid === '') {
            return;
        }

        if (! $branch instanceof Branch || ! BranchStore::query()->where('branch_id', $branch->getKey())->where('public_uuid', $uuid)->whereNull('deleted_at')->exists()) {
            $validator->errors()->add('branch_store_uuid', __('purchase_orders.messages.store_unavailable'));
        }
    }

    private function validateSupplier(Validator $validator, int $companyId): void
    {
        $docNum = trim((string) $this->input('supplier_doc_num'));

        if ($docNum === '') {
            return;
        }

        if (! Supplier::query()->active()->forCompany($companyId)->where('doc_num', $docNum)->exists()) {
            $validator->errors()->add('supplier_doc_num', __('purchase_orders.messages.supplier_unavailable'));
        }
    }

    private function validateLines(Validator $validator, int $companyId): void
    {
        $units = app(ProductComponentUnitOptionsService::class);

        foreach ($this->input('lines', []) as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            $product = Product::query()
                ->with(['unit', 'equivalentUnit'])
                ->active()
                ->nonService()
                ->forCompany($companyId)
                ->where('doc_num', $line['product_doc_num'] ?? null)
                ->first();

            if (! $product instanceof Product) {
                $validator->errors()->add("lines.{$index}.product_doc_num", __('purchase_orders.messages.product_unavailable'));
            } elseif (! $units->unitIsValidForProduct($product, $line['unit_doc_num'] ?? null, $companyId)) {
                $validator->errors()->add("lines.{$index}.unit_doc_num", __('purchase_orders.messages.invalid_unit'));
            } elseif (trim((string) ($line['unit_doc_num'] ?? '')) !== '' && ! ItemUnit::query()->forCompany($companyId)->where('doc_num', $line['unit_doc_num'])->exists()) {
                $validator->errors()->add("lines.{$index}.unit_doc_num", __('purchase_orders.messages.invalid_unit'));
            }
        }
    }

    private function uniqueDocumentNumberRule(): mixed
    {
        $rule = Rule::unique('purchase_orders', 'doc_number')
            ->where(fn ($query) => $query
                ->where('company_id', $this->input('company_id'))
                ->where('financial_period_id', $this->input('financial_period_id'))
                ->whereNull('deleted_at'));
        $current = $this->currentRecord();

        return $current instanceof PurchaseOrder ? $rule->ignore($current->getKey()) : $rule;
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
                'ordered_quantity' => $this->decimalValue($line['ordered_quantity'] ?? null),
                'unit_price' => $this->decimalValue($line['unit_price'] ?? null),
                'notes' => trim((string) ($line['notes'] ?? '')) ?: null,
            ])
            ->reject(fn (array $line): bool => $line['product_doc_num'] === null && $line['ordered_quantity'] === null && $line['unit_price'] === null)
            ->values()
            ->all();
    }

    private function normalizedDate(string $field): ?string
    {
        $value = $this->input($field);

        if (! is_string($value) || ! app(DateFormatService::class)->isValidDate($value)) {
            return null;
        }

        return app(DateFormatService::class)->normalizeForStorage($value);
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
        $value = trim(str_replace(',', '', (string) ($value ?? '')));

        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, '.')) {
            return '0'.$value;
        }

        if (str_starts_with($value, '-.')) {
            return '-0'.substr($value, 1);
        }

        return $value;
    }
}
