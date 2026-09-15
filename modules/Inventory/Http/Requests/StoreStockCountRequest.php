<?php

namespace Modules\Inventory\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\StockCount;

class StoreStockCountRequest extends FormRequest
{
    use NormalizesNumericInput;

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput(['lines.*.physical_quantity']);

        $context = app(OperatingContextService::class)->snapshot($this);
        $lines = collect($this->input('lines', []))
            ->filter(fn (mixed $line): bool => is_array($line))
            ->map(fn (array $line): array => [
                'line_id' => isset($line['line_id']) && $line['line_id'] !== '' ? (int) $line['line_id'] : null,
                'product_doc_num' => $this->nullableText($line['product_doc_num'] ?? null),
                'physical_quantity' => $this->nullableText($line['physical_quantity'] ?? null),
                'stock_status' => $this->nullableText($line['stock_status'] ?? null) ?? InventoryTransaction::StatusAvailable,
                'batch_lot' => $this->nullableText($line['batch_lot'] ?? null),
                'variance_reason' => $this->nullableText($line['variance_reason'] ?? null),
                'notes' => $this->nullableText($line['notes'] ?? null),
                '_delete' => filter_var($line['_delete'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ])
            ->values()
            ->all();

        $this->merge([
            'company_id' => $context['company_id'],
            'financial_period_id' => $context['financial_period_id'],
            'branch_id' => $context['branch_id'],
            'count_date' => $this->nullableText($this->input('count_date')),
            'notes' => $this->nullableText($this->input('notes')),
            'lines' => $lines,
        ]);
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->filled('clone_source_token') ? 'inventory.stock_counts.clone' : 'inventory.stock_counts.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $stockCount = $this->route('stockCount');
        $stockCountId = $stockCount instanceof StockCount ? $stockCount->getKey() : 0;

        return [
            'doc_number' => [
                'nullable',
                'integer',
                'min:1',
                Rule::unique('inventory_stock_counts', 'doc_number')
                    ->ignore($stockCountId)
                    ->where(fn ($query) => $query
                        ->where('company_id', $this->input('company_id'))
                        ->where('financial_period_id', $this->input('financial_period_id'))
                        ->whereNull('deleted_at')),
            ],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'financial_period_id' => ['required', 'integer', 'exists:financial_periods,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'branch_store_id' => [
                'required',
                'integer',
                Rule::exists('branch_stores', 'id')->where(fn ($query) => $query
                    ->where('branch_id', $this->input('branch_id'))
                    ->whereNull('deleted_at')),
            ],
            'warehouse_location_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouse_locations', 'id')->where(fn ($query) => $query
                    ->where('branch_store_id', $this->input('branch_store_id'))
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ],
            'count_date' => ['required', function (string $attribute, mixed $value, Closure $fail): void {
                if (! app(DateFormatService::class)->isValidDate(is_string($value) ? $value : null)) {
                    $fail(__('inventory.stock_counts.messages.date_invalid'));
                }
            }],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array'],
            'lines.*.line_id' => [
                'nullable',
                'integer',
                Rule::exists('inventory_stock_count_lines', 'id')->where('inventory_stock_count_id', $stockCountId),
            ],
            'lines.*.product_doc_num' => ['nullable', 'string', 'max:100'],
            'lines.*.physical_quantity' => ['nullable', 'numeric', 'min:0', 'decimal:0,8'],
            'lines.*.stock_status' => ['required', Rule::in($this->stockStatuses())],
            'lines.*.batch_lot' => ['nullable', 'string', 'max:100'],
            'lines.*.variance_reason' => ['nullable', 'string', 'max:100'],
            'lines.*.notes' => ['nullable', 'string', 'max:5000'],
            'lines.*._delete' => ['nullable', 'boolean'],
            'submit_action' => ['nullable', 'string'],
            'clone_source_token' => ['nullable', 'string'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateBusiness($validator)];
    }

    protected function validateBusiness(Validator $validator): void
    {
        $companyId = (int) $this->input('company_id');
        $period = FinancialPeriod::query()->find($this->input('financial_period_id'));

        if (! $period instanceof FinancialPeriod || (int) $period->company_id !== $companyId || $period->is_closed) {
            $validator->errors()->add('financial_period_id', __('inventory.stock_counts.messages.period_invalid'));
        }

        $date = $this->normalizedCountDate();
        if ($period instanceof FinancialPeriod && $date !== null) {
            $from = $period->from_date?->toDateString();
            $to = $period->to_date?->toDateString();
            if (($from !== null && $date < $from) || ($to !== null && $date > $to)) {
                $validator->errors()->add('count_date', __('inventory.stock_counts.messages.date_outside_period'));
            }
        }

        $lines = collect($this->input('lines', []))
            ->filter(fn (mixed $line): bool => is_array($line) && ! ($line['_delete'] ?? false))
            ->filter(fn (array $line): bool => $this->lineHasContent($line))
            ->values();

        if ($lines->isEmpty()) {
            $validator->errors()->add('lines', __('inventory.stock_counts.messages.lines_required'));

            return;
        }

        $products = Product::query()
            ->active()
            ->nonService()
            ->forCompany($companyId)
            ->whereIn('doc_num', $lines->pluck('product_doc_num')->filter()->unique())
            ->get(['id', 'doc_num'])
            ->keyBy('doc_num');
        $seen = [];

        foreach ($lines as $index => $line) {
            $productDocNum = trim((string) ($line['product_doc_num'] ?? ''));
            $physicalQuantity = $line['physical_quantity'] ?? null;

            if ($productDocNum === '') {
                $validator->errors()->add("lines.{$index}.product_doc_num", __('inventory.stock_counts.messages.product_required'));
            } elseif (! $products->has($productDocNum)) {
                $validator->errors()->add("lines.{$index}.product_doc_num", __('inventory.stock_counts.messages.product_unavailable'));
            } else {
                $key = implode('|', [
                    $products->get($productDocNum)->getKey(),
                    $line['stock_status'] ?? '',
                    mb_strtolower(trim((string) ($line['batch_lot'] ?? ''))),
                ]);
                if (isset($seen[$key])) {
                    $validator->errors()->add("lines.{$index}.product_doc_num", __('inventory.stock_counts.messages.duplicate_position'));
                }
                $seen[$key] = true;
            }

            if ($physicalQuantity === null || $physicalQuantity === '') {
                $validator->errors()->add("lines.{$index}.physical_quantity", __('inventory.stock_counts.messages.physical_quantity_required'));
            }
        }
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'doc_number' => __('inventory.stock_counts.attributes.doc_number'),
            'branch_store_id' => __('inventory.stock_counts.attributes.store'),
            'warehouse_location_id' => __('inventory.stock_counts.attributes.location'),
            'count_date' => __('inventory.stock_counts.attributes.count_date'),
            'notes' => __('inventory.stock_counts.attributes.notes'),
            'lines' => __('inventory.stock_counts.attributes.lines'),
            'lines.*.product_doc_num' => __('inventory.stock_counts.attributes.product'),
            'lines.*.physical_quantity' => __('inventory.stock_counts.attributes.physical_quantity'),
            'lines.*.stock_status' => __('inventory.stock_counts.attributes.stock_status'),
            'lines.*.batch_lot' => __('inventory.stock_counts.attributes.batch_lot'),
            'lines.*.variance_reason' => __('inventory.stock_counts.attributes.variance_reason'),
            'lines.*.notes' => __('inventory.stock_counts.attributes.line_notes'),
        ];
    }

    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);

        if ($key !== null || ! is_array($data)) {
            return $data;
        }

        $data['count_date'] = app(DateFormatService::class)->normalizeForStorage((string) $data['count_date']);
        $data['lines'] = collect($data['lines'] ?? [])
            ->filter(fn (array $line): bool => ! ($line['_delete'] ?? false) && $this->lineHasContent($line))
            ->values()
            ->all();

        return $data;
    }

    /** @return list<string> */
    private function stockStatuses(): array
    {
        return [
            InventoryTransaction::StatusAvailable,
            InventoryTransaction::StatusProductionStaging,
            InventoryTransaction::StatusQcHold,
            InventoryTransaction::StatusQuarantine,
            InventoryTransaction::StatusDamaged,
        ];
    }

    private function normalizedCountDate(): ?string
    {
        $value = $this->input('count_date');

        return is_string($value) && app(DateFormatService::class)->isValidDate($value)
            ? app(DateFormatService::class)->normalizeForStorage($value)
            : null;
    }

    /** @param array<string, mixed> $line */
    private function lineHasContent(array $line): bool
    {
        return collect(['product_doc_num', 'physical_quantity', 'batch_lot', 'variance_reason', 'notes'])
            ->contains(fn (string $field): bool => trim((string) ($line[$field] ?? '')) !== '');
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
