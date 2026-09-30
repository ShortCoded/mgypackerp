<?php

namespace Modules\Sales\Services;

use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Currency;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\OperatingContextService;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\Quotation;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesRequest;
use Modules\Sales\Models\SalesRequestLine;

class SalesRequestService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly FinancialPeriodService $periods,
        private readonly SalesUnitConversionService $units,
        private readonly SalesCycleAuditService $audit,
        private readonly PriceListPricingService $priceLists,
        private readonly OperatingContextService $operatingContext,
    ) {}

    /** @param array<string, mixed> $data */
    public function save(array $data, ?SalesRequest $request = null): SalesRequest
    {
        return DB::transaction(function () use ($data, $request): SalesRequest {
            $record = $request ? SalesRequest::query()->lockForUpdate()->findOrFail($request->id) : new SalesRequest;
            if ($record->exists && ! $record->isEditable()) {
                throw new DomainException(__('Only draft, rejected, or reopened sales requests can be edited.'));
            }
            $companyId = $record->company_id ?? (int) $data['company_id'];
            if ($record->exists) {
                $this->periods->resolveOpenForPostingDate($companyId, $record->request_date, (int) $record->financial_period_id, lockForUpdate: true);
            }
            $period = $this->periods->resolveOpenForPostingDate($companyId, $data['request_date'], lockForUpdate: true);
            $values = collect($data)->only(['branch_id', 'branch_store_id', 'customer_id', 'currency_id', 'business_employee_id', 'request_date', 'required_delivery_date', 'priority', 'customer_reference', 'exchange_rate', 'notes'])->all();
            if (! empty($values['customer_id'])) {
                Customer::query()->forCompany($companyId)->active()->findOrFail($values['customer_id']);
            }
            if (! empty($values['currency_id'])) {
                Currency::query()->where('company_id', $companyId)->findOrFail($values['currency_id']);
            }
            if (! empty($values['branch_store_id'])) {
                BranchStore::query()->where('branch_id', $values['branch_id'])->findOrFail($values['branch_store_id']);
            }
            if (empty($data['lines'])) {
                throw new DomainException(__('A sales request requires at least one line.'));
            }
            $lines = [];
            foreach ($data['lines'] as $index => $input) {
                $product = Product::query()->forCompany($companyId)->active()->findOrFail($input['product_id']);
                if (! $product->isSalesEligible() || bccomp((string) $input['quantity'], '0', 8) <= 0) {
                    throw new DomainException(__('Choose a saleable item and a positive requested quantity.'));
                }
                $lines[] = [...collect($input)->only(['product_id', 'description', 'quantity', 'specifications', 'notes'])->all(),
                    'unit_price' => null,
                    ...collect($this->units->snapshot($product, $input['unit_id'] ?? null, $input['quantity']))->except('base_unit_id')->all(), 'line_number' => $index + 1];
            }
            $existingLines = $record->exists ? $record->lines()->get() : collect();
            $reopenRevision = $record->status === SalesRequest::StatusReopened ? $this->latestReopenRevision($record) : null;
            $record->fill([...$values, 'company_id' => $companyId, 'financial_period_id' => $period->id]);
            $sameLines = $existingLines->count() === count($lines) && $existingLines->values()->every(function (SalesRequestLine $line, int $index) use ($lines): bool {
                return ! (clone $line)->fill($lines[$index])->isDirty();
            });
            if ($record->exists && ! $record->isDirty() && $sameLines) {
                return $record->load('lines.product', 'lines.unit');
            }
            if (! $record->exists) {
                $record->fill([...$this->documents->nextForCompany('sales_requests', SalesRequest::class, $companyId), 'created_by' => auth()->id()]);
            } else {
                $record->updated_by = auth()->id();
            }
            $record->save();
            if (! $sameLines) {
                $record->lines()->delete();
                $record->lines()->createMany($lines);
            }
            if ($reopenRevision !== null) {
                $record->refresh()->load('lines');
                $afterSnapshot = $this->snapshot($record, $record->lines);
                $history = [
                    ...($record->status_history ?? []),
                    [
                        'event' => 'amended',
                        'from' => SalesRequest::StatusReopened,
                        'to' => SalesRequest::StatusReopened,
                        'at' => now()->toIso8601String(),
                        'by' => auth()->id(),
                        'reopen_revision_id' => $reopenRevision['id'],
                        'before_snapshot' => $reopenRevision['approved_snapshot'],
                        'after_snapshot' => $afterSnapshot,
                    ],
                ];
                $record->forceFill(['status_history' => $history])->save();
                $this->audit->record($record, 'sales_request.amended', [
                    'reopen_revision_id' => $reopenRevision['id'],
                    'before_snapshot' => $reopenRevision['approved_snapshot'],
                    'after_snapshot' => $afterSnapshot,
                ]);
            } else {
                $this->audit->record($record, 'sales_request.saved');
            }

            return $record->refresh()->load('lines.product', 'lines.unit');
        });
    }

    public function delete(SalesRequest $request): void
    {
        DB::transaction(function () use ($request): void {
            $record = SalesRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($record->status !== 'draft' || $record->quotations()->withTrashed()->exists() || $record->orders()->withTrashed()->exists()) {
                throw new DomainException(__('Only unused drafts can be deleted.'));
            }
            $record->delete();
            $this->audit->record($record, 'sales_request.deleted');
        });
    }

    public function restore(SalesRequest $request): SalesRequest
    {
        return DB::transaction(function () use ($request): SalesRequest {
            $record = SalesRequest::onlyTrashed()->lockForUpdate()->findOrFail($request->id);
            if ($record->status !== 'draft') {
                throw new DomainException(__('Only unused drafts can be restored.'));
            }
            $record->restore();
            $this->audit->record($record, 'sales_request.restored');

            return $record;
        });
    }

    public function transition(SalesRequest $request, string $status, ?string $reason = null): SalesRequest
    {
        return DB::transaction(function () use ($request, $status, $reason): SalesRequest {
            $record = SalesRequest::query()->lockForUpdate()->findOrFail($request->id);
            $this->periods->resolveOpenForPostingDate((int) $record->company_id, $record->request_date, (int) $record->financial_period_id, lockForUpdate: true);
            $allowed = ['submitted' => ['draft', 'rejected', 'reopened'], 'approved' => ['submitted'], 'rejected' => ['submitted'], 'cancelled' => ['draft', 'submitted', 'approved', 'rejected', 'reopened'], 'closed' => ['approved', 'partially_converted', 'converted']];
            if (! in_array($record->status, $allowed[$status] ?? [], true)) {
                throw new DomainException(__('This sales request status transition is not allowed.'));
            }
            if (in_array($status, ['rejected', 'cancelled', 'closed'], true) && blank($reason)) {
                throw new DomainException(__('A reason is required for this action.'));
            }
            if ($status === 'cancelled' && ($record->approved_at !== null || $record->closed_at !== null
                || $record->quotations()->withTrashed()->exists() || $record->orders()->withTrashed()->exists()
                || CustomerInvoice::query()->withTrashed()->where('source_type', 'sales_request')->where('source_id', $record->getKey())->exists())) {
                throw new DomainException(__('A sales request with conversions or downstream documents cannot be cancelled.'));
            }
            $record->update(['status' => $status, $status.'_by' => auth()->id(), $status.'_at' => now(), 'status_reason' => $reason,
                'status_history' => [...($record->status_history ?? []), ['from' => $record->status, 'to' => $status, 'at' => now()->toIso8601String(), 'by' => auth()->id(), 'reason' => $reason]]]);
            $this->audit->record($record, 'sales_request.'.$status);

            return $record->refresh();
        });
    }

    public function reopen(SalesRequest $request, string $reason): SalesRequest
    {
        return DB::transaction(function () use ($request, $reason): SalesRequest {
            $record = SalesRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            $activeRequest = request();
            if (! $activeRequest->hasSession()) {
                throw new DomainException(__('operating_context.messages.required'));
            }
            $context = $this->operatingContext->snapshot($activeRequest);
            if (! $context['company_id'] || ! $context['branch_id'] || ! $context['financial_period_id']
                || (int) $record->company_id !== (int) $context['company_id']
                || (int) $record->branch_id !== (int) $context['branch_id']
                || (int) $record->financial_period_id !== (int) $context['financial_period_id']) {
                throw new DomainException(__('The document is outside the active operating context.'));
            }
            $this->periods->resolveOpenForPostingDate((int) $record->company_id, $record->request_date, (int) $record->financial_period_id, lockForUpdate: true);
            if ($record->status !== SalesRequest::StatusApproved) {
                throw new DomainException(__('Only approved sales requests may be reopened.'));
            }
            if (blank($reason)) {
                throw new DomainException(__('A reason is required for this action.'));
            }
            if (! $record->canReopenSafely()) {
                throw new DomainException(__('A sales request with conversions or downstream documents cannot be reopened.'));
            }

            $reason = trim($reason);
            $revisionId = (string) Str::uuid();
            $approvedSnapshot = $this->snapshot($record, $record->lines()->get());
            $record->update([
                'status' => SalesRequest::StatusReopened,
                'status_reason' => $reason,
                'updated_by' => auth()->id(),
                'status_history' => [
                    ...($record->status_history ?? []),
                    [
                        'event' => 'reopened',
                        'from' => SalesRequest::StatusApproved,
                        'to' => SalesRequest::StatusReopened,
                        'at' => now()->toIso8601String(),
                        'by' => auth()->id(),
                        'reason' => $reason,
                        'reopen_revision_id' => $revisionId,
                        'approved_snapshot' => $approvedSnapshot,
                    ],
                ],
            ]);
            $this->audit->record($record, 'sales_request.reopened', [
                'reason' => $reason,
                'reopen_revision_id' => $revisionId,
                'approved_snapshot' => $approvedSnapshot,
            ]);

            return $record->refresh();
        });
    }

    /** @return array{id: string, approved_snapshot: array<string, mixed>}|null */
    private function latestReopenRevision(SalesRequest $request): ?array
    {
        $event = collect($request->status_history ?? [])
            ->reverse()
            ->first(fn (mixed $event): bool => is_array($event)
                && ($event['event'] ?? null) === 'reopened'
                && filled($event['reopen_revision_id'] ?? null)
                && is_array($event['approved_snapshot'] ?? null));

        if (! is_array($event)) {
            return null;
        }

        return [
            'id' => (string) $event['reopen_revision_id'],
            'approved_snapshot' => $event['approved_snapshot'],
        ];
    }

    /**
     * @param  Collection<int, SalesRequestLine>  $lines
     * @return array<string, mixed>
     */
    private function snapshot(SalesRequest $request, Collection $lines): array
    {
        return [
            'header' => [
                'doc_num' => $request->doc_num,
                'company_id' => $request->company_id,
                'financial_period_id' => $request->financial_period_id,
                'branch_id' => $request->branch_id,
                'branch_store_id' => $request->branch_store_id,
                'customer_id' => $request->customer_id,
                'currency_id' => $request->currency_id,
                'business_employee_id' => $request->business_employee_id,
                'request_date' => $request->request_date?->toDateString(),
                'required_delivery_date' => $request->required_delivery_date?->toDateString(),
                'priority' => $request->priority,
                'customer_reference' => $request->customer_reference,
                'exchange_rate' => $request->exchange_rate,
                'notes' => $request->notes,
                'status' => $request->status,
            ],
            'lines' => $lines->sortBy('line_number')->values()->map(fn (SalesRequestLine $line): array => [
                'public_id' => $line->public_id,
                'line_number' => $line->line_number,
                'product_id' => $line->product_id,
                'unit_id' => $line->unit_id,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'conversion_factor' => $line->conversion_factor,
                'base_quantity' => $line->base_quantity,
                'converted_quantity' => $line->converted_quantity,
                'specifications' => $line->specifications,
                'notes' => $line->notes,
            ])->all(),
        ];
    }

    /** @param array<string, mixed> $data */
    public function convertToOrder(SalesRequest $request, array $data): SalesOrder
    {
        return DB::transaction(function () use ($request, $data): SalesOrder {
            $record = SalesRequest::query()->with(['lines.product', 'lines.unit'])->lockForUpdate()->findOrFail($request->getKey());
            if (! in_array($record->status, ['approved', 'partially_converted'], true) || ! $record->customer_id || ! $record->currency_id) {
                throw new DomainException(__('Conversion requires an approved request with a customer and currency.'));
            }

            $preparedLines = [];
            foreach ($data['lines'] ?? [] as $input) {
                $sourceLine = $record->lines->firstWhere('public_id', $input['source_request_line_public_id'] ?? null);
                if (! $sourceLine
                    || (int) $sourceLine->product_id !== (int) ($input['product_id'] ?? 0)
                    || (int) $sourceLine->unit_id !== (int) ($input['unit_id'] ?? 0)) {
                    throw new DomainException(__('Each order line must keep its selected sales request product and unit.'));
                }

                $quantity = (string) ($input['quantity'] ?? '0');
                if (bccomp($quantity, '0', 8) <= 0 || bccomp($quantity, $sourceLine->remainingQuantity(), 8) > 0) {
                    throw new DomainException(__('Converted quantity exceeds the remaining request quantity.'));
                }
                $preparedLines[] = [
                    ...collect($input)->except('source_request_line_public_id')->all(),
                    'sales_request_line_id' => $sourceLine->getKey(),
                ];
            }
            if ($preparedLines === []) {
                throw new DomainException(__('Select at least one sales request line.'));
            }

            $period = $this->periods->resolveOpenForPostingDate(
                (int) $record->company_id,
                (string) $data['order_date'],
                isset($data['financial_period_id']) ? (int) $data['financial_period_id'] : null,
                lockForUpdate: true,
            );
            $preparedLines = $this->priceLists->applyToLines(
                $preparedLines,
                (int) $record->company_id,
                (int) $record->customer_id,
                (int) $record->currency_id,
                (string) $data['order_date'],
                'amount',
                lockForUpdate: true,
            );

            $order = app(SalesOrderService::class)->create([
                ...collect($data)->except(['source_request_doc_num', 'lines', 'customer_id', 'currency_id', 'branch_store_id', 'business_employee_id'])->all(),
                'customer_id' => $record->customer_id,
                'currency_id' => $record->currency_id,
                'exchange_rate' => $record->exchange_rate,
                'financial_period_id' => $period->getKey(),
                'branch_store_id' => null,
                'business_employee_id' => $record->business_employee_id ?: ($data['business_employee_id'] ?? null),
                'sales_request_id' => $record->getKey(),
                'lines' => $preparedLines,
            ]);

            foreach ($preparedLines as $line) {
                SalesRequestLine::query()->lockForUpdate()->findOrFail($line['sales_request_line_id'])->increment('converted_quantity', $line['quantity']);
            }
            $record->update(['status' => $record->lines()->whereColumn('converted_quantity', '<', 'quantity')->exists() ? 'partially_converted' : 'converted']);
            $this->audit->record($record, 'sales_request.converted', ['target' => 'order', 'document' => $order->doc_num]);

            return $order;
        });
    }

    /** @param array<string, mixed> $data */
    public function convertToQuotation(SalesRequest $request, array $data): Quotation
    {
        return DB::transaction(function () use ($request, $data): Quotation {
            $record = SalesRequest::query()->with(['customer', 'currency', 'lines.product', 'lines.unit'])->lockForUpdate()->findOrFail($request->getKey());
            if (! in_array($record->status, ['approved', 'partially_converted'], true) || ! $record->customer_id || ! $record->currency_id) {
                throw new DomainException(__('Conversion requires an approved request with a customer and currency.'));
            }

            $preparedLines = [];
            $sourceLineIds = [];
            foreach ($data['lines'] ?? [] as $input) {
                $sourceLine = $record->lines->firstWhere('public_id', $input['source_request_line_public_id'] ?? null);
                if (! $sourceLine || in_array($sourceLine->getKey(), $sourceLineIds, true)
                    || $sourceLine->product?->doc_num !== ($input['product_doc_num'] ?? null)
                    || $sourceLine->unit?->doc_num !== ($input['unit_doc_num'] ?? null)) {
                    throw new DomainException(__('Each quotation line must keep its selected sales request product and unit.'));
                }

                $quantity = (string) ($input['quantity'] ?? '0');
                if (bccomp($quantity, '0', 8) <= 0 || bccomp($quantity, $sourceLine->remainingQuantity(), 8) > 0) {
                    throw new DomainException(__('Converted quantity exceeds the remaining request quantity.'));
                }
                $sourceLineIds[] = $sourceLine->getKey();
                $preparedLines[] = [
                    ...collect($input)->except('source_request_line_public_id')->all(),
                    'sales_request_line_id' => $sourceLine->getKey(),
                ];
            }
            if ($preparedLines === []) {
                throw new DomainException(__('Select at least one sales request line.'));
            }

            $this->periods->resolveOpenForPostingDate((int) $record->company_id, (string) $data['quotation_date'], lockForUpdate: true);
            $preparedLines = $this->priceLists->applyToLines(
                $preparedLines,
                (int) $record->company_id,
                (int) $record->customer_id,
                (int) $record->currency_id,
                (string) $data['quotation_date'],
                'quotation',
                lockForUpdate: true,
            );

            $quotation = app(QuotationService::class)->create([
                ...collect($data)->except(['source_request_doc_num', 'sales_person_doc_num', 'customer_doc_num', 'currency_doc_num', 'lines'])->all(),
                'sales_request_id' => $record->getKey(),
                'business_employee_id' => $record->business_employee_id,
                'customer_doc_num' => $record->customer->doc_num,
                'currency_doc_num' => $record->currency->doc_num,
                'exchange_rate' => $record->exchange_rate,
                'lines' => $preparedLines,
            ])['record'];

            foreach ($preparedLines as $line) {
                SalesRequestLine::query()->lockForUpdate()->findOrFail($line['sales_request_line_id'])->increment('converted_quantity', $line['quantity']);
            }
            $record->update(['status' => $record->lines()->whereColumn('converted_quantity', '<', 'quantity')->exists() ? 'partially_converted' : 'converted']);
            $this->audit->record($record, 'sales_request.converted', ['target' => 'quotation', 'document' => $quotation->doc_num]);

            return $quotation;
        });
    }

    /** @param list<array{public_id: string, quantity: string}> $selection */
    public function convert(SalesRequest $request, string $target, array $selection, array $conversionContext = []): Quotation|SalesOrder
    {
        return DB::transaction(function () use ($request, $target, $selection, $conversionContext): Quotation|SalesOrder {
            $record = SalesRequest::query()->with(['customer', 'currency', 'lines.product', 'lines.unit'])->lockForUpdate()->findOrFail($request->id);
            if ($record->status === 'approved') {
                if (! $record->customer_id && ! empty($conversionContext['customer_doc_num'])) {
                    $record->customer_id = Customer::query()->forCompany($record->company_id)->active()->where('doc_num', $conversionContext['customer_doc_num'])->valueOrFail('id');
                }
                if (! $record->currency_id && ! empty($conversionContext['currency_doc_num'])) {
                    $record->currency_id = Currency::query()->forCompany($record->company_id)->active()->where('doc_num', $conversionContext['currency_doc_num'])->valueOrFail('id');
                    $record->exchange_rate = $conversionContext['exchange_rate'] ?? '1';
                }
                if (! $record->branch_store_id && ! empty($conversionContext['branch_store_uuid'])) {
                    $record->branch_store_id = BranchStore::query()->where('branch_id', $record->branch_id)->where('public_uuid', $conversionContext['branch_store_uuid'])->valueOrFail('id');
                }
                if ($record->isDirty()) {
                    $record->updated_by = auth()->id();
                    $record->save();
                    $record->load('customer', 'currency');
                    $this->audit->record($record, 'sales_request.customer_assigned_for_conversion');
                }
            }
            if (! in_array($record->status, ['approved', 'partially_converted'], true) || ! $record->customer_id || ! $record->currency_id) {
                throw new DomainException(__('Conversion requires an approved request with a customer and currency.'));
            }
            if ($selection === [] || count(array_unique(array_column($selection, 'public_id'))) !== count($selection)) {
                throw new DomainException(__('Select each source line once.'));
            }
            $period = $this->periods->resolveOpenForPostingDate((int) $record->company_id, now()->toDateString(), lockForUpdate: true);
            $lines = [];
            foreach ($selection as $input) {
                $line = $record->lines->firstWhere('public_id', $input['public_id']);
                if (! $line || bccomp((string) $input['quantity'], '0', 8) <= 0 || bccomp((string) $input['quantity'], $line->remainingQuantity(), 8) > 0) {
                    throw new DomainException(__('Converted quantity exceeds the remaining request quantity.'));
                }
                $lines[] = ['sales_request_line_id' => $line->id, 'product_id' => $line->product_id, 'product_doc_num' => $line->product->doc_num,
                    'unit_id' => $line->unit_id, 'unit_doc_num' => $line->unit->doc_num, 'description' => $line->description ?: $line->product->name,
                    'quantity' => $input['quantity'], 'specifications' => $line->specifications, 'notes' => $line->notes];
                $line->increment('converted_quantity', $input['quantity']);
            }
            $lines = $this->priceLists->applyToLines(
                $lines,
                (int) $record->company_id,
                (int) $record->customer_id,
                (int) $record->currency_id,
                now()->toDateString(),
                $target === 'quotation' ? 'quotation' : 'amount',
                lockForUpdate: true,
            );
            if ($target === 'quotation') {
                $document = app(QuotationService::class)->create(['branch_id' => $record->branch_id, 'sales_request_id' => $record->id, 'business_employee_id' => $record->business_employee_id,
                    'customer_doc_num' => $record->customer->doc_num, 'currency_doc_num' => $record->currency->doc_num,
                    'quotation_date' => now()->toDateString(), 'valid_until' => now()->addMonth()->toDateString(), 'exchange_rate' => $record->exchange_rate,
                    'customer_reference' => $record->customer_reference, 'notes' => $record->notes, 'lines' => $lines])['record'];
            } elseif ($target === 'order') {
                $document = app(SalesOrderService::class)->create(['company_id' => $record->company_id, 'financial_period_id' => $period->id,
                    'branch_id' => $record->branch_id, 'branch_store_id' => $record->branch_store_id, 'customer_id' => $record->customer_id,
                    'sales_request_id' => $record->id, 'business_employee_id' => $record->business_employee_id, 'currency_id' => $record->currency_id,
                    'order_date' => now()->toDateString(), 'expected_delivery_date' => $record->required_delivery_date?->toDateString() ?? now()->toDateString(),
                    'customer_reference' => $record->customer_reference, 'exchange_rate' => $record->exchange_rate, 'notes' => $record->notes,
                    'lines' => array_map(fn (array $line): array => collect($line)->except(['product_doc_num', 'unit_doc_num', 'notes'])->all(), $lines)]);
            } else {
                throw new DomainException(__('Choose quotation or sales order as the conversion target.'));
            }
            $record->update(['status' => $record->lines()->whereColumn('converted_quantity', '<', 'quantity')->exists() ? 'partially_converted' : 'converted']);
            $this->audit->record($record, 'sales_request.converted', ['target' => $target, 'document' => $document->doc_num]);

            return $document;
        });
    }
}
