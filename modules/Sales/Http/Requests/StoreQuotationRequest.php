<?php

namespace Modules\Sales\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Models\Currency;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\Quotation;
use Modules\Sales\Models\QuotationPaymentMilestone;
use Modules\Sales\Services\SalesUnitConversionService;
use Throwable;

class StoreQuotationRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->filled('clone_source_token') ? 'quotations.clone' : 'quotations.create');
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput([
            'exchange_rate',
            'discount_value',
            'lines.*.quantity',
            'lines.*.unit_price',
            'lines.*.discount_value',
            'lines.*.tax_rate',
            'payment_milestones.*.percentage',
            'payment_milestones.*.amount',
            'execution_schedule_lines.*.duration_days',
        ]);

        $context = app(OperatingContextService::class)->snapshot($this);

        $data = [
            'company_id' => $context['company_id'],
            'branch_id' => $context['branch_id'],
            'source_request_doc_num' => $this->nullableTrim('source_request_doc_num'),
            'customer_doc_num' => $this->nullableTrim('customer_doc_num'),
            'quotation_type' => $this->nullableTrim('quotation_type') ?: Quotation::TypeStandard,
            'project_name' => $this->nullableTrim('project_name'),
            'subject' => $this->nullableTrim('subject'),
            'quotation_date' => $this->nullableTrim('quotation_date'),
            'valid_until' => $this->nullableTrim('valid_until'),
            'currency_doc_num' => $this->nullableTrim('currency_doc_num'),
            'exchange_rate' => $this->decimalInput('exchange_rate') ?: '1',
            'sales_person_doc_num' => $this->nullableTrim('sales_person_doc_num'),
            'notes' => $this->nullableTrim('notes'),
            'revision_date' => $this->nullableTrim('revision_date') ?: $this->nullableTrim('quotation_date'),
            'change_reason' => $this->nullableTrim('change_reason'),
            'customer_feedback' => $this->nullableTrim('customer_feedback'),
            'discount_type' => $this->nullableTrim('discount_type'),
            'discount_value' => $this->decimalInput('discount_value') ?: '0',
            'terms' => $this->nullableHtml('terms'),
            'payment_terms' => $this->nullableHtml('payment_terms'),
            'execution_terms' => $this->nullableHtml('execution_terms'),
            'warranty_terms' => $this->nullableHtml('warranty_terms'),
            'delivery_terms' => $this->nullableHtml('delivery_terms'),
            'technical_notes' => $this->nullableHtml('technical_notes'),
            'customer_reference' => $this->nullableTrim('customer_reference'),
            'internal_notes' => $this->nullableTrim('internal_notes'),
            'lines' => $this->normalizedLines(),
            'payment_milestones' => $this->normalizedPaymentMilestones(),
            'execution_schedule_lines' => $this->normalizedExecutionScheduleLines(),
            'attachment_file_doc_nums' => $this->normalizedStringList('attachment_file_doc_nums'),
        ];

        if ($data['quotation_type'] !== Quotation::TypeProject) {
            $data['project_name'] = null;
            $data['execution_schedule_lines'] = [];
        }

        if (! $this->user()?->can('quotations.document_number.control')) {
            $data['doc_number'] = null;
        }

        $this->merge($data);
    }

    public function rules(): array
    {
        return [
            'doc_number' => ['nullable', 'integer', 'min:1', $this->uniqueActiveQuotationRule('doc_number')],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'source_request_doc_num' => ['nullable', 'string'],
            'customer_doc_num' => ['required', 'string'],
            'quotation_type' => ['required', Rule::in(Quotation::Types)],
            'project_name' => ['nullable', 'required_if:quotation_type,'.Quotation::TypeProject, 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'quotation_date' => ['required', $this->dateRule('quotation_date')],
            'valid_until' => ['nullable', $this->dateRule('valid_until')],
            'currency_doc_num' => ['required', 'string'],
            'exchange_rate' => ['required', 'numeric', 'decimal:0,6', 'regex:/^\d{1,12}(?:\.\d{1,6})?$/D', 'min:0.000001'],
            'sales_person_doc_num' => ['nullable', 'string', Rule::exists('hr_employees', 'doc_num')->where(fn ($query) => $query->where('company_id', app(OperatingContextService::class)->snapshot($this)['company_id']))],
            'notes' => ['nullable', 'string'],
            'revision_date' => ['required', $this->dateRule('revision_date')],
            'change_reason' => ['nullable', 'string'],
            'customer_feedback' => ['nullable', 'string'],
            'discount_type' => ['nullable', Rule::in(['fixed', 'percentage'])],
            'discount_value' => ['nullable', 'numeric', 'decimal:0,4', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/D', 'min:0'],
            'terms' => ['nullable', 'string'],
            'payment_terms' => ['nullable', 'string'],
            'execution_terms' => ['nullable', 'string'],
            'warranty_terms' => ['nullable', 'string'],
            'delivery_terms' => ['nullable', 'string'],
            'technical_notes' => ['nullable', 'string'],
            'customer_reference' => ['nullable', 'string', 'max:160'],
            'internal_notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_doc_num' => ['required', 'string'],
            'lines.*.source_request_line_public_id' => ['nullable', 'uuid'],
            'lines.*.description' => ['nullable', 'string'],
            'lines.*.unit_doc_num' => ['required', 'string'],
            'lines.*.quantity' => ['required', 'numeric', 'decimal:0,8', 'regex:/^\d{1,14}(?:\.\d{1,8})?$/D', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'decimal:0,4', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/D', 'min:0.0001'],
            'lines.*.discount_type' => ['nullable', Rule::in(['fixed', 'percentage'])],
            'lines.*.discount_value' => ['nullable', 'numeric', 'decimal:0,4', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/D', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'decimal:0,4', 'regex:/^\d{1,5}(?:\.\d{1,4})?$/D', 'min:0', 'max:100'],
            'lines.*.notes' => ['nullable', 'string'],
            'lines.*.requested_date' => ['nullable', $this->dateRule('requested_date')],
            'lines.*.specifications' => ['nullable', 'array'],
            'lines.*.specifications.packaging' => ['nullable', 'string'],
            'lines.*.specifications.customer_specification' => ['nullable', 'string'],
            'lines.*.warehouse_notes' => ['nullable', 'string'],
            'lines.*.production_notes' => ['nullable', 'string'],
            'lines.*._delete' => ['nullable', 'boolean'],
            'payment_milestones' => ['nullable', 'array'],
            'payment_milestones.*.title' => ['nullable', 'string', 'max:255'],
            'payment_milestones.*.description' => ['nullable', 'string'],
            'payment_milestones.*.percentage' => ['nullable', 'numeric', 'decimal:0,4', 'regex:/^\d{1,5}(?:\.\d{1,4})?$/D', 'min:0', 'max:100'],
            'payment_milestones.*.amount' => ['nullable', 'numeric', 'decimal:0,4', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/D', 'min:0'],
            'payment_milestones.*.due_type' => ['nullable', Rule::in(QuotationPaymentMilestone::DueTypes)],
            'payment_milestones.*.due_date' => ['nullable', $this->dateRule('due_date')],
            'payment_milestones.*.notes' => ['nullable', 'string'],
            'payment_milestones.*._delete' => ['nullable', 'boolean'],
            'execution_schedule_lines' => ['nullable', 'array'],
            'execution_schedule_lines.*.phase_name' => ['nullable', 'string', 'max:255'],
            'execution_schedule_lines.*.description' => ['nullable', 'string'],
            'execution_schedule_lines.*.start_date' => ['nullable', $this->dateRule('start_date')],
            'execution_schedule_lines.*.end_date' => ['nullable', $this->dateRule('end_date')],
            'execution_schedule_lines.*.duration_days' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'execution_schedule_lines.*.responsibility' => ['nullable', 'string', 'max:255'],
            'execution_schedule_lines.*.notes' => ['nullable', 'string'],
            'execution_schedule_lines.*._delete' => ['nullable', 'boolean'],
            'attachment_file_doc_nums' => ['nullable', 'array', 'max:20'],
            'attachment_file_doc_nums.*' => ['string'],
            'submit_action' => ['nullable', 'string'],
            'clone_source_token' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateBusiness($validator);
        });
    }

    public function attributes(): array
    {
        return [
            'doc_number' => __('quotations.attributes.doc_number'),
            'customer_doc_num' => __('quotations.attributes.customer'),
            'quotation_type' => __('quotations.attributes.quotation_type'),
            'project_name' => __('quotations.attributes.project_name'),
            'subject' => __('quotations.attributes.subject'),
            'quotation_date' => __('quotations.attributes.quotation_date'),
            'valid_until' => __('quotations.attributes.valid_until'),
            'currency_doc_num' => __('quotations.attributes.currency'),
            'exchange_rate' => __('quotations.attributes.exchange_rate'),
            'sales_person_doc_num' => __('quotations.attributes.sales_person'),
            'notes' => __('quotations.attributes.notes'),
            'revision_date' => __('quotations.attributes.revision_date'),
            'change_reason' => __('quotations.attributes.change_reason'),
            'customer_feedback' => __('quotations.attributes.customer_feedback'),
            'discount_type' => __('quotations.attributes.discount_type'),
            'discount_value' => __('quotations.attributes.discount_value'),
            'terms' => __('quotations.attributes.terms'),
            'payment_terms' => __('quotations.attributes.payment_terms'),
            'execution_terms' => __('quotations.attributes.execution_terms'),
            'warranty_terms' => __('quotations.attributes.warranty_terms'),
            'delivery_terms' => __('quotations.attributes.delivery_terms'),
            'technical_notes' => __('quotations.attributes.technical_notes'),
            'customer_reference' => __('quotations.attributes.customer_reference'),
            'internal_notes' => __('quotations.attributes.internal_notes'),
            'lines' => __('quotations.attributes.lines'),
            'lines.*.product_doc_num' => __('quotations.attributes.product'),
            'lines.*.description' => __('quotations.attributes.description'),
            'lines.*.unit_doc_num' => __('quotations.attributes.unit'),
            'lines.*.quantity' => __('quotations.attributes.quantity'),
            'lines.*.unit_price' => __('quotations.attributes.unit_price'),
            'lines.*.discount_type' => __('quotations.attributes.discount_type'),
            'lines.*.discount_value' => __('quotations.attributes.discount_value'),
            'lines.*.tax_rate' => __('quotations.attributes.tax_rate'),
            'lines.*.notes' => __('quotations.attributes.line_notes'),
            'lines.*.requested_date' => __('quotations.attributes.requested_date'),
            'lines.*.specifications.packaging' => __('quotations.attributes.packaging'),
            'lines.*.specifications.customer_specification' => __('quotations.attributes.customer_specification'),
            'lines.*.warehouse_notes' => __('quotations.attributes.warehouse_notes'),
            'lines.*.production_notes' => __('quotations.attributes.production_notes'),
            'payment_milestones.*.title' => __('quotations.attributes.milestone_title'),
            'execution_schedule_lines.*.phase_name' => __('quotations.attributes.phase_name'),
            'attachment_file_doc_nums' => __('quotations.attributes.attachments'),
        ];
    }

    public function messages(): array
    {
        return [
            'doc_number.unique' => __('quotations.messages.doc_number_unique'),
            'lines.required' => __('quotations.messages.lines_required'),
            'lines.array' => __('quotations.messages.lines_required'),
        ];
    }

    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);

        if ($key !== null || ! is_array($data)) {
            return $data;
        }

        foreach (['quotation_date', 'valid_until', 'revision_date'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $data[$field] = app(DateFormatService::class)->normalizeForStorage((string) $data[$field]);
            }
        }

        $data['lines'] = collect($data['lines'] ?? [])
            ->filter(fn (array $line): bool => ! ($line['_delete'] ?? false))
            ->filter(fn (array $line): bool => $this->lineHasContent($line))
            ->map(function (array $line): array {
                if (blank($line['discount_type'] ?? null)) {
                    $line['discount_value'] = '0';
                }
                if (($line['requested_date'] ?? null) !== null) {
                    $line['requested_date'] = app(DateFormatService::class)->normalizeForStorage((string) $line['requested_date']);
                }

                return $line;
            })
            ->values()
            ->all();

        if (blank($data['discount_type'] ?? null)) {
            $data['discount_value'] = '0';
        }

        $data['payment_milestones'] = collect($data['payment_milestones'] ?? [])
            ->filter(fn (array $row): bool => ! ($row['_delete'] ?? false))
            ->filter(fn (array $row): bool => $this->milestoneHasContent($row))
            ->map(function (array $row): array {
                if (($row['due_date'] ?? null) !== null) {
                    $row['due_date'] = app(DateFormatService::class)->normalizeForStorage((string) $row['due_date']);
                }

                return $row;
            })
            ->values()
            ->all();

        $data['execution_schedule_lines'] = collect($data['execution_schedule_lines'] ?? [])
            ->filter(fn (array $row): bool => ! ($row['_delete'] ?? false))
            ->filter(fn (array $row): bool => $this->scheduleHasContent($row))
            ->map(function (array $row): array {
                foreach (['start_date', 'end_date'] as $field) {
                    if (($row[$field] ?? null) !== null) {
                        $row[$field] = app(DateFormatService::class)->normalizeForStorage((string) $row[$field]);
                    }
                }

                return $row;
            })
            ->values()
            ->all();

        return $data;
    }

    protected function currentQuotation(): ?Quotation
    {
        return null;
    }

    protected function validateBusiness(Validator $validator): void
    {
        $quotation = $this->currentQuotation();

        if ($quotation instanceof Quotation) {
            $quotation->loadMissing('currentRevision');

            if (! $quotation->canEditCurrentRevision()) {
                $validator->errors()->add('document', __('quotations.messages.revision_not_draft'));
            }
        }

        $companyId = (int) $this->input('company_id');

        $this->validateCustomer($validator, $companyId);
        $this->validateCurrency($validator, $companyId);
        $this->validateDates($validator);
        $this->validateDiscountContracts($validator);
        $this->validateLines($validator, $companyId);
        $this->validateMilestones($validator);
        $this->validateExecutionSchedule($validator);
    }

    private function validateCustomer(Validator $validator, int $companyId): void
    {
        $docNum = trim((string) $this->input('customer_doc_num'));

        if ($docNum === '') {
            return;
        }

        if (! Customer::query()->forCompany($companyId)->where('doc_num', $docNum)->whereNull('deleted_at')->exists()) {
            $validator->errors()->add('customer_doc_num', __('validation.exists', ['attribute' => __('quotations.attributes.customer')]));
        }
    }

    private function validateCurrency(Validator $validator, int $companyId): void
    {
        $docNum = trim((string) $this->input('currency_doc_num'));

        if ($docNum === '') {
            return;
        }

        if (! Currency::query()->forCompany($companyId)->where('doc_num', $docNum)->whereNull('deleted_at')->exists()) {
            $validator->errors()->add('currency_doc_num', __('validation.exists', ['attribute' => __('quotations.attributes.currency')]));
        }
    }

    private function validateDates(Validator $validator): void
    {
        $quotationDate = $this->normalizedDate('quotation_date');
        $validUntil = $this->normalizedDate('valid_until');

        if ($quotationDate !== null && $validUntil !== null && $validUntil < $quotationDate) {
            $validator->errors()->add('valid_until', __('quotations.messages.valid_until_after_quotation_date'));
        }
    }

    private function validateLines(Validator $validator, int $companyId): void
    {
        $validLineCount = 0;

        foreach ($this->input('lines', []) as $index => $line) {
            if (! is_array($line) || ($line['_delete'] ?? false) || ! $this->lineHasContent($line)) {
                continue;
            }

            $validLineCount++;
            $productDocNum = trim((string) ($line['product_doc_num'] ?? ''));
            $unitDocNum = trim((string) ($line['unit_doc_num'] ?? ''));
            $quantity = $line['quantity'] ?? null;

            if ($productDocNum === '') {
                $validator->errors()->add("lines.{$index}.product_doc_num", __('validation.required', ['attribute' => __('quotations.attributes.product')]));
            }

            $product = $productDocNum === '' ? null : Product::withTrashed()
                ->forCompany($companyId)
                ->where('doc_num', $productDocNum)
                ->first();
            if ($product instanceof Product && $product->trashed()) {
                $validator->errors()->add("lines.{$index}.product_doc_num", __('quotations.messages.deleted_product_requires_replacement', [
                    'product' => $productDocNum,
                ]));
            } elseif ($productDocNum !== '' && (! $product instanceof Product || ! $product->isSalesEligible())) {
                $validator->errors()->add("lines.{$index}.product_doc_num", __('quotations.messages.product_sales_ineligible'));
            }

            $unit = $unitDocNum === '' ? null : ItemUnit::query()->active()->forCompany($companyId)->where('doc_num', $unitDocNum)->first();
            if ($unitDocNum !== '' && ! $unit instanceof ItemUnit) {
                $validator->errors()->add("lines.{$index}.unit_doc_num", __('validation.exists', ['attribute' => __('quotations.attributes.unit')]));
            }
            if ($unitDocNum === '') {
                $validator->errors()->add("lines.{$index}.unit_doc_num", __('validation.required', ['attribute' => __('quotations.attributes.unit')]));
            }

            if ($quantity === null || $quantity === '' || ! is_numeric($quantity) || bccomp((string) $quantity, '0', 4) <= 0) {
                $validator->errors()->add("lines.{$index}.quantity", __('quotations.messages.quantity_gt_zero'));
            }

            if ($product instanceof Product && $quantity !== null && $quantity !== '' && is_numeric($quantity)) {
                try {
                    app(SalesUnitConversionService::class)->snapshot($product, $unit?->getKey(), $quantity);
                } catch (Throwable $exception) {
                    $validator->errors()->add("lines.{$index}.unit_doc_num", $exception->getMessage());
                }
            }
        }

        if ($validLineCount === 0) {
            $validator->errors()->add('lines', __('quotations.messages.lines_required'));
        }
    }

    private function validateDiscountContracts(Validator $validator): void
    {
        $this->validateDiscount($validator, 'discount_value', $this->input('discount_type'), $this->input('discount_value'));

        foreach ($this->input('lines', []) as $index => $line) {
            if (! is_array($line) || ($line['_delete'] ?? false) || ! $this->lineHasContent($line)) {
                continue;
            }

            $this->validateDiscount(
                $validator,
                "lines.{$index}.discount_value",
                $line['discount_type'] ?? null,
                $line['discount_value'] ?? null,
            );
        }
    }

    private function validateDiscount(Validator $validator, string $valueField, mixed $type, mixed $value): void
    {
        $numericValue = is_numeric($value) ? (float) $value : 0.0;
        if (blank($type) && $numericValue > 0) {
            $validator->errors()->add($valueField, __('sales_ui.discount_type_required'));
        }
        if ($type === 'percentage' && $numericValue > 100) {
            $validator->errors()->add($valueField, __('sales_ui.discount_percentage_max'));
        }
    }

    private function validateMilestones(Validator $validator): void
    {
        foreach ($this->input('payment_milestones', []) as $index => $row) {
            if (! is_array($row) || ($row['_delete'] ?? false) || ! $this->milestoneHasContent($row)) {
                continue;
            }

            if (trim((string) ($row['title'] ?? '')) === '') {
                $validator->errors()->add("payment_milestones.{$index}.title", __('validation.required', ['attribute' => __('quotations.attributes.milestone_title')]));
            }

            if (($row['due_type'] ?? null) === QuotationPaymentMilestone::DueCustomDate && trim((string) ($row['due_date'] ?? '')) === '') {
                $validator->errors()->add("payment_milestones.{$index}.due_date", __('validation.required', ['attribute' => __('quotations.attributes.due_date')]));
            }
        }
    }

    private function validateExecutionSchedule(Validator $validator): void
    {
        foreach ($this->input('execution_schedule_lines', []) as $index => $row) {
            if (! is_array($row) || ($row['_delete'] ?? false) || ! $this->scheduleHasContent($row)) {
                continue;
            }

            if (trim((string) ($row['phase_name'] ?? '')) === '') {
                $validator->errors()->add("execution_schedule_lines.{$index}.phase_name", __('validation.required', ['attribute' => __('quotations.attributes.phase_name')]));
            }

            $start = $this->normalizedNestedDate($row, 'start_date');
            $end = $this->normalizedNestedDate($row, 'end_date');

            if ($start !== null && $end !== null && $end < $start) {
                $validator->errors()->add("execution_schedule_lines.{$index}.end_date", __('quotations.messages.end_date_after_start'));
            }
        }
    }

    private function uniqueActiveQuotationRule(string $column): Unique
    {
        $rule = Rule::unique('quotations', $column)
            ->where(fn ($query) => $query
                ->where('company_id', $this->input('company_id'))
                ->whereNull('deleted_at'));
        $current = $this->currentQuotation();

        return $current ? $rule->ignore($current->getKey()) : $rule;
    }

    private function dateRule(string $field): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($field): void {
            if (! app(DateFormatService::class)->isValidDate(is_string($value) ? $value : null)) {
                $fail(__('quotations.messages.date_invalid', ['attribute' => __("quotations.attributes.{$field}")]));
            }
        };
    }

    private function normalizedDate(string $field): ?string
    {
        $value = $this->input($field);

        if (! is_string($value) || ! app(DateFormatService::class)->isValidDate($value)) {
            return null;
        }

        return app(DateFormatService::class)->normalizeForStorage($value);
    }

    private function normalizedNestedDate(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;

        if (! is_string($value) || ! app(DateFormatService::class)->isValidDate($value)) {
            return null;
        }

        return app(DateFormatService::class)->normalizeForStorage($value);
    }

    private function nullableTrim(string $field): ?string
    {
        $value = trim((string) $this->input($field));

        return $value === '' ? null : $value;
    }

    private function nullableHtml(string $field): ?string
    {
        $value = trim((string) $this->input($field));

        return $value === '' ? null : $value;
    }

    private function decimalInput(string $field): ?string
    {
        $value = trim((string) $this->input($field));

        return $value === '' ? null : $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizedLines(): array
    {
        return collect($this->input('lines', []))
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): array => [
                'product_doc_num' => isset($row['product_doc_num']) ? trim((string) $row['product_doc_num']) ?: null : null,
                'source_request_line_public_id' => isset($row['source_request_line_public_id']) ? trim((string) $row['source_request_line_public_id']) ?: null : null,
                'description' => isset($row['description']) ? trim((string) $row['description']) ?: null : null,
                'unit_doc_num' => isset($row['unit_doc_num']) ? trim((string) $row['unit_doc_num']) ?: null : null,
                'quantity' => isset($row['quantity']) ? trim((string) $row['quantity']) ?: null : null,
                'unit_price' => isset($row['unit_price']) ? trim((string) $row['unit_price']) ?: null : null,
                'discount_type' => isset($row['discount_type']) ? trim((string) $row['discount_type']) ?: null : null,
                'discount_value' => isset($row['discount_value']) ? trim((string) $row['discount_value']) ?: '0' : '0',
                'tax_rate' => isset($row['tax_rate']) ? trim((string) $row['tax_rate']) ?: '0' : '0',
                'notes' => isset($row['notes']) ? trim((string) $row['notes']) ?: null : null,
                'requested_date' => isset($row['requested_date']) ? trim((string) $row['requested_date']) ?: null : null,
                'specifications' => [
                    'packaging' => isset($row['specifications']['packaging']) ? trim((string) $row['specifications']['packaging']) ?: null : null,
                    'customer_specification' => isset($row['specifications']['customer_specification']) ? trim((string) $row['specifications']['customer_specification']) ?: null : null,
                ],
                'warehouse_notes' => isset($row['warehouse_notes']) ? trim((string) $row['warehouse_notes']) ?: null : null,
                'production_notes' => isset($row['production_notes']) ? trim((string) $row['production_notes']) ?: null : null,
                '_delete' => filter_var($row['_delete'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizedPaymentMilestones(): array
    {
        return collect($this->input('payment_milestones', []))
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): array => [
                'title' => isset($row['title']) ? trim((string) $row['title']) ?: null : null,
                'description' => isset($row['description']) ? trim((string) $row['description']) ?: null : null,
                'percentage' => isset($row['percentage']) ? trim((string) $row['percentage']) ?: null : null,
                'amount' => isset($row['amount']) ? trim((string) $row['amount']) ?: null : null,
                'due_type' => isset($row['due_type']) ? trim((string) $row['due_type']) ?: null : null,
                'due_date' => isset($row['due_date']) ? trim((string) $row['due_date']) ?: null : null,
                'notes' => isset($row['notes']) ? trim((string) $row['notes']) ?: null : null,
                '_delete' => filter_var($row['_delete'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizedExecutionScheduleLines(): array
    {
        return collect($this->input('execution_schedule_lines', []))
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): array => [
                'phase_name' => isset($row['phase_name']) ? trim((string) $row['phase_name']) ?: null : null,
                'description' => isset($row['description']) ? trim((string) $row['description']) ?: null : null,
                'start_date' => isset($row['start_date']) ? trim((string) $row['start_date']) ?: null : null,
                'end_date' => isset($row['end_date']) ? trim((string) $row['end_date']) ?: null : null,
                'duration_days' => isset($row['duration_days']) ? trim((string) $row['duration_days']) ?: null : null,
                'responsibility' => isset($row['responsibility']) ? trim((string) $row['responsibility']) ?: null : null,
                'notes' => isset($row['notes']) ? trim((string) $row['notes']) ?: null : null,
                '_delete' => filter_var($row['_delete'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function normalizedStringList(string $field): array
    {
        return collect($this->input($field, []))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function lineHasContent(array $line): bool
    {
        foreach (['product_doc_num', 'description', 'unit_doc_num', 'quantity', 'unit_price', 'notes'] as $field) {
            if (trim((string) ($line[$field] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function milestoneHasContent(array $row): bool
    {
        foreach (['title', 'description', 'percentage', 'amount', 'due_type', 'due_date', 'notes'] as $field) {
            if (trim((string) ($row[$field] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function scheduleHasContent(array $row): bool
    {
        foreach (['phase_name', 'description', 'start_date', 'end_date', 'duration_days', 'responsibility', 'notes'] as $field) {
            if (trim((string) ($row[$field] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }
}
