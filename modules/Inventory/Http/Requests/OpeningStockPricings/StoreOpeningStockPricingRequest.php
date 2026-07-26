<?php

namespace Modules\Inventory\Http\Requests\OpeningStockPricings;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockLine;
use Modules\Inventory\Models\OpeningStockPricing;
use Modules\Inventory\Services\OpeningStockPricingService;

class StoreOpeningStockPricingRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->filled('clone_source_token') ? 'inventory.opening_stock_pricings.clone' : 'inventory.opening_stock_pricings.create');
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput([
            'exchange_rate',
            'lines.*.unit_price',
        ]);

        $context = app(OperatingContextService::class)->snapshot($this);
        $lines = collect($this->input('lines', []))
            ->filter(fn (mixed $line): bool => is_array($line))
            ->map(fn (array $line): array => [
                'public_id' => isset($line['public_id']) ? trim((string) $line['public_id']) : null,
                'opening_stock_line_public_id' => isset($line['opening_stock_line_public_id']) ? trim((string) $line['opening_stock_line_public_id']) : null,
                'unit_price' => isset($line['unit_price']) ? trim((string) $line['unit_price']) : null,
                'notes' => isset($line['notes']) ? trim((string) $line['notes']) : null,
                '_delete' => filter_var($line['_delete'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ])
            ->values()
            ->all();

        $this->merge([
            'company_id' => $context['company_id'],
            'financial_period_id' => $context['financial_period_id'],
            'document_date' => $this->filled('document_date') ? trim((string) $this->input('document_date')) : null,
            'branch_doc_num' => $this->filled('branch_doc_num') ? trim((string) $this->input('branch_doc_num')) : null,
            'branch_hall_uuid' => $this->filled('branch_hall_uuid') ? trim((string) $this->input('branch_hall_uuid')) : null,
            'opening_stock_doc_num' => $this->filled('opening_stock_doc_num') ? trim((string) $this->input('opening_stock_doc_num')) : null,
            'currency_doc_num' => $this->filled('currency_doc_num') ? trim((string) $this->input('currency_doc_num')) : null,
            'exchange_rate' => $this->filled('exchange_rate') ? trim((string) $this->input('exchange_rate')) : null,
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
                Rule::unique('inventory_opening_stock_pricings', 'doc_number')
                    ->where(fn ($query) => $query
                        ->where('company_id', $this->input('company_id'))
                        ->where('financial_period_id', $this->input('financial_period_id'))
                        ->whereNull('deleted_at')),
            ],
            'document_date' => ['required', function (string $attribute, mixed $value, Closure $fail): void {
                if (! app(DateFormatService::class)->isValidDate(is_string($value) ? $value : null)) {
                    $fail(__('inventory.opening_stock_pricings.messages.date_invalid'));
                }
            }],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'financial_period_id' => ['required', 'integer', 'exists:financial_periods,id'],
            'branch_doc_num' => ['required', 'string'],
            'branch_hall_uuid' => ['nullable', 'string'],
            'opening_stock_doc_num' => ['required', 'string'],
            'currency_doc_num' => ['required', 'string'],
            'exchange_rate' => ['required', 'numeric', 'decimal:0,6', 'regex:/^\d{1,12}(?:\.\d{1,6})?$/D', 'gt:0'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array'],
            'lines.*.public_id' => ['nullable', 'string'],
            'lines.*.opening_stock_line_public_id' => ['nullable', 'string'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'decimal:0,4', 'regex:/^\d{1,11}(?:\.\d{1,4})?$/D'],
            'lines.*.notes' => ['nullable', 'string'],
            'lines.*._delete' => ['nullable', 'boolean'],
            'submit_action' => ['nullable', 'string'],
            'clone_source_token' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'document_date.required' => __('inventory.opening_stock_pricings.messages.date_required'),
            'branch_doc_num.required' => __('inventory.opening_stock_pricings.messages.branch_required'),
            'opening_stock_doc_num.required' => __('inventory.opening_stock_pricings.messages.opening_stock_required'),
            'lines.required' => __('inventory.opening_stock_pricings.messages.lines_required'),
            'lines.array' => __('inventory.opening_stock_pricings.messages.lines_required'),
            'exchange_rate.gt' => __('inventory.opening_stock_pricings.messages.exchange_rate_positive'),
            'lines.*.unit_price.numeric' => __('inventory.opening_stock_pricings.messages.price_gt_zero'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateBusiness($validator, $this->currentRecord());
        });
    }

    protected function validateBusiness(Validator $validator, ?OpeningStockPricing $current = null): void
    {
        if ($current?->isClosed()) {
            $validator->errors()->add('document', __('inventory.opening_stock_pricings.messages.closed_edit_forbidden'));
        }

        $companyId = (int) $this->input('company_id');
        $period = FinancialPeriod::query()->find($this->input('financial_period_id'));
        $branch = $this->branch($companyId);
        $hall = $this->branchHall($branch);
        $openingStock = $this->openingStock($companyId, $period, $branch, $hall);
        $currency = $this->currency($companyId);

        if (! $period instanceof FinancialPeriod || (int) $period->company_id !== $companyId) {
            $validator->errors()->add('financial_period_id', __('operating_context.validation.financial_period_invalid'));
        }

        if (! $branch instanceof Branch) {
            $validator->errors()->add('branch_doc_num', __('inventory.opening_stock_pricings.messages.branch_type_required'));
        }

        $this->validateDateInsidePeriod($validator, $period);
        $this->validateBranchHall($validator, $branch, $hall);
        $this->validateOpeningStock($validator, $openingStock, $current);
        $this->validateCurrency($validator, $currency);
        $this->validateLines($validator, $openingStock, $current);
    }

    protected function currentRecord(): ?OpeningStockPricing
    {
        return null;
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
            $validator->errors()->add('document_date', __('inventory.opening_stock_pricings.messages.date_outside_period'));
        }
    }

    private function validateBranchHall(Validator $validator, ?Branch $branch, ?BranchHall $hall): void
    {
        $uuid = trim((string) $this->input('branch_hall_uuid'));

        if ($uuid === '') {
            return;
        }

        if (! $branch instanceof Branch || $branch->type !== Branch::TypeFactory || ! $hall instanceof BranchHall) {
            $validator->errors()->add('branch_hall_uuid', __('inventory.opening_stock_pricings.messages.hall_invalid'));
        }
    }

    private function validateOpeningStock(Validator $validator, ?OpeningStock $openingStock, ?OpeningStockPricing $current): void
    {
        if (! $openingStock instanceof OpeningStock || $openingStock->lines()->whereNull('deleted_at')->count() === 0) {
            $validator->errors()->add('opening_stock_doc_num', __('inventory.opening_stock_pricings.messages.opening_stock_unavailable'));

            return;
        }

        if (app(OpeningStockPricingService::class)->isFullyPriced($openingStock, $current)) {
            $validator->errors()->add('opening_stock_doc_num', __('inventory.opening_stock_pricings.messages.opening_stock_fully_priced'));
        }
    }

    private function validateCurrency(Validator $validator, ?Currency $currency): void
    {
        if (! $currency instanceof Currency) {
            $validator->errors()->add('currency_doc_num', __('validation.exists', ['attribute' => __('inventory.opening_stock_pricings.attributes.currency')]));

            return;
        }

        $rate = $this->input('exchange_rate');

        if ($currency->is_main && is_numeric($rate) && abs(((float) $rate) - 1.0) > 0.000001) {
            $validator->errors()->add('exchange_rate', __('inventory.opening_stock_pricings.messages.exchange_rate_main_currency'));
        }
    }

    private function validateLines(Validator $validator, ?OpeningStock $openingStock, ?OpeningStockPricing $current): void
    {
        $activeOpeningLines = $openingStock instanceof OpeningStock
            ? $openingStock->lines()->whereNull('deleted_at')->get()->keyBy('public_id')
            : collect();
        $requiredLineIds = $activeOpeningLines->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $pricedElsewhere = app(OpeningStockPricingService::class)->pricedOpeningStockLineIds($requiredLineIds, $current?->getKey())->flip();
        $validLineCount = 0;
        $seenOpeningLines = [];
        $seenProducts = [];

        foreach ($this->input('lines', []) as $index => $line) {
            if (! is_array($line) || ($line['_delete'] ?? false)) {
                continue;
            }

            $linePublicId = trim((string) ($line['opening_stock_line_public_id'] ?? ''));
            $unitPrice = $line['unit_price'] ?? null;
            $notes = trim((string) ($line['notes'] ?? ''));

            if ($linePublicId === '' && ($unitPrice === null || $unitPrice === '') && $notes === '') {
                continue;
            }

            $validLineCount++;

            if ($linePublicId === '') {
                $validator->errors()->add("lines.{$index}.opening_stock_line_public_id", __('inventory.opening_stock_pricings.messages.product_required'));
            } else {
                $openingLine = $activeOpeningLines->get($linePublicId);

                if (! $openingLine instanceof OpeningStockLine) {
                    $validator->errors()->add("lines.{$index}.opening_stock_line_public_id", __('inventory.opening_stock_pricings.messages.opening_stock_unavailable'));
                } elseif ($pricedElsewhere->has((int) $openingLine->getKey())) {
                    $validator->errors()->add("lines.{$index}.opening_stock_line_public_id", __('inventory.opening_stock_pricings.messages.opening_stock_fully_priced'));
                } elseif (isset($seenOpeningLines[$openingLine->getKey()]) || isset($seenProducts[$openingLine->product_id])) {
                    $validator->errors()->add("lines.{$index}.opening_stock_line_public_id", __('inventory.opening_stock_pricings.messages.duplicate_product'));
                } else {
                    $seenOpeningLines[$openingLine->getKey()] = true;
                    $seenProducts[$openingLine->product_id] = true;
                }
            }

            if ($unitPrice === null || $unitPrice === '') {
                $validator->errors()->add("lines.{$index}.unit_price", __('inventory.opening_stock_pricings.messages.price_required'));
            } elseif (! is_numeric($unitPrice) || (float) $unitPrice <= 0) {
                $validator->errors()->add("lines.{$index}.unit_price", __('inventory.opening_stock_pricings.messages.price_gt_zero'));
            }
        }

        if ($validLineCount === 0) {
            $validator->errors()->add('lines', __('inventory.opening_stock_pricings.messages.lines_required'));
        }

        if ($openingStock instanceof OpeningStock && count($seenOpeningLines) < count($requiredLineIds)) {
            $validator->errors()->add('lines', __('inventory.opening_stock_pricings.messages.all_lines_required'));
        }
    }

    private function branch(int $companyId): ?Branch
    {
        return Branch::query()
            ->where('company_id', $companyId)
            ->where('doc_num', $this->input('branch_doc_num'))
            ->where('status', 'active')
            ->whereIn('type', [Branch::TypeWarehouse, Branch::TypeFactory])
            ->first();
    }

    private function branchHall(?Branch $branch): ?BranchHall
    {
        $uuid = trim((string) $this->input('branch_hall_uuid'));

        if (! $branch instanceof Branch || $uuid === '') {
            return null;
        }

        return BranchHall::query()
            ->where('branch_id', $branch->getKey())
            ->where('public_uuid', $uuid)
            ->whereNull('deleted_at')
            ->first();
    }

    private function openingStock(int $companyId, ?FinancialPeriod $period, ?Branch $branch, ?BranchHall $hall): ?OpeningStock
    {
        if (! $period instanceof FinancialPeriod || ! $branch instanceof Branch) {
            return null;
        }

        return OpeningStock::query()
            ->where('company_id', $companyId)
            ->where('financial_period_id', $period->getKey())
            ->where('branch_id', $branch->getKey())
            ->where('doc_num', $this->input('opening_stock_doc_num'))
            ->when($hall instanceof BranchHall, fn ($query) => $query->where('branch_hall_id', $hall->getKey()))
            ->whereNull('deleted_at')
            ->first();
    }

    private function currency(int $companyId): ?Currency
    {
        return Currency::query()
            ->active()
            ->forCompany($companyId)
            ->where('doc_num', $this->input('currency_doc_num'))
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
            'doc_number' => __('inventory.opening_stock_pricings.attributes.doc_number'),
            'document_date' => __('inventory.opening_stock_pricings.attributes.document_date'),
            'branch_doc_num' => __('inventory.opening_stock_pricings.attributes.branch'),
            'branch_hall_uuid' => __('inventory.opening_stock_pricings.attributes.hall'),
            'opening_stock_doc_num' => __('inventory.opening_stock_pricings.attributes.opening_stock'),
            'currency_doc_num' => __('inventory.opening_stock_pricings.attributes.currency'),
            'exchange_rate' => __('inventory.opening_stock_pricings.attributes.exchange_rate'),
            'notes' => __('inventory.opening_stock_pricings.attributes.notes'),
            'lines' => __('inventory.opening_stock_pricings.attributes.lines'),
            'lines.*.opening_stock_line_public_id' => __('inventory.opening_stock_pricings.attributes.product'),
            'lines.*.unit_price' => __('inventory.opening_stock_pricings.attributes.unit_price'),
            'lines.*.notes' => __('inventory.opening_stock_pricings.attributes.line_notes'),
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
                return trim((string) ($line['opening_stock_line_public_id'] ?? '')) !== ''
                    || trim((string) ($line['unit_price'] ?? '')) !== ''
                    || trim((string) ($line['notes'] ?? '')) !== '';
            })
            ->values()
            ->all();

        return $data;
    }
}
