<?php

namespace Modules\Inventory\Http\Requests\UnpricedInventoryReceipts;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\ProductComponentUnitOptionsService;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Purchases\Models\Supplier;

class StoreUnpricedInventoryReceiptRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('inventory.unpriced_inventory_receipts.create');
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput([
            'lines.*.quantity',
        ]);

        $context = app(OperatingContextService::class)->snapshot($this);
        $lines = collect($this->input('lines', []))
            ->filter(fn (mixed $line): bool => is_array($line))
            ->map(fn (array $line): array => [
                'public_id' => isset($line['public_id']) ? trim((string) $line['public_id']) : null,
                'product_doc_num' => isset($line['product_doc_num']) ? trim((string) $line['product_doc_num']) : null,
                'unit_doc_num' => isset($line['unit_doc_num']) ? trim((string) $line['unit_doc_num']) : null,
                'quantity' => isset($line['quantity']) ? $this->normalizeDecimalInput($line['quantity']) : null,
                'notes' => isset($line['notes']) ? trim((string) $line['notes']) : null,
                '_delete' => filter_var($line['_delete'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ])
            ->values()
            ->all();

        $this->merge([
            'company_id' => $context['company_id'],
            'financial_period_id' => $context['financial_period_id'],
            'branch_doc_num' => $this->filled('branch_doc_num') ? trim((string) $this->input('branch_doc_num')) : null,
            'branch_hall_uuid' => $this->filled('branch_hall_uuid') ? trim((string) $this->input('branch_hall_uuid')) : null,
            'branch_store_uuid' => $this->filled('branch_store_uuid') ? trim((string) $this->input('branch_store_uuid')) : null,
            'supplier_doc_num' => $this->filled('supplier_doc_num') ? trim((string) $this->input('supplier_doc_num')) : null,
            'document_date' => $this->filled('document_date') ? trim((string) $this->input('document_date')) : null,
            'reference_number' => $this->filled('reference_number') ? trim((string) $this->input('reference_number')) : null,
            'reference_date' => $this->filled('reference_date') ? trim((string) $this->input('reference_date')) : null,
            'notes' => $this->filled('notes') ? trim((string) $this->input('notes')) : null,
            'lines' => $lines,
        ]);
    }

    public function rules(): array
    {
        return [
            'doc_number' => [
                'nullable',
                'integer',
                'min:1',
                Rule::unique('unpriced_inventory_receipts', 'doc_number')
                    ->where(fn ($query) => $query
                        ->where('company_id', $this->input('company_id'))
                        ->where('financial_period_id', $this->input('financial_period_id'))
                        ->whereNull('deleted_at')),
            ],
            'document_date' => ['required', function (string $attribute, mixed $value, Closure $fail): void {
                if (! app(DateFormatService::class)->isValidDate(is_string($value) ? $value : null)) {
                    $fail(__('inventory.unpriced_inventory_receipts.messages.date_invalid'));
                }
            }],
            'reference_date' => ['nullable', function (string $attribute, mixed $value, Closure $fail): void {
                if ($value !== null && $value !== '' && ! app(DateFormatService::class)->isValidDate(is_string($value) ? $value : null)) {
                    $fail(__('inventory.unpriced_inventory_receipts.messages.reference_date_invalid'));
                }
            }],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'financial_period_id' => ['required', 'integer', 'exists:financial_periods,id'],
            'branch_doc_num' => ['required', 'string'],
            'branch_hall_uuid' => ['nullable', 'string'],
            'branch_store_uuid' => ['nullable', 'string'],
            'supplier_doc_num' => ['nullable', 'string'],
            'reference_number' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array'],
            'lines.*.public_id' => ['nullable', 'string'],
            'lines.*.product_doc_num' => ['nullable', 'string'],
            'lines.*.unit_doc_num' => ['nullable', 'string'],
            'lines.*.quantity' => ['nullable', 'numeric', 'decimal:0,8', 'regex:/^\d{1,12}(?:\.\d{1,8})?$/D'],
            'lines.*.notes' => ['nullable', 'string'],
            'lines.*._delete' => ['nullable', 'boolean'],
            'submit_action' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'document_date.required' => __('inventory.unpriced_inventory_receipts.messages.date_required'),
            'branch_doc_num.required' => __('inventory.unpriced_inventory_receipts.messages.branch_required'),
            'lines.required' => __('inventory.unpriced_inventory_receipts.messages.lines_required'),
            'lines.array' => __('inventory.unpriced_inventory_receipts.messages.lines_required'),
            'lines.*.quantity.numeric' => __('inventory.unpriced_inventory_receipts.messages.quantity_gt_zero'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateBusiness($validator);
        });
    }

    protected function validateBusiness(Validator $validator, ?UnpricedInventoryReceipt $current = null): void
    {
        if ($current?->isApproved()) {
            $validator->errors()->add('document', __('inventory.unpriced_inventory_receipts.messages.approved_edit_forbidden'));
        } elseif ($current?->isClosed()) {
            $validator->errors()->add('document', __('inventory.unpriced_inventory_receipts.messages.closed_edit_forbidden'));
        } elseif ($current?->isCancelled()) {
            $validator->errors()->add('document', __('inventory.unpriced_inventory_receipts.messages.cancelled_edit_forbidden'));
        }

        $companyId = (int) $this->input('company_id');
        $period = FinancialPeriod::query()->find($this->input('financial_period_id'));
        $branch = $this->selectedBranch();

        if (! $period instanceof FinancialPeriod || (int) $period->company_id !== $companyId) {
            $validator->errors()->add('financial_period_id', __('operating_context.validation.financial_period_invalid'));
        } elseif ((bool) $period->is_closed) {
            $validator->errors()->add('financial_period_id', __('inventory.unpriced_inventory_receipts.messages.period_closed'));
        }

        if (! $branch instanceof Branch) {
            $validator->errors()->add('branch_doc_num', __('inventory.unpriced_inventory_receipts.messages.branch_unavailable'));
        } elseif ((int) $branch->company_id !== $companyId) {
            $validator->errors()->add('branch_doc_num', __('operating_context.validation.branch_invalid'));
        } elseif (! in_array($branch->type, [Branch::TypeWarehouse, Branch::TypeFactory], true)) {
            $validator->errors()->add('branch_doc_num', __('inventory.unpriced_inventory_receipts.messages.branch_type_required'));
        }

        $this->validateDateInsidePeriod($validator, $period);
        $this->validateReferenceDate($validator);
        $this->validateBranchHall($validator, $branch);
        $this->validateBranchStore($validator, $branch);
        $this->validateSupplier($validator, $companyId);
        $this->validateLines($validator, $companyId);
    }

    private function selectedBranch(): ?Branch
    {
        $docNum = trim((string) $this->input('branch_doc_num'));

        if ($docNum === '') {
            return null;
        }

        return app(OperatingContextService::class)
            ->allowedBranchQueryForCurrentCompany($this)
            ->where('branches.doc_num', $docNum)
            ->where('branches.status', 'active')
            ->whereIn('branches.type', [Branch::TypeWarehouse, Branch::TypeFactory])
            ->first();
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
            $validator->errors()->add('document_date', __('inventory.unpriced_inventory_receipts.messages.date_outside_period'));
        }
    }

    private function validateReferenceDate(Validator $validator): void
    {
        $referenceDate = $this->input('reference_date');

        if ($referenceDate !== null && $referenceDate !== '' && $this->normalizedDate('reference_date') === null) {
            $validator->errors()->add('reference_date', __('inventory.unpriced_inventory_receipts.messages.reference_date_invalid'));
        }
    }

    private function validateBranchHall(Validator $validator, ?Branch $branch): void
    {
        $uuid = trim((string) $this->input('branch_hall_uuid'));

        if ($uuid === '') {
            return;
        }

        if (! $branch instanceof Branch || $branch->type !== Branch::TypeFactory) {
            $validator->errors()->add('branch_hall_uuid', __('inventory.unpriced_inventory_receipts.messages.hall_invalid'));

            return;
        }

        if (! BranchHall::query()->where('branch_id', $branch->getKey())->where('public_uuid', $uuid)->whereNull('deleted_at')->exists()) {
            $validator->errors()->add('branch_hall_uuid', __('inventory.unpriced_inventory_receipts.messages.hall_invalid'));
        }
    }

    private function validateSupplier(Validator $validator, int $companyId): void
    {
        $docNum = trim((string) $this->input('supplier_doc_num'));

        if ($docNum === '') {
            return;
        }

        if (! Supplier::query()->active()->forCompany($companyId)->where('doc_num', $docNum)->exists()) {
            $validator->errors()->add('supplier_doc_num', __('inventory.unpriced_inventory_receipts.messages.supplier_unavailable'));
        }
    }

    private function validateBranchStore(Validator $validator, ?Branch $branch): void
    {
        $uuid = trim((string) $this->input('branch_store_uuid'));

        if ($uuid === '') {
            return;
        }

        if (! $branch instanceof Branch || $branch->type !== Branch::TypeFactory) {
            $validator->errors()->add('branch_store_uuid', __('inventory.unpriced_inventory_receipts.messages.store_invalid'));

            return;
        }

        if (! BranchStore::query()->where('branch_id', $branch->getKey())->where('public_uuid', $uuid)->whereNull('deleted_at')->exists()) {
            $validator->errors()->add('branch_store_uuid', __('inventory.unpriced_inventory_receipts.messages.store_invalid'));
        }
    }

    private function validateLines(Validator $validator, int $companyId): void
    {
        $validLineCount = 0;

        foreach ($this->input('lines', []) as $index => $line) {
            if (! is_array($line) || ($line['_delete'] ?? false)) {
                continue;
            }

            $productDocNum = trim((string) ($line['product_doc_num'] ?? ''));
            $unitDocNum = trim((string) ($line['unit_doc_num'] ?? ''));
            $quantity = $line['quantity'] ?? null;
            $notes = trim((string) ($line['notes'] ?? ''));

            if ($productDocNum === '' && $unitDocNum === '' && ($quantity === null || $quantity === '') && $notes === '') {
                continue;
            }

            $validLineCount++;
            $product = null;

            if ($productDocNum === '') {
                $validator->errors()->add("lines.{$index}.product_doc_num", __('inventory.unpriced_inventory_receipts.messages.product_required'));
            } else {
                $product = $this->stockableProduct($companyId, $productDocNum);

                if (! $product instanceof Product) {
                    $validator->errors()->add("lines.{$index}.product_doc_num", __('inventory.unpriced_inventory_receipts.messages.product_unavailable'));
                }
            }

            if ($unitDocNum === '') {
                $validator->errors()->add("lines.{$index}.unit_doc_num", __('inventory.unpriced_inventory_receipts.messages.unit_required'));
            } elseif ($product instanceof Product && ! app(ProductComponentUnitOptionsService::class)->unitIsValidForProduct($product, $unitDocNum, $companyId)) {
                $validator->errors()->add("lines.{$index}.unit_doc_num", __('inventory.unpriced_inventory_receipts.messages.unit_invalid'));
            } elseif ($unitDocNum !== '' && ! ItemUnit::query()->forCompany($companyId)->where('doc_num', $unitDocNum)->exists()) {
                $validator->errors()->add("lines.{$index}.unit_doc_num", __('inventory.unpriced_inventory_receipts.messages.unit_invalid'));
            }

            if ($quantity === null || $quantity === '') {
                $validator->errors()->add("lines.{$index}.quantity", __('inventory.unpriced_inventory_receipts.messages.quantity_required'));
            } elseif (! is_numeric($quantity) || (float) $quantity <= 0) {
                $validator->errors()->add("lines.{$index}.quantity", __('inventory.unpriced_inventory_receipts.messages.quantity_gt_zero'));
            }
        }

        if ($validLineCount === 0) {
            $validator->errors()->add('lines', __('inventory.unpriced_inventory_receipts.messages.lines_required'));
        }
    }

    private function stockableProduct(int $companyId, string $docNum): ?Product
    {
        return Product::query()
            ->active()
            ->nonService()
            ->forCompany($companyId)
            ->where('doc_num', $docNum)
            ->first();
    }

    private function normalizedDate(string $field): ?string
    {
        $value = $this->input($field);

        if (! is_string($value) || ! app(DateFormatService::class)->isValidDate($value)) {
            return null;
        }

        return app(DateFormatService::class)->normalizeForStorage($value);
    }

    private function normalizeDecimalInput(mixed $value): ?string
    {
        $value = trim((string) $value);

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

    public function attributes(): array
    {
        return [
            'doc_number' => __('inventory.unpriced_inventory_receipts.attributes.doc_number'),
            'document_date' => __('inventory.unpriced_inventory_receipts.attributes.document_date'),
            'branch_doc_num' => __('inventory.unpriced_inventory_receipts.attributes.branch'),
            'branch_hall_uuid' => __('inventory.unpriced_inventory_receipts.attributes.hall'),
            'branch_store_uuid' => __('inventory.unpriced_inventory_receipts.attributes.store'),
            'supplier_doc_num' => __('inventory.unpriced_inventory_receipts.attributes.supplier'),
            'reference_number' => __('inventory.unpriced_inventory_receipts.attributes.reference_number'),
            'reference_date' => __('inventory.unpriced_inventory_receipts.attributes.reference_date'),
            'notes' => __('inventory.unpriced_inventory_receipts.attributes.notes'),
            'lines' => __('inventory.unpriced_inventory_receipts.attributes.lines'),
            'lines.*.product_doc_num' => __('inventory.unpriced_inventory_receipts.attributes.product'),
            'lines.*.unit_doc_num' => __('inventory.unpriced_inventory_receipts.attributes.unit'),
            'lines.*.quantity' => __('inventory.unpriced_inventory_receipts.attributes.quantity'),
            'lines.*.notes' => __('inventory.unpriced_inventory_receipts.attributes.line_notes'),
        ];
    }

    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);

        if ($key !== null || ! is_array($data)) {
            return $data;
        }

        foreach (['document_date', 'reference_date'] as $field) {
            if (array_key_exists($field, $data) && $data[$field]) {
                $data[$field] = app(DateFormatService::class)->normalizeForStorage((string) $data[$field]);
            }
        }

        $data['lines'] = collect($data['lines'] ?? [])
            ->filter(fn (array $line): bool => ! ($line['_delete'] ?? false))
            ->filter(function (array $line): bool {
                return trim((string) ($line['product_doc_num'] ?? '')) !== ''
                    || trim((string) ($line['unit_doc_num'] ?? '')) !== ''
                    || trim((string) ($line['quantity'] ?? '')) !== ''
                    || trim((string) ($line['notes'] ?? '')) !== '';
            })
            ->values()
            ->all();

        return $data;
    }
}
