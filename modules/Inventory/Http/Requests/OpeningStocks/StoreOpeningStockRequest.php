<?php

namespace Modules\Inventory\Http\Requests\OpeningStocks;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\OpeningStock;

class StoreOpeningStockRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->filled('clone_source_token') ? 'inventory.opening_stocks.clone' : 'inventory.opening_stocks.create');
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
                'quantity' => isset($line['quantity']) ? trim((string) $line['quantity']) : null,
                'stock_status' => isset($line['stock_status']) ? trim((string) $line['stock_status']) : InventoryTransaction::StatusAvailable,
                'batch_lot' => isset($line['batch_lot']) ? trim((string) $line['batch_lot']) : null,
                'manufacture_date' => isset($line['manufacture_date']) ? trim((string) $line['manufacture_date']) : null,
                'expiry_date' => isset($line['expiry_date']) ? trim((string) $line['expiry_date']) : null,
                'notes' => isset($line['notes']) ? trim((string) $line['notes']) : null,
                '_delete' => filter_var($line['_delete'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ])
            ->values()
            ->all();

        $this->merge([
            'company_id' => $context['company_id'],
            'financial_period_id' => $context['financial_period_id'],
            'branch_id' => $context['branch_id'],
            'document_date' => $this->filled('document_date') ? trim((string) $this->input('document_date')) : null,
            'branch_hall_uuid' => $this->filled('branch_hall_uuid') ? trim((string) $this->input('branch_hall_uuid')) : null,
            'branch_store_uuid' => $this->filled('branch_store_uuid') ? trim((string) $this->input('branch_store_uuid')) : null,
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
                Rule::unique('inventory_opening_stocks', 'doc_number')
                    ->where(fn ($query) => $query
                        ->where('company_id', $this->input('company_id'))
                        ->where('financial_period_id', $this->input('financial_period_id'))
                        ->whereNull('deleted_at')),
            ],
            'document_date' => ['required', function (string $attribute, mixed $value, Closure $fail): void {
                if (! app(DateFormatService::class)->isValidDate(is_string($value) ? $value : null)) {
                    $fail(__('inventory.opening_stocks.messages.date_invalid'));
                }
            }],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'financial_period_id' => ['required', 'integer', 'exists:financial_periods,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'branch_hall_uuid' => ['nullable', 'string'],
            'branch_store_uuid' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array'],
            'lines.*.public_id' => ['nullable', 'string'],
            'lines.*.product_doc_num' => ['nullable', 'string'],
            'lines.*.quantity' => ['nullable', 'numeric', 'decimal:0,4', 'regex:/^\d{1,11}(?:\.\d{1,4})?$/D'],
            'lines.*.stock_status' => ['required', Rule::in([
                InventoryTransaction::StatusAvailable,
                InventoryTransaction::StatusQcHold,
                InventoryTransaction::StatusQuarantine,
                InventoryTransaction::StatusDamaged,
            ])],
            'lines.*.batch_lot' => ['nullable', 'string', 'max:100'],
            'lines.*.manufacture_date' => ['nullable', 'date'],
            'lines.*.expiry_date' => ['nullable', 'date', 'after_or_equal:document_date'],
            'lines.*.notes' => ['nullable', 'string'],
            'lines.*._delete' => ['nullable', 'boolean'],
            'submit_action' => ['nullable', 'string'],
            'clone_source_token' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'document_date.required' => __('inventory.opening_stocks.messages.date_required'),
            'lines.required' => __('inventory.opening_stocks.messages.lines_required'),
            'lines.array' => __('inventory.opening_stocks.messages.lines_required'),
            'lines.*.quantity.numeric' => __('inventory.opening_stocks.messages.quantity_gt_zero'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateBusiness($validator);
        });
    }

    protected function validateBusiness(Validator $validator, ?OpeningStock $current = null): void
    {
        if ($current?->isApproved()) {
            $validator->errors()->add('document', __('inventory.opening_stocks.messages.approved_edit_forbidden'));
        } elseif ($current?->isClosed()) {
            $validator->errors()->add('document', __('inventory.opening_stocks.messages.closed_edit_forbidden'));
        }

        $companyId = (int) $this->input('company_id');
        $period = FinancialPeriod::query()->find($this->input('financial_period_id'));
        $branch = Branch::query()->find($this->input('branch_id'));

        if (! $period instanceof FinancialPeriod || (int) $period->company_id !== $companyId) {
            $validator->errors()->add('financial_period_id', __('operating_context.validation.financial_period_invalid'));
        }

        if (! $branch instanceof Branch || (int) $branch->company_id !== $companyId) {
            $validator->errors()->add('branch_id', __('operating_context.validation.branch_invalid'));
        } elseif (! in_array($branch->type, [Branch::TypeWarehouse, Branch::TypeFactory], true)) {
            $validator->errors()->add('branch_id', __('inventory.opening_stocks.messages.branch_type_required'));
        }

        $this->validateDateInsidePeriod($validator, $period);
        $this->validateBranchHall($validator, $branch);
        $this->validateBranchStore($validator, $branch);
        $this->validateLines($validator, $companyId);
    }

    private function validateDateInsidePeriod(Validator $validator, ?FinancialPeriod $period): void
    {
        $documentDate = $this->normalizedDocumentDate();

        if (! $period instanceof FinancialPeriod || $documentDate === null) {
            return;
        }

        $fromDate = $period->from_date?->toDateString();
        $toDate = $period->to_date?->toDateString();

        if ($fromDate !== null && $toDate !== null && ($documentDate < $fromDate || $documentDate > $toDate)) {
            $validator->errors()->add('document_date', __('inventory.opening_stocks.messages.date_outside_period'));
        }
    }

    private function validateBranchHall(Validator $validator, ?Branch $branch): void
    {
        $uuid = trim((string) $this->input('branch_hall_uuid'));

        if ($uuid === '') {
            return;
        }

        if (! $branch instanceof Branch || $branch->type !== Branch::TypeFactory) {
            $validator->errors()->add('branch_hall_uuid', __('inventory.opening_stocks.messages.hall_invalid'));

            return;
        }

        if (! BranchHall::query()->where('branch_id', $branch->getKey())->where('public_uuid', $uuid)->whereNull('deleted_at')->exists()) {
            $validator->errors()->add('branch_hall_uuid', __('inventory.opening_stocks.messages.hall_invalid'));
        }
    }

    private function validateBranchStore(Validator $validator, ?Branch $branch): void
    {
        $uuid = trim((string) $this->input('branch_store_uuid'));

        if ($uuid === '') {
            return;
        }

        if (! $branch instanceof Branch || $branch->type !== Branch::TypeFactory) {
            $validator->errors()->add('branch_store_uuid', __('inventory.opening_stocks.messages.store_invalid'));

            return;
        }

        if (! BranchStore::query()->where('branch_id', $branch->getKey())->where('public_uuid', $uuid)->whereNull('deleted_at')->exists()) {
            $validator->errors()->add('branch_store_uuid', __('inventory.opening_stocks.messages.store_invalid'));
        }
    }

    private function validateLines(Validator $validator, int $companyId): void
    {
        $validLineCount = 0;
        $seenProducts = [];

        foreach ($this->input('lines', []) as $index => $line) {
            if (! is_array($line) || ($line['_delete'] ?? false)) {
                continue;
            }

            $productDocNum = trim((string) ($line['product_doc_num'] ?? ''));
            $quantity = $line['quantity'] ?? null;
            $batchLot = trim((string) ($line['batch_lot'] ?? ''));
            $notes = trim((string) ($line['notes'] ?? ''));

            if ($productDocNum === '' && ($quantity === null || $quantity === '') && $batchLot === '' && $notes === '') {
                continue;
            }

            $validLineCount++;

            if ($productDocNum === '') {
                $validator->errors()->add("lines.{$index}.product_doc_num", __('inventory.opening_stocks.messages.product_required'));
            } else {
                $product = $this->stockableProduct($companyId, $productDocNum);

                if (! $product instanceof Product) {
                    $validator->errors()->add("lines.{$index}.product_doc_num", __('inventory.opening_stocks.messages.product_unavailable'));
                } elseif (isset($seenProducts[$product->getKey()])) {
                    $validator->errors()->add("lines.{$index}.product_doc_num", __('inventory.opening_stocks.messages.duplicate_product'));
                } else {
                    $seenProducts[$product->getKey()] = true;
                    if ($product->tracks_expiry && blank($line['expiry_date'] ?? null)) {
                        $validator->errors()->add("lines.{$index}.expiry_date", __('An expiry date is required for expiry-tracked inventory.'));
                    }
                }
            }

            if ($quantity === null || $quantity === '') {
                $validator->errors()->add("lines.{$index}.quantity", __('inventory.opening_stocks.messages.quantity_required'));
            } elseif (! is_numeric($quantity) || (float) $quantity <= 0) {
                $validator->errors()->add("lines.{$index}.quantity", __('inventory.opening_stocks.messages.quantity_gt_zero'));
            }
        }

        if ($validLineCount === 0) {
            $validator->errors()->add('lines', __('inventory.opening_stocks.messages.lines_required'));
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

    private function normalizedDocumentDate(): ?string
    {
        $value = $this->input('document_date');

        if (! is_string($value) || ! app(DateFormatService::class)->isValidDate($value)) {
            return null;
        }

        return app(DateFormatService::class)->normalizeForStorage($value);
    }

    public function attributes(): array
    {
        return [
            'doc_number' => __('inventory.opening_stocks.attributes.doc_number'),
            'document_date' => __('inventory.opening_stocks.attributes.document_date'),
            'branch_hall_uuid' => __('inventory.opening_stocks.attributes.hall'),
            'branch_store_uuid' => __('inventory.opening_stocks.attributes.store'),
            'notes' => __('inventory.opening_stocks.attributes.notes'),
            'lines' => __('inventory.opening_stocks.attributes.lines'),
            'lines.*.product_doc_num' => __('inventory.opening_stocks.attributes.product'),
            'lines.*.quantity' => __('inventory.opening_stocks.attributes.quantity'),
            'lines.*.stock_status' => __('inventory.opening_stocks.attributes.stock_status'),
            'lines.*.batch_lot' => __('inventory.opening_stocks.attributes.batch_lot'),
            'lines.*.manufacture_date' => __('Manufacture date'),
            'lines.*.expiry_date' => __('Expiry date'),
            'lines.*.notes' => __('inventory.opening_stocks.attributes.line_notes'),
        ];
    }

    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);

        if ($key !== null || ! is_array($data)) {
            return $data;
        }

        if (array_key_exists('document_date', $data)) {
            $data['document_date'] = app(DateFormatService::class)->normalizeForStorage((string) $data['document_date']);
        }

        $data['lines'] = collect($data['lines'] ?? [])
            ->filter(fn (array $line): bool => ! ($line['_delete'] ?? false))
            ->filter(function (array $line): bool {
                return trim((string) ($line['product_doc_num'] ?? '')) !== ''
                    || trim((string) ($line['quantity'] ?? '')) !== ''
                    || trim((string) ($line['batch_lot'] ?? '')) !== ''
                    || trim((string) ($line['notes'] ?? '')) !== '';
            })
            ->values()
            ->all();

        return $data;
    }
}
